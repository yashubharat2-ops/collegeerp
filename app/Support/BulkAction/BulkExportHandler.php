<?php

namespace App\Support\BulkAction;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for the "export the selected records" bulk actions.
 *
 * It is deliberately NOT a second bulk-action system: it is an abstract
 * {@see BulkActionHandler} that all the Academic and Examination CSV exports
 * extend, so every one of them inherits the SAME contract the Student,
 * Admission and Enrollment exports already use:
 *
 *  1. the ids come from the browser and are treated as a request, never as
 *     data — `BulkActionHandler::execute()` re-queries them inside the active
 *     college, drops soft-deleted rows and foreign-college ids, and applies
 *     per-record policy authorization before this class ever sees a record;
 *  2. nothing is streamed here. The handler only hands the export endpoint a
 *     redirect built from the ids IT could authorize, and the endpoint
 *     re-resolves those ids through the tenant-scoped query again — a
 *     hand-edited URL can therefore never widen the download;
 *  3. a sub-class may add its own read-only rule on top (e.g. marksheets and
 *     grade cards only exist for PUBLISHED results) through
 *     {@see self::acceptsRecord()}; records failing that rule are never
 *     exported and are reported as skipped by the shared endpoint.
 *
 * Because every id is re-queried server-side, a bulk export can never mutate
 * an academic or examination record: the whole family is read-only by
 * construction.
 */
abstract class BulkExportHandler extends BulkActionHandler
{
    /**
     * Route name of the CSV endpoint that streams the authorized selection.
     */
    abstract protected function exportRouteName(): string;

    /**
     * Extra query parameters the export endpoint needs to reproduce exactly
     * what the listing showed (never invented here: they are server-side values
     * such as the active examination scope). Every endpoint re-validates them
     * and always re-applies the tenant scope and the id narrowing.
     *
     * @param  array<string, mixed>  $parameters  Validated bulk parameters
     * @return array<string, mixed>
     */
    protected function exportParameters(Collection $records, User $user, College $college, array $parameters): array
    {
        return [];
    }

    /**
     * Read-only rule on top of the policy gate, e.g. "only published results
     * have a marksheet". Defaults to accepting every authorized record.
     */
    protected function acceptsRecord(Model $record): bool
    {
        return true;
    }

    /**
     * The ids handed to the export endpoint — always derived from the records
     * the framework authorized, never from the request.
     *
     * @return array<int, int>
     */
    protected function exportIds(Collection $records): array
    {
        return $records->map(fn (Model $record): int => (int) $record->getKey())->values()->all();
    }

    /**
     * Plural-aware noun for the flash message ("2 exam schedules").
     */
    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'record' : 'records';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $exportable = $records
            ->filter(fn (Model $record): bool => $this->acceptsRecord($record))
            ->values();

        if ($exportable->isEmpty()) {
            return BulkActionResult::forbidden('None of the selected records may be exported.');
        }

        $ids = $this->exportIds($exportable);
        $count = count($ids);

        return BulkActionResult::success(
            'Export prepared for '.$count.' '.$this->recordNoun($count).'.',
            $count,
            [
                // Only authorized ids travel in the URL; the endpoint re-queries
                // them inside the same college scope and re-applies its own
                // rules (published-only, filters, permission) on top.
                'redirect' => route($this->exportRouteName(), array_merge(
                    ['ids' => $ids],
                    $this->exportParameters($exportable, $user, $college, $parameters),
                )),
                'ids' => $ids,
            ]
        );
    }
}
