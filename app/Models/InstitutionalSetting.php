<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;

class InstitutionalSetting extends Model
{
    use BelongsToCollege;
    protected $fillable = ['college_id', 'key', 'value', 'type', 'is_public', 'created_by', 'updated_by'];
    protected function casts(): array { return ['is_public' => 'boolean']; }
}
