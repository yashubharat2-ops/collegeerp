<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\AcademicSubjectEnrollment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected student subject enrollments.
 *
 * The record set is re-queried inside the active college by
 * {@see \App\Support\BulkAction\BulkActionHandler::execute()} and each row is
 * re-authorized through {@see \App\Policies\AcademicSubjectEnrollmentPolicy}
 * (`academic_subject_enrollments.view`), including its college_id.
 *
 * The CSV itself is streamed by `AcademicExportController::subjectEnrollments`,
 * which re-resolves the authorized ids and — because the selection is a
 * sub-set of the subject enrollment list — exports only columns already shown
 * on that screen. No student identity number (Aadhaar / government id) and no
 * document or file path is part of the export.
 */
class AcademicSubjectEnrollmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AcademicSubjectEnrollment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_subject_enrollments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'academic-subject-enrollments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'subject enrollment' : 'subject enrollments';
    }
}
