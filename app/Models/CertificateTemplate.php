<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateTemplate extends Model
{
    use BelongsToCollege;

    protected $guarded = ['id'];

    public function type(): BelongsTo { return $this->belongsTo(CertificateType::class, 'certificate_type_id'); }
}
