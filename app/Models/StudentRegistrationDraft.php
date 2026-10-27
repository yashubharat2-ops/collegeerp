<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;

/** A private working copy, owned by one user in one college. */
class StudentRegistrationDraft extends Model
{
    use BelongsToCollege;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'college_id', 'created_by', 'data', 'attachments', 'status',
        'student_id', 'approval_request_id',
    ];

    // Never serialize identity values or private file paths into a response.
    protected $hidden = ['data', 'attachments'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'attachments' => 'encrypted:array',
        ];
    }
}
