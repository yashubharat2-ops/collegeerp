<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\Section;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected class / section records from the Academic section
 * management screen.
 *
 * Sections are Platform master data and are referenced, never duplicated, so
 * the export reads the existing `Section` rows for the active college only —
 * `BulkActionHandler::execute()` re-queries the ids inside the college scope,
 * which drops a foreign-college or soft-deleted id instead of exporting it.
 *
 * Authorization is the screen's own permission (`academic_sections.view`).
 * Section management proper stays behind the Platform `sections.*` permissions
 * and the SectionPolicy: this handler exposes no write path at all. The CSV
 * carries the section's own reference data plus its derived subject count —
 * no student-level data, no identity numbers.
 */
class AcademicSectionBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Section::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_sections.view';
    }

    protected function exportRouteName(): string
    {
        return 'academic-sections.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'section' : 'sections';
    }
}
