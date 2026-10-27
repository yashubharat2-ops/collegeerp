<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document uploaded while the wizard draft has no student yet.
 *
 * The blob already lives on the private disk under a server-generated,
 * tenant-scoped path; this row is metadata only. On submission the staged rows
 * are adopted into the existing `student_documents` table (same file, same
 * architecture) and removed.
 */
class StudentRegistrationDraftDocument extends Model
{
    use BelongsToCollege;

    protected $fillable = [
        'college_id', 'draft_id', 'document_type_id', 'title',
        'file_path', 'original_filename', 'mime_type', 'file_size', 'remarks',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(StudentRegistrationDraft::class, 'draft_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(AdmissionDocumentType::class, 'document_type_id');
    }
}
