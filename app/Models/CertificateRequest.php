<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bonafide/Character requests; TC requests remain StudentTransfer lifecycle rows. */
class CertificateRequest extends Model
{
    use BelongsToCollege;

    protected $fillable = ['college_id', 'student_id', 'enrollment_id', 'type', 'purpose', 'status', 'requested_by', 'reviewed_by', 'reviewed_at'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class); }
}
