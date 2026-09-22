<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryComponent extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const COMPONENT_TYPES = ['earning', 'deduction'];
    public const CALCULATION_TYPES = ['fixed', 'percentage'];
    public const BASES = ['basic', 'gross', 'earnings'];

    protected $fillable = [
        'college_id', 'salary_structure_id', 'name', 'code', 'component_type',
        'calculation_type', 'value', 'basis', 'sort_order', 'status',
    ];

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
