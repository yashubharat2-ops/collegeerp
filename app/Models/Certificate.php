<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use BelongsToCollege;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data_snapshot' => 'array', 'generated_at' => 'datetime', 'issued_at' => 'datetime', 'last_verified_at' => 'datetime'];
    }

    public function type(): BelongsTo { return $this->belongsTo(CertificateType::class, 'certificate_type_id'); }
    public function template(): BelongsTo { return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id'); }
    public function transfer(): BelongsTo { return $this->belongsTo(StudentTransfer::class, 'student_transfer_id'); }

    /** Plain-text substitution only: never execute administrator-supplied PHP/Blade/HTML. */
    public function renderedBody(): string
    {
        $data = ($this->data_snapshot ?? []) + [
            'certificate_number' => $this->number ?? 'DRAFT — not issued',
            'issue_date' => $this->issued_at?->toDateString() ?? '',
        ];
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($match) => (string) ($data[$match[1]] ?? ''), $this->template_snapshot ?? '');
    }
}
