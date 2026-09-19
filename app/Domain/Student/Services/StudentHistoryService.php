<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Support\StudentHistoryEvent;
use App\Models\AuditLog;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Consolidated, chronological student lifecycle history.
 *
 * DERIVED, NOT DUPLICATED. Nothing is copied into a history table: the timeline
 * is assembled on demand from the records that already own the facts
 * (AdmissionApplication/Admission, the Student row, StudentEnrollment,
 * StudentAcademicRecord, StudentPromotion, StudentTransfer, StudentDocument)
 * and from the append-only AuditLog. A dedicated immutable history table was
 * considered and rejected: it would be a second source of truth that can drift
 * from the modules it summarises, while the audit log already provides the
 * tamper-evident (non-updatable, non-deletable) record of state changes.
 *
 * The result is deterministic: StudentHistoryEvent::sortKey() orders events by
 * timestamp, then a fixed category rank, then the originating record id, so the
 * same data always renders in the same order.
 *
 * Tenant safety: callers must pass a Student that was resolved through
 * CollegeScope; audit entries are additionally filtered by college_id, so a
 * timeline can never leak another college's events.
 */
class StudentHistoryService
{
    /** Human labels for the student-related audit actions. */
    public const AUDIT_LABELS = [
        'student.created' => 'Student record created',
        'student.updated' => 'Student details updated',
        'student.converted' => 'Converted from admission application',
        'student.deleted' => 'Student record deleted',
        'student_id_card.generated' => 'ID card generated',
    ];

    /** Actions that belong to the Students module (used for the college feed). */
    public const ACTION_CATEGORIES = [
        'student_academic_record' => 'academic',
        'student_document' => 'document',
        'student_enrollment' => 'enrollment',
        'student_id_card' => 'student',
        'student_promotion' => 'promotion',
        'student_transfer' => 'transfer',
    ];

    /**
     * Full chronological timeline for one student, oldest first.
     *
     * @return Collection<int, StudentHistoryEvent>
     */
    public function forStudent(Student $student): Collection
    {
        $student->loadMissing([
            'admissionApplication.admission',
            'enrollments.academicYear',
            'enrollments.program',
            'enrollments.section',
            'academicRecords.academicYear',
            'academicRecords.academicTerm',
            'promotions',
            'transfers',
            'documents',
        ]);

        $events = [];

        $this->addAdmissionEvents($student, $events);
        $this->addStudentEvents($student, $events);
        $this->addEnrollmentEvents($student, $events);
        $this->addAcademicRecordEvents($student, $events);
        $this->addPromotionEvents($student, $events);
        $this->addTransferEvents($student, $events);
        $this->addDocumentEvents($student, $events);
        $this->addAuditEvents($student, $events);

        return collect($events)
            ->sortBy(fn (StudentHistoryEvent $event) => $event->sortKey())
            ->values();
    }

    /**
     * Most recent Students-module activity for the active college, newest first.
     *
     * Backed by the append-only audit log, which is already tenant stamped.
     *
     * @return Collection<int, StudentHistoryEvent>
     */
    public function recentForCollege(int $collegeId, int $limit = 30): Collection
    {
        $logs = AuditLog::query()
            ->where('college_id', $collegeId)
            ->where('action', 'like', 'student%')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)))
            ->get();

        $studentNumbers = $this->studentNumbersFor($logs);

        return $logs->map(function (AuditLog $log) use ($studentNumbers): StudentHistoryEvent {
            $subject = $studentNumbers[$log->subject_id] ?? null;

            return new StudentHistoryEvent(
                occurredAt: $log->created_at,
                category: $this->categoryForAction($log->action),
                label: $this->labelForAction($log->action),
                description: ($subject ? 'Student '.$subject.' · ' : '').$this->describeChanges($log->old_values ?? [], $log->new_values ?? []),
                reference: $log->action,
                referenceId: (int) $log->id,
                source: 'audit',
            );
        })->values();
    }

    private function studentNumbersFor(EloquentCollection $logs): array
    {
        $ids = $logs
            ->filter(fn (AuditLog $log) => $log->subject_type === (new Student)->getMorphClass() && $log->subject_id)
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Student::query()
            ->whereIn('id', $ids)
            ->pluck('student_number', 'id')
            ->all();
    }

    private function addAdmissionEvents(Student $student, array &$events): void
    {
        $application = $student->admissionApplication;

        if ($application && $application->created_at) {
            $events[] = new StudentHistoryEvent(
                occurredAt: $application->created_at,
                category: 'admission',
                label: 'Admission application',
                description: 'Application '.$application->application_number.' ('.str_replace('_', ' ', (string) $application->status).')',
                reference: $application->application_number,
                referenceId: (int) $application->id,
            );
        }

        $admission = $application?->admission;

        if ($admission) {
            $events[] = new StudentHistoryEvent(
                occurredAt: $admission->admission_date ?? $admission->created_at,
                category: 'admission',
                label: 'Admission confirmed',
                description: 'Admission '.$admission->admission_number.' ('.str_replace('_', ' ', (string) $admission->status).')',
                reference: $admission->admission_number,
                referenceId: (int) $admission->id,
            );
        }
    }

    private function addStudentEvents(Student $student, array &$events): void
    {
        if ($student->created_at) {
            $events[] = new StudentHistoryEvent(
                occurredAt: $student->created_at,
                category: 'student',
                label: 'Student record created',
                description: 'Student number '.$student->student_number.' · status '.str_replace('_', ' ', $student->status),
                reference: $student->student_number,
                referenceId: (int) $student->id,
            );
        }
    }

    private function addEnrollmentEvents(Student $student, array &$events): void
    {
        foreach ($student->enrollments as $enrollment) {
            if (! $enrollment->created_at) {
                continue;
            }

            $parts = array_filter([
                $enrollment->academicYear?->name,
                $enrollment->program?->name,
                $enrollment->section ? 'Section '.$enrollment->section->name : null,
            ]);

            $events[] = new StudentHistoryEvent(
                occurredAt: $enrollment->created_at,
                category: 'enrollment',
                label: 'Enrollment created',
                description: $enrollment->enrollment_number.' · '.implode(' · ', $parts).' · '.str_replace('_', ' ', $enrollment->status),
                reference: $enrollment->enrollment_number,
                referenceId: (int) $enrollment->id,
            );
        }
    }

    private function addAcademicRecordEvents(Student $student, array &$events): void
    {
        foreach ($student->academicRecords as $record) {
            if (! $record->created_at) {
                continue;
            }

            $events[] = new StudentHistoryEvent(
                occurredAt: $record->created_at,
                category: 'academic',
                label: 'Academic record',
                description: $record->periodLabel().' · academic: '.str_replace('_', ' ', $record->academic_status)
                    .' · promotion: '.str_replace('_', ' ', $record->promotion_status)
                    .' · completion: '.str_replace('_', ' ', $record->completion_status),
                reference: $record->periodLabel(),
                referenceId: (int) $record->id,
            );
        }
    }

    private function addPromotionEvents(Student $student, array &$events): void
    {
        foreach ($student->promotions as $promotion) {
            if ($promotion->created_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $promotion->created_at,
                    category: 'promotion',
                    label: 'Promotion requested',
                    description: 'Into academic year #'.$promotion->target_academic_year_id
                        .($promotion->target_section_id ? ' · section #'.$promotion->target_section_id : '')
                        .' · '.$promotion->status,
                    reference: 'PROM-'.$promotion->id,
                    referenceId: (int) $promotion->id,
                );
            }

            if ($promotion->approved_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $promotion->approved_at,
                    category: 'promotion',
                    label: 'Promotion approved',
                    description: $promotion->target_enrollment_id
                        ? 'New enrollment created (#'.$promotion->target_enrollment_id.'); previous enrollment preserved.'
                        : 'Approved.',
                    reference: 'PROM-'.$promotion->id,
                    referenceId: (int) $promotion->id,
                );
            }
        }
    }

    private function addTransferEvents(Student $student, array &$events): void
    {
        foreach ($student->transfers as $transfer) {
            if ($transfer->created_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $transfer->created_at,
                    category: 'transfer',
                    label: 'Transfer / TC requested',
                    description: 'Reason: '.$transfer->reason,
                    reference: 'TC-REQ-'.$transfer->id,
                    referenceId: (int) $transfer->id,
                );
            }

            if ($transfer->approved_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $transfer->approved_at,
                    category: 'transfer',
                    label: 'Transfer '.($transfer->status === 'rejected' ? 'rejected' : 'approved'),
                    description: $transfer->status === 'approved' ? 'Request approved; awaiting TC issuance.' : 'Request rejected.',
                    reference: 'TC-REQ-'.$transfer->id,
                    referenceId: (int) $transfer->id,
                );
            }

            if ($transfer->isTcIssued() && $transfer->tc_issue_date) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $transfer->tc_issue_date,
                    category: 'transfer',
                    label: 'Transfer certificate issued',
                    description: 'TC '.$transfer->tc_number
                        .($transfer->destination_institution ? ' · destination: '.$transfer->destination_institution : ''),
                    reference: $transfer->tc_number,
                    referenceId: (int) $transfer->id,
                );
            }

            if ($transfer->cancelled_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $transfer->cancelled_at,
                    category: 'transfer',
                    label: 'Transfer cancelled',
                    description: 'Request withdrawn; the student record was not affected.',
                    reference: 'TC-REQ-'.$transfer->id,
                    referenceId: (int) $transfer->id,
                );
            }
        }
    }

    private function addDocumentEvents(Student $student, array &$events): void
    {
        foreach ($student->documents as $document) {
            if ($document->created_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $document->created_at,
                    category: 'document',
                    label: 'Document uploaded',
                    description: $document->title.' · '.$document->sizeInKb().' KB · '.str_replace('_', ' ', $document->verification_status),
                    reference: 'DOC-'.$document->id,
                    referenceId: (int) $document->id,
                );
            }

            if ($document->verified_at) {
                $events[] = new StudentHistoryEvent(
                    occurredAt: $document->verified_at,
                    category: 'document',
                    label: $document->isRejected() ? 'Document rejected' : 'Document verified',
                    description: $document->title,
                    reference: 'DOC-'.$document->id,
                    referenceId: (int) $document->id,
                );
            }
        }
    }

    /**
     * Append the audit trail for this student's own record. AuditLog rows are
     * non-updatable and non-deletable, so this part of the history is
     * tamper-evident.
     */
    private function addAuditEvents(Student $student, array &$events): void
    {
        $logs = AuditLog::query()
            ->where('college_id', $student->college_id)
            ->where('subject_type', (new Student)->getMorphClass())
            ->where('subject_id', $student->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($logs as $log) {
            if (! $log->created_at) {
                continue;
            }

            $events[] = new StudentHistoryEvent(
                occurredAt: $log->created_at,
                category: 'audit',
                label: self::AUDIT_LABELS[$log->action] ?? $this->labelForAction($log->action),
                description: $this->describeChanges($log->old_values ?? [], $log->new_values ?? []),
                reference: $log->action,
                referenceId: (int) $log->id,
                source: 'audit',
            );
        }
    }

    /**
     * Compact "field: old → new" summary for an audit pair.
     */
    public function describeChanges(array $old, array $new): string
    {
        $changes = [];

        foreach ($new as $key => $value) {
            if (in_array($key, ['id', 'updated_by', 'created_by'], true)) {
                continue;
            }

            $previous = $old[$key] ?? null;

            if ($previous === $value) {
                continue;
            }

            if ($old === [] && ($value === null || $value === '')) {
                continue;
            }

            $changes[] = $key.': '.$this->display($previous).' → '.$this->display($value);
        }

        if ($changes === []) {
            return 'No field changes recorded.';
        }

        return implode('; ', array_slice($changes, 0, 6)).(count($changes) > 6 ? ' …' : '');
    }

    public function labelForAction(string $action): string
    {
        return self::AUDIT_LABELS[$action] ?? ucfirst(str_replace(['_', '.'], [' ', ' · '], $action));
    }

    public function categoryForAction(string $action): string
    {
        $module = (string) preg_replace('/\..*$/', '', $action);

        return self::ACTION_CATEGORIES[$module] ?? 'student';
    }

    private function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return 'array';
        }

        $text = (string) $value;

        return $text === '' ? '—' : (mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text);
    }
}
