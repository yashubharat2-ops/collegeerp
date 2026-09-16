<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AdmissionDocumentType — master list of document types required for admission.
 *
 * Tenant scoped, configurable per college, optionally per program via pivot.
 * No hard-coded college rules.
 */
class AdmissionDocumentType extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'code',
        'name',
        'description',
        'is_required',
        'allowed_extensions',
        'allowed_mimes',
        'max_size_kb',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'max_size_kb' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AdmissionDocument::class, 'document_type_id');
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'admission_document_type_program', 'document_type_id', 'program_id')
            ->withPivot(['college_id', 'is_required'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get allowed extensions as array, normalized lower case.
     */
    public function allowedExtensionsArray(): array
    {
        if (blank($this->allowed_extensions)) {
            return ['pdf', 'jpg', 'jpeg', 'png'];
        }

        return collect(explode(',', $this->allowed_extensions))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()
            ->values()
            ->all();
    }

    public function allowedMimesArray(): array
    {
        if (blank($this->allowed_mimes)) {
            // Default safe set
            return ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        }

        return collect(explode(',', $this->allowed_mimes))
            ->map(fn ($m) => strtolower(trim($m)))
            ->filter()
            ->values()
            ->all();
    }
}
