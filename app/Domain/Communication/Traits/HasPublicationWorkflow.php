<?php

namespace App\Domain\Communication\Traits;

use App\Domain\Communication\Support\PublicationWorkflow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared read-side behaviour of publishable communication records
 * (Notices, Circulars): status helpers, derived visibility, the "live"
 * scope and attachment helpers.
 *
 * Visibility is derived at read time from status + publish_at + expires_at,
 * so no scheduler or stored flag is needed and it can never go stale:
 *
 *   draft / archived                    → the status itself
 *   published, publish_at in the future → scheduled
 *   published, expires_at reached       → expired
 *   published otherwise                 → live
 */
trait HasPublicationWorkflow
{
    public function isDraft(): bool
    {
        return $this->status === PublicationWorkflow::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === PublicationWorkflow::PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->status === PublicationWorkflow::ARCHIVED;
    }

    public function canTransition(string $action): bool
    {
        return PublicationWorkflow::canTransition((string) $this->status, $action);
    }

    public function visibility(): string
    {
        if (! $this->isPublished()) {
            return $this->isArchived() ? PublicationWorkflow::VISIBILITY_ARCHIVED : PublicationWorkflow::VISIBILITY_DRAFT;
        }

        $now = now();

        if ($this->expires_at !== null && $this->expires_at->lte($now)) {
            return PublicationWorkflow::VISIBILITY_EXPIRED;
        }

        if ($this->publish_at !== null && $this->publish_at->gt($now)) {
            return PublicationWorkflow::VISIBILITY_SCHEDULED;
        }

        return PublicationWorkflow::VISIBILITY_LIVE;
    }

    /**
     * Published, already effective and not yet expired.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where($this->qualifyColumn('status'), PublicationWorkflow::PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('publish_at'))->orWhere($this->qualifyColumn('publish_at'), '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('expires_at'))->orWhere($this->qualifyColumn('expires_at'), '>', $now));
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    public function attachmentSizeLabel(): string
    {
        $bytes = (int) $this->attachment_size;

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max($bytes, 0) / 1024, 1).' KB';
    }
}
