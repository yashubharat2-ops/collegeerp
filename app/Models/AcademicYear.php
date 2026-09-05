<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;
    protected $fillable = ['college_id', 'name', 'code', 'starts_on', 'ends_on', 'status', 'created_by', 'updated_by'];
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date']; }
}
