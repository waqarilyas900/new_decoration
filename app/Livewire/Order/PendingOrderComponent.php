<?php

namespace App\Livewire\Order;

use App\Http\Controllers\EmailSendController;
use App\Mail\ReadyEmail;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderLog;
use App\Models\OrderTrack;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

use Illuminate\Support\Facades\Mail;
use Livewire\WithPagination;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Illuminate\Support\Str;
use App\Services\EtaService;

class PendingOrderComponent extends Component 
{
    #[Url] 
    public $search;
    #[Url] 
    public $location;
    protected $queryString = ['search', 'location'];
    

    use WithPagination;
    protected $paginationTheme = 'bootstrap';
    public $need_sewing;
    public $by_user;
    public $sort;
    public $orderBy;
    #[Url]
    public $internal_employee;
    #[Url]
    public $external_employee;
    public $employees = [];
    public $internalEmployees = [];
    public array $employeeOccupiedUntil = [];
    public $overallEtaBreakdown = [
        'readable' => null,
        'date' => null,
    ];
    public string $calculationTime;
    protected EtaService $etaService;

    public function mount(EtaService $etaService)
    {
    $this->etaService = $etaService;
        $this->employees = Employee::where('type', 2)
            ->where('active', 1)
            ->where('is_delete', 0)
            ->orderBy('first_name', 'asc')
            ->get()
            ->map(function ($employee) {
                $employee['full_name'] = $employee->first_name . ' ' . $employee->last_name;
                $employee['empId'] = $employee->id;
                return $employee;
            })
            ->pluck('full_name', 'empId');

        $this->internalEmployees = Employee::where('type', 1)
            ->where('active', 1)
            ->where('is_delete', 0)
            ->orderBy('first_name', 'asc')
            ->get()
            ->mapWithKeys(function ($employee) {
                return [$employee->id => $employee->first_name . ' ' . $employee->last_name];
            });
             $this->calculationTime = now()->toDateTimeString();
        $this->calculateOverallEta();    
    }

    public function updatingSearch()
    {
        $this->calculationTime = now()->toDateTimeString();
        $this->resetPage();
    }

    public function updatingInternalEmployee()
    {
        $this->resetPage();
        $this->calculationTime = now()->toDateTimeString();
        $this->calculateOverallEta();
    }

    public function updatingExternalEmployee()
    {
        $this->calculationTime = now()->toDateTimeString();
        $this->resetPage();
        $this->calculateOverallEta();
    }
    
    public function updatedExternalEmployee()
    {
        $this->calculateOverallEta();
    }

   
    public function orders()
    {
        $query = Order::with('assignments.employee')
            ->where('status', 0);

        $user = auth()->user();

        if ($user->type == 2) {
            $employeeId = $user->employee_id;

            $assignedSections = OrderAssignment::where('employee_id', $employeeId)
                ->pluck('section')
                ->toArray();

            $query->where(function ($outer) use ($employeeId, $assignedSections) {
                $outer->where(function ($q) use ($employeeId, $assignedSections) {
                    $q->whereHas('assignments', fn($q2) => $q2->where('employee_id', $employeeId))
                    ->whereIn('current_location', $assignedSections);
                })
                ->orWhere(function ($q) use ($employeeId) {
                    $q->whereHas('assignments', fn($q2) => $q2->where('employee_id', $employeeId))
                    ->where(function ($inner) {
                        $inner->where('need_sewing', 1)
                                ->orWhere('need_embroidery', 1)
                                ->orWhere('need_imprinting', 1);
                    });
                });
            });
        }

        if ($this->internal_employee) {
            $query->where('created_by', $this->internal_employee);
        }

        if ($this->external_employee) {
            $query->whereHas('assignments', fn($q) => $q->where('employee_id', $this->external_employee));
        }

        if ($this->location) {
            $query->where('current_location', $this->location);
        }

        if (strlen($this->search) > 3) {
            $query->searchLike(['order_number', 'current_location'], $this->search);
        }

        if ($this->sort) {
            $query->orderBy($this->sort, $this->orderBy);
        } else {
            $query->orderByDesc('is_priority')
                ->orderBy('created_at', 'asc');
        }

        $orders = $query->paginate(20);

        if ($user->type !== 2 && count($orders->items()) > 0) {
            $etaService = app(\App\Services\EtaService::class);

            $employeeId = $this->external_employee ?: null;

            $result = $etaService->calculateEtas($this->calculationTime, $employeeId);
            $orderEtas = $result['orderEtas']; 

            foreach ($orders as $order) {
                if (isset($orderEtas[$order->id])) {
                    $order->expected_delivery = $orderEtas[$order->id]->toDateTimeString();
                }
            }
        }


        return $orders;
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
        $startTime = $start->copy()->setTimeFrom($startHour);
        $endTime = $start->copy()->setTimeFrom($endHour);

        if ($start->lt($startTime)) {
            $start = $startTime;
        } elseif ($start->gte($endTime)) {
            $start = $startTime->addDay();
        }

        while ($this->isWeekend($start)) {
            $start->addDay()->setTimeFrom($startHour);
        }

        return $start;
    }
    public function addWorkingTime(Carbon $start, int $secondsToAdd, Carbon $startHour, Carbon $endHour): Carbon
    {
        $workDaySeconds = $endHour->diffInSeconds($startHour);
        $current = $start->copy();

        while ($secondsToAdd > 0) {
            if ($this->isWeekend($current)) {
                $current->addDay()->setTimeFrom($startHour);
                continue;
            }

            $workDayStart = $current->copy()->setTimeFrom($startHour);
            $workDayEnd = $current->copy()->setTimeFrom($endHour);

            if ($current->lt($workDayStart)) {
                $current = $workDayStart;
            }

            if ($current->gte($workDayEnd)) {
                $current->addDay()->setTimeFrom($startHour);
                continue;
            }

            $availableToday = $workDayEnd->diffInSeconds($current);

            if ($secondsToAdd <= $availableToday) {
                return $current->addSeconds($secondsToAdd);
            }

            $secondsToAdd -= $availableToday;
            $current = $current->copy()->addDay()->setTimeFrom($startHour);
        }

        return $current;
    }
    protected function isWeekend(Carbon $date)
    {
        return $date->isSaturday() || $date->isSunday();
    }
   
   
    public function calculateOverallEta()
    {
        $service = new EtaService();
        
        $employeeId = $this->external_employee ?: null;
        
        $result = $service->calculateEtas($this->calculationTime, $employeeId);

        $this->overallEtaBreakdown = $result['overallEtaBreakdown'];

        foreach ($this->orders() as $order) {
            if (isset($result['orderEtas'][$order->id])) {
                $order->expected_delivery = $result['orderEtas'][$order->id]->toDateTimeString();
            }
        }
    }


    public function sortData($sort, $orderBy)
    {
        $this->orderBy = $orderBy;
        $this->sort  = $sort;
    }
    public function render()
    {
        $this->dispatch('sticky-header'); 
        return view('livewire.order.pending-order-component', [
            'orders' => $this->orders()
        ]);
    }

    public function updateSweing($status, $orderId, $updated_by)
    {
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);  
        ////
        $ready = 0;
        $allCount = 0;
        if($order->need_sewing != 0) {
            $allCount += 1;
        }
        if($order->need_embroidery != 0) {
            $allCount += 1;
        }
        if($order->need_imprinting != 0) {
            $allCount += 1;
        }

        ////////
        $order->need_sewing = $status;
        $order->update();
        if($status)
        {
            $msg = "completed";

            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->update([
                'is_progress' => 1,
                'is_complete' => 1
            ]);

            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 1,
                'status' => 1,
            ]);
        }
        else
        {
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->update([
                // 'is_progress' => 0,
                'is_complete' => 0
            ]);

            OrderTrack::where('type', 1)->where('order_id', $order->id)->where('status', 1)->delete();
            $msg = "unchecked";
            $order->need_sewing = 2;
            $order->update();
        }
        
        // Get all employees assigned to this order for Sewing section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Sewing mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }

        if($order->need_sewing == 1) {
            $ready += 1;
        }
        if($order->need_embroidery == 1) {
            $ready += 1;
        }
        if($order->need_imprinting == 1) {
            $ready += 1;
        }
        

        if($allCount == $ready && isset($order->employee->email)) {
           
            // Mail::to($order->employee->email)->send(new ReadyEmail($order));
            $emailResult = EmailSendController::sendAzureEmail(
                to: $order->employee->email,
                subject: 'Order Ready',
                view: 'email.ready-email',
                data: [
                   'order' => $order
                ],
                fromEmail: 'uniforms@911erp.com'
            );
            $order->status = 1;
            $order->update();
        }
        else
        {
            $order->status = 0;
            $order->update();
        }
         $this->calculateOverallEta();     
    }
    public function updateInprogress($status, $orderId, $updated_by)
    {
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);
        if($status)
        {
            $order->sewing_progress =  1;
            $order->update();
            $msg = "in process";
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->update([
                'is_progress' => 1,
            ]);
        }
        else
        {
            $msg = "Unfinished";
            
        }
        
        // Get all employees assigned to this order for Sewing section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Sewing mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }
        
        /////
        if($status) 
        {
            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 1,
                'status' => 0,
            ]);
        }
        
        else
        {
             OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Sewing')
            ->update([
                'is_progress' => 0,
            ]);
            OrderTrack::where('order_id', $order->id)->where('type', 1)->delete();
            $order->need_sewing = 2;
            $order->sewing_progress = 0;
            $order->update();
        } 
         $this->calculateOverallEta();    
    }
    public function updateEmb($status, $orderId, $updated_by)
    {   
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);  
        ///
        $ready = 0;
        $allCount = 0;
        if($order->need_sewing != 0) {
            $allCount += 1;
        }
        if($order->need_embroidery != 0) {
            $allCount += 1;
        }
        if($order->need_imprinting != 0) {
            $allCount += 1;
        }
        ///
        $order->need_embroidery = $status;
        $order->update();
        if($status){

            // dd('asd');
            $msg = "completed";
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->update([
                'is_progress' => 1,
                'is_complete' => 1
            ]);
            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 2,
                'status' => 1,
            ]);
           
        }
        else
        {
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->update([
                // 'is_progress' => 0,
                'is_complete' => 0
            ]);

            OrderTrack::where('type', 2)->where('order_id', $order->id)->where('status', 1)->delete();
            $msg = "unchecked";
            $order->need_embroidery = 2;
            $order->update();
        }
        
        // Get all employees assigned to this order for Embroidery section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Embroidery mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }
        ///
        if($order->need_sewing == 1) {
            $ready += 1;
        }
        if($order->need_embroidery == 1) {
            $ready += 1;
        }
        if($order->need_imprinting == 1) {
            $ready += 1;
        }
        

        if($allCount == $ready) {
            // Mail::to($order->employee->email)->send(new ReadyEmail($order));
            $emailResult = EmailSendController::sendAzureEmail(
                to: $order->employee->email,
                subject: 'Order Ready',
                view: 'email.ready-email',
                data: [
                   'order' => $order
                ],
                fromEmail: 'uniforms@911erp.com'
            );
            $order->status = 1;
            $order->update();
        }else{
            $order->status = 0;
            $order->update();
        }
         $this->calculateOverallEta();   
    }

    public function updateInprogressEmb($status, $orderId, $updated_by)
    {
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);
        if($status)
        {
            
            $order->embroidery_progress =  1;
            $order->update();
            $msg = "in process";
             OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->update([
                'is_progress' => 1,
            ]);
        }
        else
        {
            $msg = "Unfinished";
        }
        
        // Get all employees assigned to this order for Embroidery section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Embroidery mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }
        
        /////
        if($status) 
        {
            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 2,
                'status' => 0,
            ]);
        }
        else
        {
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Embroidery')
            ->update([
                'is_progress' => 0,
            ]);
            OrderTrack::where('order_id', $order->id)->where('type', 2)->delete();
            $order->need_embroidery = 2;
            $order->embroidery_progress = 0;
            $order->update();
        }  
         $this->calculateOverallEta();   
    }
    public function updateImp($status, $orderId, $updated_by)
    {
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);  
         ///
         $ready = 0;
         $allCount = 0;
         if($order->need_sewing != 0) {
             $allCount += 1;
         }
         if($order->need_embroidery != 0) {
             $allCount += 1;
         }
         if($order->need_imprinting != 0) {
             $allCount += 1;
         }
         ///
        $order->need_imprinting = $status;
        $order->update();
        if($status)
        {
            $msg = "completed";
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->update([
                'is_progress' => 1,
                'is_complete' => 1
            ]);
            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 3,
                'status' => 1,
            ]);
        }
        else
        {
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->update([
                // 'is_progress' => 0,
                'is_complete' => 0
            ]);
            OrderTrack::where('type', 3)->where('status', 1)->where('order_id', $order->id)->delete();
            $msg = "unchecked";
            $order->need_imprinting = 2;
            $order->update();
        }
        
        // Get all employees assigned to this order for Imprinting section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Imprinting mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }
        ///
        if($order->need_sewing == 1) {
            $ready += 1;
        }
        if($order->need_embroidery == 1) {
            $ready += 1;
        }
        if($order->need_imprinting == 1) {
            $ready += 1;
        }
        

        if($allCount == $ready) {
            // Mail::to($order->employee->email)->send(new ReadyEmail($order));
            $emailResult = EmailSendController::sendAzureEmail(
                to: $order->employee->email,
                subject: 'Order Ready',
                view: 'email.ready-email',
                data: [
                   'order' => $order
                ],
                fromEmail: 'uniforms@911erp.com'
            );
            $order->status = 1;
            $order->update();
        }else{
            $order->status = 0;
            $order->update();
        }
         $this->calculateOverallEta();   
    }
    public function updateInprogressImp($status, $orderId, $updated_by)
    {
        $numbersOnly = preg_replace("/[^0-9]/", "", $orderId);
        $order = Order::find($numbersOnly);
        if($status)
        {
            $order->imprinting_progress =  1;
            $order->update();
            $msg = "in process";
            OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->update([
                'is_progress' => 1,
            ]);
        }
        else
        {
            $msg = "Unfinished";
        }
        
        // Get all employees assigned to this order for Imprinting section
        $assignedEmployees = OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->pluck('employee_id')
            ->unique();
        
        // Create log entry for each assigned employee
        foreach ($assignedEmployees as $employeeId) {
            OrderLog::forceCreate([
                'title' => "Imprinting mark as $msg",
                'updated_by' => $employeeId,
                'order_id' => $order->id
            ]);
        }
        
        /////
        if($status) 
        {
            OrderTrack::forceCreate([
                'order_id' => $order->id,
                'type' => 3,
                'status' => 0,
            ]);
        }
        else
        {
             OrderAssignment::where('order_id', $order->id)
            ->where('section', 'Imprinting')
            ->update([
                'is_progress' => 0,
            ]);
            OrderTrack::where('order_id', $order->id)->where('type', 3)->delete();
            $order->need_imprinting = 2;
            $order->imprinting_progress = 0;
            $order->update();
        }
         $this->calculateOverallEta();     
    }
    public function updateLocation($orderId, $updated_by, $selectedText)
    {
        $order = Order::find($orderId);
        if($order){
            $order->assignments()->update([
            'location' => null,
            ]);
        }
        $location = $order->current_location;
        $order->current_location=$selectedText;
        $order->update();
        OrderLog::forceCreate([
            'title' => "Location changed from ".$location. " to $selectedText",
            'updated_by' => $updated_by,
            'order_id' => $order->id
        ]);
         $this->calculateOverallEta();   
    }
    #[On('fetchAssignedEmployees')]
    public function fetchAssignedEmployees($orderId, $section, $currentLocation)
    {

        $assigned = Employee::whereHas('assignments', function ($q) use ($orderId, $section, $currentLocation) {
                $q->where('order_id', $orderId)
                ->where('section', $section)
                ->where(function ($sub) use ($currentLocation) {
                    $sub->whereNull('location')
                        ->orWhere('location', $currentLocation);
                });
            })
            ->where('type', 2)
            ->where('active', 1)
            ->where('is_delete', 0)
            ->get();

        $assignedNames = $assigned->map(function ($e) {
            return trim("{$e->first_name} {$e->last_name}");
        })->toArray();

        $this->dispatch('assigned-employees-loaded', assigned: $assignedNames);
    } 
}
