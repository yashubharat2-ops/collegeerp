<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    use BelongsToCollege;

    protected $fillable = ['college_id', 'type', 'name', 'body', 'is_active', 'created_by'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
