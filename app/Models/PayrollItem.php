<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    use HasFactory, BelongsToCollege;

    protected $fillable = [
        'college_id', 'payroll_id', 'salary_component_id', 'component_name',
        'component_code', 'component_type', 'calculation_type', 'input_value', 'amount',
    ];

    protected function casts(): array
    {
        return ['input_value' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function salaryComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class);
    }
}
