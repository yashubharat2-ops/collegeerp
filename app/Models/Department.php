<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = ['college_id', 'campus_id', 'name', 'code', 'description', 'status'];

    public function campus(): BelongsTo { return $this->belongsTo(Campus::class); }
}
