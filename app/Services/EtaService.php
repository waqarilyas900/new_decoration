<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\OrderAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;

class EtaService
{
    public function calculateEtas(?string $calculationTime = null, ?int $employeeId = null): array
    {
        $calculationTime = $calculationTime ?? now()->toDateTimeString();

        $query = OrderAssignment::with(['employee', 'order'])
            ->whereHas('employee', fn($q) => $q->where('is_delete', 0))
            ->whereHas('order', fn($q) => $q->where('status', 0))
            ->where('is_complete', false);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $employeeAssignments = $query->get()->groupBy('employee_id');

        $cursorTimes = [];
        $orderEtaTracker = [];

        foreach ($employeeAssignments as $employeeId => $assignments) {
            $employee = $assignments->first()->employee;
            if (!$employee) continue;

            $startHour = Carbon::createFromFormat('H:i:s', $employee->working_hours_start);
            $endHour   = Carbon::createFromFormat('H:i:s', $employee->working_hours_end);

            $timePerGarmentStr = $employee->time_per_garment;

            if (substr_count($timePerGarmentStr, ':') === 1) {
                $timePerGarmentStr .= ':00';
            }
            $timePerGarment = CarbonInterval::createFromFormat('H:i:s', $timePerGarmentStr);

            $cursorTimes[$employeeId] = $this->normalizeStartTime(
                Carbon::parse($calculationTime),
                $startHour,
                $endHour
            );

            // Group assignments by order
            $groupedAssignments = $assignments->groupBy('order_id');

            // Sort orders by priority first (priority orders come first), then by creation date
            $sortedOrders = $groupedAssignments->sortBy(function ($orderAssignments, $orderId) {
                $order = $orderAssignments->first()->order;
                // Priority orders get 0, non-priority get 1, then sort by created_at
                return sprintf(
                    '%d-%d',
                    $order->is_priority ? 0 : 1,
                    $order->created_at->timestamp
                );
            });

            // Process orders sequentially - each order starts after the previous one completes
            foreach ($sortedOrders as $orderId => $orderAssignments) {
                $maxEtaForOrder = null;

                // Sort assignments within order by section priority
                $sortedAssignments = $orderAssignments->sortBy(function ($assignment) {
                    return $this->getSectionPriority($assignment->section);
                });

                foreach ($sortedAssignments as $assignment) {
                    // Start from current cursor time (which is after previous order completes)
                    $eta = $cursorTimes[$employeeId]->copy();
                    $totalSeconds = $timePerGarment->totalSeconds * $assignment->garments_assigned;

                    // Normalize start time to respect working hours
                    $eta = $this->normalizeStartTime($eta, $startHour, $endHour);
                    $secondsLeft = $totalSeconds;

                    while ($secondsLeft > 0) {
                        if ($this->isWeekend($eta)) {
                            $eta->addDay()->setTimeFrom($startHour);
                            continue;
                        }

                        $endOfDay  = $eta->copy()->setTimeFrom($endHour);
                        $available = $eta->diffInSeconds($endOfDay);
                        $consume   = min($available, $secondsLeft);

                        $eta->addSeconds($consume);
                        $secondsLeft -= $consume;

                        if ($secondsLeft > 0) {
                            $eta->addDay()->setTimeFrom($startHour);
                            while ($this->isWeekend($eta)) {
                                $eta->addDay()->setTimeFrom($startHour);
                            }
                        }
                    }

                    // Track the longest ETA within this order
                    if (!$maxEtaForOrder || $eta->greaterThan($maxEtaForOrder)) {
                        $maxEtaForOrder = $eta->copy();
                    }
                }

                // Set order final ETA
                if (!isset($orderEtaTracker[$orderId]) || $maxEtaForOrder->greaterThan($orderEtaTracker[$orderId])) {
                    $orderEtaTracker[$orderId] = $maxEtaForOrder->copy();
                }

                // Update cursor to start of next order (after this order completes)
                $cursorTimes[$employeeId] = $orderEtaTracker[$orderId]->copy();
            }
        }

        $overallEtaBreakdown = $this->computeOverallBreakdown($orderEtaTracker);

        $employeeFreeTimes = [];

        foreach ($employeeAssignments as $employeeId => $assignments) {
            $employee = $assignments->first()->employee;
            if (!$employee) continue;

            $latestEta = null;
            foreach ($assignments as $assignment) {
                $orderFinalEta = $orderEtaTracker[$assignment->order_id] ?? null;
                if ($orderFinalEta && (!$latestEta || $orderFinalEta->gt($latestEta))) {
                    $latestEta = $orderFinalEta;
                }
            }

            if ($latestEta) {
                $employeeFreeTimes[$employeeId] = [
                    'employee'     => $employee,
                    'free_at'      => $latestEta,
                    'personal_eta' => $latestEta,
                ];
            } else {
                $normalizedNow = $this->normalizeStartTime(
                    Carbon::parse($calculationTime),
                    $startHour,
                    $endHour
                );
                $employeeFreeTimes[$employeeId] = [
                    'employee'     => $employee,
                    'free_at'      => $normalizedNow,
                    'personal_eta' => $normalizedNow,
                ];
            }
        }

       
        $allEmployees = Employee::whereNotNull('department')
            ->where('active', 1)      
            ->where('is_delete', 0) 
            ->get();

        foreach ($allEmployees as $emp) {
            if (!isset($employeeFreeTimes[$emp->id])) {
                $startHour = Carbon::createFromFormat('H:i:s', $emp->working_hours_start);
                $endHour = Carbon::createFromFormat('H:i:s', $emp->working_hours_end);
                $normalizedNow = $this->normalizeStartTime(
                    Carbon::parse($calculationTime),
                    $startHour,
                    $endHour
                );
                
                $employeeFreeTimes[$emp->id] = [
                    'employee'     => $emp,
                    'free_at'      => $normalizedNow,
                    'personal_eta' => $normalizedNow,
                ];
            }
        }

        return [
            'orderEtas'           => $orderEtaTracker,
            'overallEtaBreakdown' => $overallEtaBreakdown,
            'employeeFreeTimes'   => $employeeFreeTimes,
        ];
    }

    protected function computeOverallBreakdown(array $orderEtaTracker): array
    {
        $maxEta = collect($orderEtaTracker)->max();

        if (!$maxEta) {
            return ['readable' => null, 'date' => null];
        }

        $now = Carbon::now();
        $diffInDays = $now->diffInDays($maxEta);
        $weeks = floor($diffInDays / 7);
        $days = $diffInDays % 7;

        $parts = [];
        if ($weeks > 0) $parts[] = $weeks . ' ' . \Illuminate\Support\Str::plural('Week', $weeks);
        if ($days > 0 || empty($parts)) $parts[] = $days . ' ' . \Illuminate\Support\Str::plural('Day', $days);

        $last = array_pop($parts);
        $readable = count($parts) ? implode(', ', $parts) . ' and ' . $last : $last;

        return [
            'readable' => $readable,
            'date' => $maxEta->format('M d, Y'),
        ];
    }

    protected function getSectionPriority($section)
    {
        return match ($section) {
            'Sewing' => 1,
            'Embroidery' => 2,
            'Imprinting' => 3,
            default => 99,
        };
    }

    private function normalizeStartTime($start, $startHour, $endHour)
    {
        
        $currentDateStartTime = $start->copy()->setTimeFrom($startHour);
        $currentDateEndTime = $start->copy()->setTimeFrom($endHour);

     
        if ($start->lt($currentDateStartTime)) {
            $start = $currentDateStartTime->copy();
        } 
      
        elseif ($start->gte($currentDateEndTime)) {
            $start = $currentDateStartTime->copy()->addDay();
            $start->setTimeFrom($startHour);
        }
        while ($this->isWeekend($start)) {
            $start->addDay()->setTimeFrom($startHour);
        }

        return $start;
    }

    private function isWeekend(Carbon $date)
    {
        return $date->isSaturday() || $date->isSunday();
    }

}
