<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * VehicleDocument — a private document attached to an existing Vehicle
 * (registration / RC, insurance, fitness certificate, permit, PUC, other).
 *
 * `document_type` is an extensible string, NOT a closed enum: a college may
 * store any document category without a migration (same pattern as the payment
 * modes of FeePayment). The application only SUGGESTS the conventional set in
 * TYPES below and never rejects another label.
 *
 * Security: `file_path` is server-generated (uuid + sanitised extension) under
 * a tenant-scoped private-disk directory (`vehicle-documents/{college}/{vehicle}/`)
 * and is never accepted from a request. Access always goes through the
 * authorized controller action, which re-resolves the row through CollegeScope
 * and re-checks the stored path before streaming (path traversal defence in
 * depth — see VehicleDocumentService::assertSafePath).
 *
 * Document history is preserved: rows are soft-deleted and the stored file is
 * retained; create/update/replace/delete/download are audited.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class VehicleDocument extends Model
{
    use SoftDeletes, BelongsToCollege;

    /** How many days before expiry a document starts showing "expiring". */
    public const EXPIRING_SOON_DAYS = 30;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Conventional document categories (extensible, not enforced).
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'registration' => 'Registration / RC',
        'insurance' => 'Insurance',
        'fitness_certificate' => 'Fitness Certificate',
        'permit' => 'Permit',
        'puc' => 'Pollution / PUC',
        'other' => 'Other',
    ];

    protected $fillable = [
        'college_id',
        'vehicle_id',
        'document_type',
        'document_number',
        'issue_date',
        'expiry_date',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'remarks',
        'uploaded_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'issue_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Human label for the (extensible) document type. */
    public function typeLabel(): string
    {
        return self::TYPES[$this->document_type] ?? str_replace('_', ' ', ucfirst((string) $this->document_type));
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = self::EXPIRING_SOON_DAYS): bool
    {
        return $this->expiry_date !== null
            && ! $this->isExpired()
            && $this->expiry_date->lte(Carbon::today()->addDays($days));
    }

    /**
     * Validity status derived purely from dates: active / expiring / expired.
     */
    public function documentStatus(): string
    {
        return match (true) {
            $this->isExpired() => self::STATUS_EXPIRED,
            $this->isExpiringSoon() => self::STATUS_EXPIRING,
            default => self::STATUS_ACTIVE,
        };
    }

    public function sizeInKb(): float
    {
        return round(((int) $this->file_size) / 1024, 1);
    }
}
