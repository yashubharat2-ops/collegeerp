<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use HasFactory, BelongsToCollege;

    public const STATUSES = ['processed', 'cancelled'];

    protected $fillable = [
        'college_id', 'faculty_id', 'salary_structure_id', 'pay_period',
        'basic_amount', 'gross_amount', 'total_deductions', 'net_amount',
        'status', 'remarks', 'processed_at', 'processed_by', 'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'pay_period' => 'date',
            'basic_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class)->orderBy('id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
