<?php

namespace App\Livewire\Order;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderAssignmentLog;
use App\Models\OrderLog;
use App\Models\OrderTrack;
use Livewire\Component;
use Livewire\Attributes\Validate;
use Illuminate\Support\Arr;
class OrderEditComponent extends Component
{
    #[Validate] 
    public $order_number = '';
    public $need_sewing;
    public $need_embroidery;
    public $need_imprinting;
    public $current_location;
    public $sewing_progress;
    public $imprinting_progress;
    public $embroidery_progress;
    public $created_by;
    public $getCreatedBy = [];
    public $orderId;
    protected $queryString = ['orderId'];
    public $order;
    public $confrmView;
    public $updated_by;
    public $removed_by;
    public $status;
    public $getReadyBy = [];
    public $getRemovedBy = [];
    public $employees = [];
    public $employeesCreated = [];
     public $external_employees = [];
    public $updateView = true;
    public $number_of_garments;
    public $is_priority;
    public $showSplitModal = false;
    public $splitSection = '';
    public $splitEntries = [];
    public $splitQuantitiesPattern = null;
    public $allSplitEntries = []; 
    public $manuallyOverriddenSections = [];
    public $assignments = [];
     public $pendingOrdersPerEmployee = [];
    public $notes = ''; 

    public function mount()
    {
        $this->employees  = Employee::where('type', 1)
        ->where('is_delete', 0)
        ->orderBy('first_name', 'asc')
        ->where('active', 1)->get();
         $this->external_employees = Employee::where('type', 2) 
        ->where('active', 1)
        ->where('is_delete', 0)
        ->orderBy('first_name', 'asc')
        ->get();

        $this->employeesCreated  = Employee::where('type', 1)
       
        ->orderBy('first_name', 'asc')
        ->where('active', 1)->get();

        $this->order = Order::with(['assignments', 'logs.user', 'assignmentLogs.employee'])->find($this->orderId);

        $this->allSplitEntries = [];

        foreach (['Sewing', 'Embroidery', 'Imprinting'] as $section) {
            $assignments = $this->order->assignments->where('section', $section);

            if ($assignments->isNotEmpty()) {
                $this->allSplitEntries[$section] = $assignments->map(function ($assignment) {
                    return [
                        'employee_id' => $assignment->employee_id,
                        'quantity' => $assignment->garments_assigned,  
                    ];
                })->toArray();
            }
        }
        $this->need_sewing = $this->order->need_sewing ? true : false;
        $this->need_embroidery = (int)$this->order->need_embroidery ? true : false;
        $this->need_imprinting = (int)$this->order->need_imprinting ? true : false;
        $this->current_location = $this->order->current_location;
        $this->order_number = $this->order->order_number;
        $this->created_by = $this->order->created_by;
        $this->number_of_garments = $this->order->number_of_garments;
        $this->is_priority = $this->order->is_priority;
        $this->notes = $this->order->notes ?? '';
        // dd($this->current_location);
    }
     public function getPendingOrdersCountPerEmployee()
    {
        return \App\Models\OrderAssignment::query()
            ->select('employee_id')
            ->selectRaw('COUNT(DISTINCT order_id) as pending_count')
            ->where('is_complete', 0)  // pending assignments only
            ->groupBy('employee_id')
            ->pluck('pending_count', 'employee_id')
            ->toArray();
    }
    public function render()
    {  
        return view('livewire.order.order-edit-component');
    }

    public function save()
    {
        if (!$this->need_sewing && !$this->need_embroidery && !$this->need_imprinting) {
            $this->current_location = null;
        }

        $order = Order::findOrFail($this->order->id);

        $validated = $this->validate([
            'updated_by' => 'required',
            'current_location' => 'required',
            'need_sewing' => 'nullable',
            'need_imprinting' => 'nullable',
            'need_embroidery' => 'nullable',
            'sewing_progress' => 'nullable',
            'imprinting_progress' => 'nullable',
            'embroidery_progress' => 'nullable',
            'is_priority' => 'nullable|boolean',
            'number_of_garments' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        unset($validated['updated_by']);

        if ($this->confrmView) {
            $requiredSections = collect([
                    'Sewing' => $this->need_sewing,
                    'Embroidery' => $this->need_embroidery,
                    'Imprinting' => $this->need_imprinting,
                ])->filter(fn($val) => (int)$val === 1); // only required ones

        foreach ($requiredSections as $section => $required) {
            $entries = $this->allSplitEntries[$section] ?? [];

            if (empty($entries)) {
                $this->addError('splitEntries', "Please assign employees for the {$section} section.");
                return;
            }

            foreach ($entries as $entry) {
                if (empty($entry['employee_id']) || empty($entry['quantity']) || (int)$entry['quantity'] <= 0) {
                    $this->addError('splitEntries', "Each {$section} assignment must have a valid employee and quantity.");
                    return;
                }
            }

            // Check for duplicate employees within the section
            $employeeIds = collect($entries)->pluck('employee_id');
            if ($employeeIds->count() !== $employeeIds->unique()->count()) {
                $this->addError('splitEntries', "Each employee in the {$section} section must be assigned only once.");
                return;
            }}
            $resetStatus = false;

            // Reset order status if any requirement has changed
            if (
                (int) $order->need_sewing !== (int) $this->need_sewing ||
                (int) $order->need_imprinting !== (int) $this->need_imprinting ||
                (int) $order->need_embroidery !== (int) $this->need_embroidery
            ) {
                $resetStatus = true;
            }

            // Reset section if newly required
            if ((int) $order->need_sewing !== 1 && (int) $this->need_sewing === 1) {
                $validated['need_sewing'] = 2;
                $validated['sewing_progress'] = 0;
                OrderTrack::where('type', 1)->where('status', 0)->where('order_id', $order->id)->delete();
                $resetStatus = true;
            }

            if ((int) $order->need_imprinting !== 1 && (int) $this->need_imprinting === 1) {
                $validated['need_imprinting'] = 2;
                $validated['imprinting_progress'] = 0;
                OrderTrack::where('type', 3)->where('status', 0)->where('order_id', $order->id)->delete();
                $resetStatus = true;
            }

            if ((int) $order->need_embroidery !== 1 && (int) $this->need_embroidery === 1) {
                $validated['need_embroidery'] = 2;
                $validated['embroidery_progress'] = 0;
                OrderTrack::where('type', 2)->where('status', 0)->where('order_id', $order->id)->delete();
                $resetStatus = true;
            }

            if ($resetStatus) {
                $order->status = 0;
                $order->save();
            }

            // Update main order record
            $order->update($validated);

            // Sync assignments and log them
            $this->syncAssignmentsWithLogs($order);

            // Check for readiness
            $afterUpdateOrder = $order->fresh();

            $required = collect([
                'need_sewing' => $afterUpdateOrder->need_sewing,
                'need_embroidery' => $afterUpdateOrder->need_embroidery,
                'need_imprinting' => $afterUpdateOrder->need_imprinting,
            ]);

            $totalSteps = $required->filter(fn($val) => (int) $val > 0)->count();
            $readySteps = $required->filter(fn($val) => (int) $val === 1)->count();
            if($this->current_location != $order->current_location) {
                 $order->assignments()->update([
                    'location' => null,
                    ]);
            }
             if ($order->current_location === 'Imprinting' && $this->need_imprinting != 1) {
                $this->addError('location', 'Please select current location');
                return false;
            }

            // Sewing check
            if ($order->current_location === 'Sewing' && $this->need_sewing != 1) {
                $this->addError('location', 'Please select current location');
                return false;
            }

            // Embroidery check
            if ($order->current_location === 'Embroidery' && $this->need_embroidery != 1) {
                $this->addError('location', 'Please select current location');
                return false;
            }
            $afterUpdateOrder->status = ($totalSteps > 0 && $readySteps === $totalSteps) ? 1 : 0;
            $afterUpdateOrder->save();

            OrderLog::forceCreate([
                'order_id' => $order->id,
                'title' => $afterUpdateOrder->status === 1 ? 'Order marked as ready' : 'Order marked as pending',
                'updated_by' => $this->updated_by,
            ]);

            OrderLog::forceCreate([
                'order_id' => $order->id,
                'title' => 'Order updated',
                'updated_by' => $this->updated_by,
            ]);

            session()->flash('message', 'Order has been updated');
            $this->confrmView = false;
        }
    }

    public function syncAssignmentsWithLogs(Order $order)
    {
        $existingAssignments = $order->assignments()->get()->keyBy(function ($assignment) {
            return $assignment->section . '_' . $assignment->employee_id;
        });

        $processedKeys = [];

        foreach ($this->allSplitEntries as $section => $entries) {
            foreach ($entries as $entry) {
                if (!empty($entry['employee_id']) && !empty($entry['quantity'])) {
                    $employeeId = $entry['employee_id'];
                    $quantity = (int)$entry['quantity'];
                    $key = $section . '_' . $employeeId;
                    $processedKeys[] = $key;

                    if ($existingAssignments->has($key)) {
                        $existing = $existingAssignments->get($key);
                        if ($existing->garments_assigned != $quantity) {
                            $existing->update(['garments_assigned' => $quantity]);

                            OrderAssignmentLog::create([
                                'order_id' => $order->id,
                                'assignment_id' => $existing->id,
                                'employee_id' => $employeeId,
                                'title' => "Assignment updated for $section",
                                'updated_by' => $this->updated_by,
                                'section' => $section,
                                'garments_assigned' => $quantity,
                                'status' => 'updated',
                            ]);
                        }
                    } else {
                        $assignment = $order->assignments()->create([
                            'section' => $section,
                            'employee_id' => $employeeId,
                            'garments_assigned' => $quantity,
                        ]);

                        OrderAssignmentLog::create([
                            'order_id' => $order->id,
                            'assignment_id' => $assignment->id,
                            'employee_id' => $employeeId,
                            'title' => "Assigned to $section",
                            'updated_by' => $this->updated_by,
                            'section' => $section,
                            'garments_assigned' => $quantity,
                            'status' => 'assigned',
                        ]);
                    }
                }
            }
        }

        foreach ($existingAssignments as $key => $assignment) {
            if (!in_array($key, $processedKeys)) {
                $assignment->delete();

                OrderAssignmentLog::create([
                    'order_id' => $order->id,
                    'assignment_id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'title' => "Unassigned from {$assignment->section}",
                    'updated_by' => $this->updated_by,
                    'section' => $assignment->section,
                    'garments_assigned' => 0,
                    'status' => 'unassigned',
                ]);
            }
        }
    }


    public function confirmation($status)
    {
        if($status == "no") {
            $this->confrmView = false;
        }
        elseif($status == "update") {
            $this->updateView = false;
            $this->confrmView = true;
        }
        else
        {
            $this->status = $status;
            $this->confrmView = true;
        }
    }

    public function openSplitModal($section)
    {
        $this->resetErrorBag();
        $this->splitSection = $section;
        $this->external_employees = Employee::where('type', 2)
        ->where('active', 1)
        ->where('is_delete', 0)
        ->where('department', $section)
        ->orderBy('first_name', 'asc')
        ->get();
        $this->pendingOrdersPerEmployee = $this->getPendingOrdersCountPerEmployee($section);
        // If already exists, use it
        if (isset($this->allSplitEntries[$section])) {
            $this->splitEntries = $this->allSplitEntries[$section];
        }
        // If not set but another section has pattern, use the first one found
        elseif (!$this->splitQuantitiesPattern && !empty($this->allSplitEntries)) {
            $firstSection = array_key_first($this->allSplitEntries);
            $pattern = collect($this->allSplitEntries[$firstSection])->pluck('quantity')->map(fn($q) => (int) $q)->toArray();
            $this->splitQuantitiesPattern = $pattern;
            $this->splitEntries = collect($pattern)->map(fn($qty) => ['employee_id' => '', 'quantity' => $qty])->toArray();
        }
        // Else, fallback
        elseif ($this->splitQuantitiesPattern) {
            $this->splitEntries = collect($this->splitQuantitiesPattern)
                ->map(fn($qty) => ['employee_id' => '', 'quantity' => $qty])
                ->toArray();
        } else {
            $this->splitEntries = [['employee_id' => '', 'quantity' => '']];
        }

        $this->showSplitModal = true;
    }

     public function addSplitEntry()
    {
        $this->splitEntries[] = ['employee_id' => '', 'quantity' => ''];
    }

    public function removeSplitEntry($index)
    {
        unset($this->splitEntries[$index]);
        $this->splitEntries = array_values($this->splitEntries);
    }

    public function updatedSplitEntries($value, $name)
    {
        $this->validateSplitEntriesTotal();

        // Validate split matches initial pattern if set
        if ($this->splitQuantitiesPattern) {
            $this->validateSplitMatch();
        }
    }

    public function validateSplitEntriesTotal()
    {
        $totalAssigned = collect($this->splitEntries)->sum(fn($entry) => (int) $entry['quantity']);

        if ($totalAssigned > $this->number_of_garments) {
            $this->addError('splitEntries', 'Total assigned garments exceed the number of garments specified.');
        } else {
            $this->resetErrorBag('splitEntries');
        }
    }

    public function validateSplitMatch()
    {
        $currentQuantities = collect($this->splitEntries)->pluck('quantity')->map(fn($q) => (int) $q)->toArray();

        if ($currentQuantities !== $this->splitQuantitiesPattern) {
            $this->addError('splitEntries', 'Garment split quantities must match the first section\'s pattern.');
        } else {
            $this->resetErrorBag('splitEntries');
        }
    }

    public function saveSplitAssignments()
    {
        $totalAssigned = collect($this->splitEntries)->sum(fn($entry) => (int) $entry['quantity']);
        if ($totalAssigned !== (int) $this->number_of_garments) {
            $this->addError('splitEntries', 'Total assigned garments must exactly match the number of garments specified ('.$this->number_of_garments.').');
            return;
        }
        if ($totalAssigned > $this->number_of_garments) {
            $this->addError('splitEntries', 'Total assigned garments exceed the number of garments specified.');
            return;
        }

        foreach ($this->splitEntries as $entry) {
            if (empty($entry['employee_id']) || empty($entry['quantity']) || $entry['quantity'] <= 0) {
                $this->addError('splitEntries', 'Each split must have a valid employee and a positive garment quantity.');
                return;
            }
        }

        $quantities = collect($this->splitEntries)
            ->pluck('quantity')
            ->map(fn($q) => (int) $q)
            ->toArray();

        // if (count($quantities) > 1 && count(array_unique($quantities)) === 1) {
        //     $this->addError('splitEntries', 'Split quantities must not be equally divided.');
        //     return;
        // }

        // Save the first-used pattern
        if (!$this->splitQuantitiesPattern) {
            $this->splitQuantitiesPattern = $quantities;
        }

        // Save entries for this section
        $this->allSplitEntries[$this->splitSection] = $this->splitEntries;

        $this->resetErrorBag('splitEntries');
        $this->showSplitModal = false;
    }

    public function updatedNeedSewing($value)
    {
        if (!$value) {
            unset($this->allSplitEntries['Sewing']);

            if ($this->splitSection === 'Sewing') {
                $this->resetSplitModal();
            }

            $this->maybeResetSplitQuantitiesPattern();
        }
    }
    public function updatedNeedEmbroidery($value)
    {
        if (!$value) {
            unset($this->allSplitEntries['Embroidery']);

            if ($this->splitSection === 'Embroidery') {
                $this->resetSplitModal();
            }

            $this->maybeResetSplitQuantitiesPattern();
        }
    }

    public function updatedNeedImprinting($value)
    {
        if (!$value) {
            unset($this->allSplitEntries['Imprinting']);

            if ($this->splitSection === 'Imprinting') {
                $this->resetSplitModal();
            }

            $this->maybeResetSplitQuantitiesPattern();
        }
    }
    protected function maybeResetSplitQuantitiesPattern()
    {
        if (empty($this->allSplitEntries)) {
            $this->splitQuantitiesPattern = null;
        }
    }
    protected function resetSplitModal()
    {
        $this->showSplitModal = false;
        $this->splitSection = '';
        $this->splitEntries = [];
        $this->resetErrorBag('splitEntries');
    }

    public function getTimeSpentForEmployee($employeeId, $section)
    {
        if (!$this->order) {
            return null;
        }

        $sectionFields = [
            'Sewing' => [
                'progress_field' => 'sewing_progress',
                'completed_field' => 'need_sewing'
            ],
            'Embroidery' => [
                'progress_field' => 'embroidery_progress',
                'completed_field' => 'need_embroidery'
            ],
            'Imprinting' => [
                'progress_field' => 'imprinting_progress',
                'completed_field' => 'need_imprinting'
            ]
        ];

        if (!isset($sectionFields[$section])) {
            return null;
        }

        $fields = $sectionFields[$section];
        $progressField = $fields['progress_field'];
        $completedField = $fields['completed_field'];

        // Check OrderAssignment table: only calculate if employee has both is_progress = 1 AND is_complete = 1
        $assignment = OrderAssignment::where('order_id', $this->order->id)
            ->where('employee_id', $employeeId)
            ->where('section', $section)
            ->where('is_progress', 1)
            ->where('is_complete', 1)
            ->first();

        if (!$assignment) {
            return null;
        }

        // Map section to log title patterns
        $sectionPatterns = [
            'Sewing' => [
                'in_process' => ['Sewing mark as in process', 'Sewing marked as In Progress'],
                'completed' => ['Sewing mark as completed', 'Sewing marked as Complete'],
                'unchecked' => ['Sewing mark as unchecked', 'Sewing mark as Unfinished', 'Sewing unmarked as Complete']
            ],
            'Embroidery' => [
                'in_process' => ['Embroidery mark as in process', 'Embroidery marked as In Progress'],
                'completed' => ['Embroidery mark as completed', 'Embroidery marked as Complete'],
                'unchecked' => ['Embroidery mark as unchecked', 'Embroidery mark as Unfinished', 'Embroidery unmarked as Complete']
            ],
            'Imprinting' => [
                'in_process' => ['Imprinting mark as in process', 'Imprinting marked as In Progress'],
                'completed' => ['Imprinting mark as completed', 'Imprinting marked as Complete'],
                'unchecked' => ['Imprinting mark as unchecked', 'Imprinting mark as Unfinished', 'Imprinting unmarked as Complete']
            ]
        ];

        if (!isset($sectionPatterns[$section])) {
            return null;
        }

        $patterns = $sectionPatterns[$section];

        // Simple logic: Start from the latest "Complete" log for this employee (by ID, not updated_at)
        $completedLog = OrderLog::where('order_id', $this->order->id)
            ->where('updated_by', $employeeId)
            ->where(function($query) use ($patterns) {
                foreach ($patterns['completed'] as $pattern) {
                    $query->orWhere('title', 'like', '%' . $pattern . '%');
                }
            })
            ->where('title', 'not like', '%unmarked%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$completedLog) {
            return null;
        }

        // Check if there's an "unmarked" log after this complete log (by ID)
        $unmarkedAfterComplete = OrderLog::where('order_id', $this->order->id)
            ->where('updated_by', $employeeId)
            ->where(function($query) use ($patterns) {
                foreach ($patterns['unchecked'] as $pattern) {
                    $query->orWhere('title', 'like', '%' . $pattern . '%');
                }
            })
            ->where('id', '>', $completedLog->id)
            ->orderBy('id', 'desc')
            ->first();

        // If there's an unmarked log after the complete, find the complete before the unmarked
        if ($unmarkedAfterComplete) {
            $completedLog = OrderLog::where('order_id', $this->order->id)
                ->where('updated_by', $employeeId)
                ->where(function($query) use ($patterns) {
                    foreach ($patterns['completed'] as $pattern) {
                        $query->orWhere('title', 'like', '%' . $pattern . '%');
                    }
                })
                ->where('title', 'not like', '%unmarked%')
                ->where('id', '<', $unmarkedAfterComplete->id)
                ->orderBy('id', 'desc')
                ->first();

            if (!$completedLog) {
                return null;
            }
        }

        // Now find the latest "In Progress" log (by ID, not updated_at)
        // This handles cases where progress might be logged after completion
        $inProcessLog = OrderLog::where('order_id', $this->order->id)
            ->where('updated_by', $employeeId)
            ->where(function($query) use ($patterns) {
                foreach ($patterns['in_process'] as $pattern) {
                    $query->orWhere('title', 'like', '%' . $pattern . '%');
                }
            })
            ->orderBy('id', 'desc')
            ->first();

        if (!$inProcessLog) {
            return null;
        }

        if (!$completedLog || !$inProcessLog || !$inProcessLog->updated_at || !$completedLog->updated_at) {
            return null;
        }

        // Use the earlier of the two timestamps as start, and the later as end
        // This ensures we calculate time correctly even if logs are out of order
        $startTimestamp = $inProcessLog->updated_at < $completedLog->updated_at 
            ? $inProcessLog->updated_at 
            : $completedLog->updated_at;
        $endTimestamp = $inProcessLog->updated_at > $completedLog->updated_at 
            ? $inProcessLog->updated_at 
            : $completedLog->updated_at;

        // Get employee to access working hours
        $employee = Employee::find($employeeId);
        $startTime = \Carbon\Carbon::parse($startTimestamp);
        $endTime = \Carbon\Carbon::parse($endTimestamp);
        
        if (!$employee || !$employee->working_hours_start || !$employee->working_hours_end) {
            // If no working hours set, calculate simple difference
            $totalSeconds = $startTime->diffInSeconds($endTime);
        } else {
            // Calculate time only within working hours
            $totalSeconds = $this->calculateWorkingTime(
                $startTime, 
                $endTime, 
                $employee->working_hours_start, 
                $employee->working_hours_end,
                $employeeId
            );
            
            if ($totalSeconds === null || $totalSeconds <= 0) {
                return null;
            }
        }
        
        // Convert seconds to hours, minutes, and seconds
        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;
        
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($seconds > 0) {
            $parts[] = $seconds . ' second' . ($seconds > 1 ? 's' : '');
        }

        return !empty($parts) ? implode(' ', $parts) : null;
    }

   
    private function calculateWorkingTime($startTime, $endTime, $workingHoursStart, $workingHoursEnd, $employeeId = null)
    {
        if ($startTime >= $endTime) {
            return null;
        }

        // Parse working hours (handle both H:i and H:i:s formats)
        $startHourStr = is_string($workingHoursStart) ? $workingHoursStart : $workingHoursStart->format('H:i:s');
        $endHourStr = is_string($workingHoursEnd) ? $workingHoursEnd : $workingHoursEnd->format('H:i:s');
        
        // Ensure format is H:i:s
        if (substr_count($startHourStr, ':') === 1) {
            $startHourStr .= ':00';
        }
        if (substr_count($endHourStr, ':') === 1) {
            $endHourStr .= ':00';
        }
        
        $startHour = \Carbon\Carbon::createFromFormat('H:i:s', $startHourStr);
        $endHour = \Carbon\Carbon::createFromFormat('H:i:s', $endHourStr);
        $workDayDuration = $endHour->diffInSeconds($startHour);
        
        $totalSeconds = 0;
        $start = $startTime->copy();
        $end = $endTime->copy();
        
        // Get the dates
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->startOfDay();
        
        // If same day
        if ($startDate->equalTo($endDate)) {
            // Skip if weekend
            if ($start->isSaturday() || $start->isSunday()) {
                return null;
            }
            
            list($startH, $startM, $startS) = explode(':', $startHourStr);
            list($endH, $endM, $endS) = explode(':', $endHourStr);
            $dayStart = $start->copy()->setTime((int)$startH, (int)$startM, (int)$startS);
            $dayEnd = $start->copy()->setTime((int)$endH, (int)$endM, (int)$endS);
            
            // If both are within working hours, count the difference
            if ($start >= $dayStart && $start <= $dayEnd && $end >= $dayStart && $end <= $dayEnd) {
                $totalSeconds = $start->diffInSeconds($end);
            }
            // If start is before working hours and end is during working hours
            elseif ($start < $dayStart && $end >= $dayStart && $end <= $dayEnd) {
                $totalSeconds = $dayStart->diffInSeconds($end);
            }
            // If start is during working hours and end is after working hours
            elseif ($start >= $dayStart && $start <= $dayEnd && $end > $dayEnd) {
                $totalSeconds = $start->diffInSeconds($dayEnd);
            }
            // If both are outside working hours but on same day, count full day
            elseif ($start < $dayStart && $end > $dayEnd) {
                $totalSeconds = $workDayDuration;
            }
        } else {
            // Different days - handle first day, middle days, and last day
            
            // First day: handle progress time
            if (!$start->isSaturday() && !$start->isSunday()) {
                list($startH, $startM, $startS) = explode(':', $startHourStr);
                list($endH, $endM, $endS) = explode(':', $endHourStr);
                $dayStart = $start->copy()->setTime((int)$startH, (int)$startM, (int)$startS);
                $dayEnd = $start->copy()->setTime((int)$endH, (int)$endM, (int)$endS);
                
                // If progress during working hours: count from progress to end of working hours
                if ($start >= $dayStart && $start <= $dayEnd) {
                    $firstDaySeconds = $start->diffInSeconds($dayEnd);
                    $totalSeconds += $firstDaySeconds;
                }
                // If progress before working hours: count full day
                elseif ($start < $dayStart) {
                    $totalSeconds += $workDayDuration;
                }
                // If progress after working hours: don't count this day, will start from next day
            }
            
            // Calculate next day for middle days calculation
            $nextDay = $startDate->copy()->addDay();
            
            // Skip to next working day if it's a weekend
            while ($nextDay->isSaturday() || $nextDay->isSunday()) {
                $nextDay->addDay();
            }
            
            // Middle days: full working hours for each day between first and last day (skip weekends)
            // Only count days that are strictly between start and end dates
            $currentDay = $nextDay->copy()->startOfDay();
            $endDateStart = $endDate->copy()->startOfDay();
            
            // Only count middle days if there's at least one day between start and end
            if ($currentDay->lt($endDateStart)) {
                while ($currentDay->lt($endDateStart)) {
                    if (!$currentDay->isSaturday() && !$currentDay->isSunday()) {
                        $totalSeconds += $workDayDuration;
                    }
                    $currentDay->addDay()->startOfDay();
                }
            }
            
            // Last day: handle completion time
            if (!$end->isSaturday() && !$end->isSunday()) {
                list($startH, $startM, $startS) = explode(':', $startHourStr);
                list($endH, $endM, $endS) = explode(':', $endHourStr);
                $dayStart = $end->copy()->setTime((int)$startH, (int)$startM, (int)$startS);
                $dayEnd = $end->copy()->setTime((int)$endH, (int)$endM, (int)$endS);
                
                // If completion during working hours: count from start of working hours to completion
                if ($end >= $dayStart && $end <= $dayEnd) {
                    $lastDaySeconds = $dayStart->diffInSeconds($end);
                    $totalSeconds += $lastDaySeconds;
                }
                // If completion after working hours: count full day
                elseif ($end > $dayEnd) {
                    $totalSeconds += $workDayDuration;
                }
                // If completion before working hours: don't count this day
            }
        }
        
        return $totalSeconds > 0 ? $totalSeconds : null;
    }

}
