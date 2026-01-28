<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Services\EtaService;
use Livewire\Component;

class BestEmployeeFinder extends Component
{
    public $quantity;
    public $selectedSections = [];
    public $bestEmployees = [];
    public string $title = 'ETA Generator';
    public $errorMessage = '';
    public $results = [];
    public $overallCompletion = '';

    protected EtaService $etaService;

    public function boot()
    {
        $this->etaService = app(EtaService::class);
    }

    public function render()
    {
        return view('livewire.best-employee-finder');
    }

    public function generate()
    {
        $this->errorMessage = '';
        $this->bestEmployees = [];
        $this->results = [];

        if (empty($this->quantity) || $this->quantity <= 0) {
            $this->errorMessage = 'Please enter a valid quantity to generate ETA.';
            return;
        }

        if (empty($this->selectedSections)) {
            $this->errorMessage = 'Please select at least one section (Sewing, Embroidery, or Imprinting).';
            return;
        }

        $calculationTime = now()->toDateTimeString();

        $this->results = $this->etaService->calculateEtas($calculationTime);
        $employeeFreeTimes = $this->results['employeeFreeTimes'] ?? [];

        if (empty($employeeFreeTimes)) {
            $this->errorMessage = 'No employees found with active assignments.';
            return;
        }

        $employeeOrderMap = [];
        foreach ($employeeFreeTimes as $employeeId => $data) {
            $employee = $data['employee'];
            
            $employeeResults = $this->etaService->calculateEtas($calculationTime, $employeeId);
            $employeeOrderEtas = $employeeResults['orderEtas'] ?? [];
            
            if (!empty($employeeOrderEtas)) {
                $employeeOrderMap[$employeeId] = collect($employeeOrderEtas)->max();
            } else {
                $employeeOrderMap[$employeeId] = $data['free_at'];
            }
        }

        foreach ($this->selectedSections as $section) {
            $best = collect($employeeFreeTimes)
                ->filter(fn ($data) => $data['employee']->department === $section)
                ->sortBy('free_at')
                ->first();

            if ($best) {
                $employee = $best['employee'];
                $employeeId = $employee->id;
                
                $previousEta = isset($employeeOrderMap[$employeeId]) 
                    ? $employeeOrderMap[$employeeId]->copy() 
                    : $best['free_at']->copy();
                
                $newEta = $previousEta->copy();

                $timePerGarmentStr = $employee->time_per_garment ?? '00:00:00';
                if (substr_count($timePerGarmentStr, ':') === 1) {
                    $timePerGarmentStr .= ':00';
                }
                $timeInterval = \Carbon\CarbonInterval::createFromFormat('H:i:s', $timePerGarmentStr);
                $totalSeconds = $timeInterval->totalSeconds * $this->quantity;

                $startHour = \Carbon\Carbon::createFromFormat('H:i:s', $employee->working_hours_start);
                $endHour = \Carbon\Carbon::createFromFormat('H:i:s', $employee->working_hours_end);

                $eta = $this->normalizeStartTime($newEta->copy(), $startHour, $endHour);
                $secondsLeft = $totalSeconds;
                
                while ($secondsLeft > 0) {
                    if ($eta->isSaturday() || $eta->isSunday()) {
                        $eta->addDay()->setTimeFrom($startHour);
                        continue;
                    }
                    $endOfDay = $eta->copy()->setTimeFrom($endHour);
                    $available = $eta->diffInSeconds($endOfDay);
                    $consume = min($available, $secondsLeft);
                    $eta->addSeconds($consume);
                    $secondsLeft -= $consume;
                    if ($secondsLeft > 0) {
                        $eta->addDay()->setTimeFrom($startHour);
                        while ($eta->isSaturday() || $eta->isSunday()) {
                            $eta->addDay()->setTimeFrom($startHour);
                        }
                    }
                }

                $this->bestEmployees[$section] = [
                    'employee'      => $employee->first_name . ' ' . $employee->last_name,
                    'previous_eta'  => $previousEta ? $previousEta->setTimezone(config('app.timezone'))->format('M d, Y H:i') : 'N/A',
                    'new_eta'       => $eta->setTimezone(config('app.timezone'))->format('M d, Y H:i'),
                ];
            }
        }

        if (!empty($this->bestEmployees)) {
            $latestEta = collect($this->bestEmployees)
                ->map(fn($info) => \Carbon\Carbon::parse($info['new_eta']))
                ->sortDesc()
                ->first();

            if ($latestEta) {
                $this->overallCompletion = $latestEta->setTimezone(config('app.timezone'))->format('M d, Y H:i');
            }
        }


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

    private function isWeekend(\Carbon\Carbon $date)
    {
        return $date->isSaturday() || $date->isSunday();
    }

}
