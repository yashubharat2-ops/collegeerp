<?php

namespace App\Domain\HR\Services;

use App\Models\Faculty;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use App\Services\Audit\AuditLogService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll foundation: calculate a deterministic monthly snapshot from a salary
 * structure. It deliberately does not post accounting entries or touch Finance.
 */
class PayrollService
{
    private const AUDITED = [
        'id', 'faculty_id', 'salary_structure_id', 'pay_period', 'basic_amount',
        'gross_amount', 'total_deductions', 'net_amount', 'status', 'remarks',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function process(array $data, int $collegeId, ?int $userId): Payroll
    {
        abort_unless((int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey() === $collegeId, 403);
        $period = $this->period($data['pay_period']);
        $employee = Faculty::query()->whereKey((int) $data['faculty_id'])->where('status', 'active')->first();
        $structure = SalaryStructure::query()->with('activeComponents')->whereKey((int) $data['salary_structure_id'])->where('status', 'active')->first();

        if (! $employee) {
            abort(404, 'Active employee not found in this college context.');
        }
        if (! $structure) {
            abort(404, 'Active salary structure not found in this college context.');
        }
        if ($structure->effective_from && $period->lt($structure->effective_from->startOfMonth())) {
            throw ValidationException::withMessages(['salary_structure_id' => 'The salary structure is not effective for this pay period.']);
        }
        if ($structure->effective_to && $period->gt($structure->effective_to->startOfMonth())) {
            throw ValidationException::withMessages(['salary_structure_id' => 'The salary structure has expired for this pay period.']);
        }

        $calculation = $this->calculate($structure);

        try {
            $payroll = DB::transaction(function () use ($data, $collegeId, $userId, $period, $structure, $employee, $calculation): Payroll {
                if (Payroll::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('faculty_id', $employee->id)
                    ->whereDate('pay_period', $period->toDateString())
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'pay_period' => 'Payroll for this employee and pay period already exists.',
                    ]);
                }

                $payroll = Payroll::create([
                    'college_id' => $collegeId,
                    'faculty_id' => $employee->id,
                    'salary_structure_id' => $structure->id,
                    'pay_period' => $period->toDateString(),
                    'basic_amount' => $calculation['basic_amount'],
                    'gross_amount' => $calculation['gross_amount'],
                    'total_deductions' => $calculation['total_deductions'],
                    'net_amount' => $calculation['net_amount'],
                    'status' => 'processed',
                    'remarks' => $data['remarks'] ?? null,
                    'processed_at' => now(),
                    'processed_by' => $userId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                foreach ($calculation['items'] as $item) {
                    $payroll->items()->create([
                        'college_id' => $collegeId,
                        'salary_component_id' => $item['salary_component_id'],
                        'component_name' => $item['component_name'],
                        'component_code' => $item['component_code'],
                        'component_type' => $item['component_type'],
                        'calculation_type' => $item['calculation_type'],
                        'input_value' => $item['input_value'],
                        'amount' => $item['amount'],
                    ]);
                }

                return $payroll;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'pay_period' => 'Payroll for this employee and pay period already exists.',
            ]);
        }

        $this->audit->record('payroll.processed', $payroll, [], $payroll->only(self::AUDITED));

        return $payroll->load(['employee', 'salaryStructure', 'items']);
    }

    /** @return array{basic_amount: float, gross_amount: float, total_deductions: float, net_amount: float, items: array<int, array<string, mixed>>} */
    public function calculate(SalaryStructure $structure): array
    {
        $gross = 0.0;
        $deductions = 0.0;
        $basic = 0.0;
        $amountsByCode = [];
        $items = [];
        $hasBasicComponent = $structure->activeComponents->contains(fn ($component) => strtoupper((string) $component->code) === 'BASIC');

        foreach ($structure->activeComponents as $component) {
            $base = $this->basisAmount((string) $component->basis, $gross, $basic, $amountsByCode);
            $inputValue = $this->money($component->value);
            $amount = $component->calculation_type === 'percentage'
                ? $this->money($base * $inputValue / 100)
                : $inputValue;

            if ($component->component_type === 'earning') {
                $gross = $this->money($gross + $amount);
                if (strtoupper((string) $component->code) === 'BASIC' || (! $hasBasicComponent && $basic === 0.0)) {
                    $basic = $amount;
                }
            } else {
                $deductions = $this->money($deductions + $amount);
            }

            $amountsByCode[strtoupper((string) $component->code)] = $amount;
            $items[] = [
                'salary_component_id' => $component->id,
                'component_name' => $component->name,
                'component_code' => $component->code,
                'component_type' => $component->component_type,
                'calculation_type' => $component->calculation_type,
                'input_value' => $inputValue,
                'amount' => $amount,
            ];
        }

        return [
            'basic_amount' => $this->money($basic),
            'gross_amount' => $this->money($gross),
            'total_deductions' => $this->money($deductions),
            'net_amount' => $this->money(max(0, $gross - $deductions)),
            'items' => $items,
        ];
    }

    public function cancel(Payroll $payroll, ?int $userId): Payroll
    {
        abort_unless((int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey() === (int) $payroll->college_id, 403);
        if ($payroll->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'This payroll is already cancelled.']);
        }

        $old = $payroll->only(self::AUDITED);
        $payroll->update(['status' => 'cancelled', 'updated_by' => $userId]);
        $this->audit->record('payroll.cancelled', $payroll, $old, $payroll->fresh()->only(self::AUDITED));

        return $payroll->refresh();
    }

    private function period(string $period): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['pay_period' => 'Pay period must be a valid month.']);
        }
    }

    private function basisAmount(string $basis, float $gross, float $basic, array $amountsByCode): float
    {
        if ($basis !== '' && ! in_array($basis, ['basic', 'gross', 'earnings'], true)) {
            return $amountsByCode[strtoupper($basis)] ?? 0.0;
        }

        return match ($basis) {
            'basic' => $basic,
            'gross', 'earnings' => $gross,
            default => $gross,
        };
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
