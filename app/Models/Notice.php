<?php

namespace App\Models;

use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Domain\Communication\Traits\HasPublicationWorkflow;
use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Notice / Announcement (Communication Management, Phase 1).
 *
 * A tenant-scoped announcement with a draft → published → archived workflow
 * (see PublicationWorkflow), a priority, an audience target and an optional
 * private attachment.
 *
 *  - `notice_type` is an extensible, normalised label (CommunicationTypes);
 *  - `target_type` is extensible (CommunicationTargets): audience-wide
 *    targets need no `target_id`; entity targets reference an EXISTING
 *    department / program / section of the same college;
 *  - `slug` is generated server-side, unique per college (archived rows keep
 *    theirs), and only regenerated while the notice is still a draft;
 *  - `status` is never mass-assigned from a form — only the publish /
 *    unpublish / archive actions (guarded by `notices.publish`) change it.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope. college_id,
 * created_by and updated_by are always stamped server-side.
 */
class Notice extends Model
{
    use BelongsToCollege, HasPublicationWorkflow, SoftDeletes;

    public const STATUSES = PublicationWorkflow::STATUSES;

    public const PRIORITIES = CommunicationPriority::ALL;

    /** Suggested (not enforced) notice categories. */
    public const TYPES = CommunicationTypes::NOTICE_TYPES;

    protected $fillable = [
        'college_id',
        'title',
        'slug',
        'notice_type',
        'content',
        'publish_at',
        'expires_at',
        'status',
        'priority',
        'target_type',
        'target_id',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => PublicationWorkflow::DRAFT,
        'priority' => CommunicationPriority::NORMAL,
        'target_type' => CommunicationTargets::ALL,
    ];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'target_id' => 'integer',
            'attachment_size' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function typeLabel(): string
    {
        return CommunicationTypes::label($this->notice_type, self::TYPES);
    }

    /** "Everyone", "All staff", "Program: B.Sc Physics (BSCP)", … */
    public function targetLabel(): string
    {
        return CommunicationTargets::describe($this->target_type, $this->target_id, (int) $this->college_id);
    }
}
