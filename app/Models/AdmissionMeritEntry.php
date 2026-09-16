<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AdmissionMeritEntry — individual merit ranking for an application within a list.
 *
 * No hard-coded formula: merit_score is configurable decimal, rank is explicit.
 * Selection status: selected, waitlisted, rejected, pending.
 */
class AdmissionMeritEntry extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'merit_list_id',
        'application_id',
        'applicant_id',
        'merit_score',
        'rank',
        'selection_status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'merit_score' => 'decimal:2',
            'rank' => 'integer',
        ];
    }

    public const SELECTION_STATUSES = ['pending', 'selected', 'waitlisted', 'rejected'];

    public function meritList(): BelongsTo
    {
        return $this->belongsTo(AdmissionMeritList::class, 'merit_list_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplicant::class, 'applicant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
