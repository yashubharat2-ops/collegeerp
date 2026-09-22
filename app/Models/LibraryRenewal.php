<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LibraryRenewal — one extension of an issued library transaction
 * (Library Management, Phase 2).
 *
 * Append-only. old_due_date is the due date before this renewal; the issue
 * transaction keeps issued_on and stores the new current due date. There is
 * no update or delete path.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope.
 */
class LibraryRenewal extends Model
{
    use HasFactory, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'issue_transaction_id',
        'old_due_date',
        'new_due_date',
        'renewed_on',
        'renewed_by',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'old_due_date' => 'date',
            'new_due_date' => 'date',
            'renewed_on' => 'date',
        ];
    }

    public function issueTransaction(): BelongsTo
    {
        return $this->belongsTo(LibraryTransaction::class, 'issue_transaction_id');
    }

    public function renewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewed_by');
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
