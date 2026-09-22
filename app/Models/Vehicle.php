<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const FIELDS = ['registration_number' => 'text', 'vehicle_type' => 'text', 'make' => 'text', 'model' => 'text', 'seating_capacity' => 'number', 'purchase_date' => 'date', 'insurance_expiry' => 'date', 'fitness_expiry' => 'date', 'permit_expiry' => 'date', 'status' => 'select', 'remarks' => 'textarea'];

    public const STATUSES = ['active', 'inactive', 'maintenance', 'retired'];

    protected $fillable = ['registration_number', 'vehicle_type', 'make', 'model', 'seating_capacity', 'purchase_date', 'insurance_expiry', 'fitness_expiry', 'permit_expiry', 'status', 'remarks'];

    public function setRegistrationNumberAttribute($value): void
    {
        $this->attributes['registration_number'] = strtoupper(trim((string) $value));
    }

}
