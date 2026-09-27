<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateType extends Model
{
    use BelongsToCollege;

    public const BUILT_INS = [
        'transfer' => ['TC', 'Transfer Certificate (TC)'],
        'bonafide' => ['BON', 'Bonafide Certificate'],
        'character' => ['CHAR', 'Character Certificate'],
        'course_completion' => ['CC', 'Course Completion Certificate'],
        'migration' => ['MIG', 'Migration Certificate'],
        'provisional' => ['PROV', 'Provisional Certificate'],
        'custom' => ['CUSTOM', 'Custom Certificate'],
    ];

    protected $guarded = ['id'];

    public function templates(): HasMany { return $this->hasMany(CertificateTemplate::class); }
    public function certificates(): HasMany { return $this->hasMany(Certificate::class); }
}
