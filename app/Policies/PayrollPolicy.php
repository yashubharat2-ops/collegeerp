<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;

class PayrollPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('payrolls.view'); }
    public function view(User $user, Payroll $payroll): bool { return $user->hasPermission('payrolls.view', $payroll->college_id); }
    public function create(User $user): bool
    {
        return $user->hasPermission('payrolls.create') || $user->hasPermission('payrolls.process');
    }
    public function update(User $user, Payroll $payroll): bool { return $user->hasPermission('payrolls.update', $payroll->college_id); }
    public function delete(User $user, Payroll $payroll): bool { return $user->hasPermission('payrolls.delete', $payroll->college_id); }
    public function process(User $user): bool
    {
        return $user->hasPermission('payrolls.create') || $user->hasPermission('payrolls.process');
    }
    public function cancel(User $user, Payroll $payroll): bool { return $user->hasPermission('payrolls.update', $payroll->college_id); }
}
