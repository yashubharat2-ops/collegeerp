<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportDriver extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const FIELDS = ['faculty_id' => 'staff', 'license_number' => 'text', 'license_type' => 'text', 'license_expiry' => 'date', 'joining_date' => 'date', 'status' => 'select', 'remarks' => 'textarea'];

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = ['faculty_id', 'license_number', 'license_type', 'license_expiry', 'joining_date', 'status', 'remarks'];

    public function setLicenseNumberAttribute($value): void
    {
        $this->attributes['license_number'] = strtoupper(trim((string) $value));
    }

    public function faculty(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }
}
