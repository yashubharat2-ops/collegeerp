<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/** Issued Bonafide/Character certificates. TC is deliberately not stored here. */
class CertificateIssuance extends Model
{
    use BelongsToCollege;

    protected $fillable = ['college_id', 'student_id', 'enrollment_id', 'template_id', 'type', 'certificate_number', 'issued_at', 'purpose', 'rendered_content', 'issued_by'];
    protected function casts(): array { return ['issued_at' => 'date']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class); }
    public function template(): BelongsTo { return $this->belongsTo(CertificateTemplate::class); }
}
