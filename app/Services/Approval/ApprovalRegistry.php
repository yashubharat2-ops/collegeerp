<?php

namespace App\Services\Approval;

/**
 * Central allowlist of modules and actions that MAY require approval.
 *
 * Keeps references safe: target_type never comes from the browser as an
 * arbitrary class, only from this map. Disabling approval does not silently
 * invalidate existing pending requests; they remain pending and decidable.
 */
final class ApprovalRegistry
{
    /**
     * module => [action => [label, permission_approve, modelClass, description]]
     */
    public const MODULES = [
        'admission' => [
            'application_submit' => [
                'label' => 'New Admission Application',
                'permission_approve' => 'approvals.approve',
                'model' => \App\Models\AdmissionApplication::class,
                'description' => 'A new admission application requires approval before proceeding.',
            ],
            'application_status_change' => [
                'label' => 'Application Status Change',
                'permission_approve' => 'approvals.approve',
                'model' => \App\Models\AdmissionApplication::class,
                'description' => 'Changing application status requires approval.',
            ],
            // NOTE: an `admission_create` action used to be declared here. It
            // was removed because nothing ever called submitRequest() for it and
            // applyApproval() handled it with an empty "not needed yet" branch —
            // yet Approval Settings still rendered a live toggle for it, so an
            // administrator could enable "Create Admission requires approval"
            // and nothing would change. Admission confirmation is now gated by
            // AdmissionService::ADMITTABLE_STATUSES (confirmation follows
            // approval) and, in the unified registration workflow, by the single
            // `student.registration_approval` decision. Removal is safe: no
            // caller and no test ever created such a request, so none can be
            // orphaned, and ApprovalController@updateSettings() already skips
            // unsupported module/action pairs.
        ],
        'student' => [
            'student_data_change' => [
                'label' => 'Sensitive Student Data Change',
                'permission_approve' => 'students.update',
                'model' => \App\Models\Student::class,
                'description' => 'Sensitive student data changes require approval.',
            ],
            'student_transfer' => [
                'label' => 'Student Transfer',
                'permission_approve' => 'student_transfers.approve',
                'model' => \App\Models\StudentTransfer::class,
                'description' => 'Student transfer requests require approval.',
            ],
        ],
        'fees' => [
            'fee_concession' => [
                'label' => 'Fee Concession',
                'permission_approve' => 'fee_concessions.approve',
                'model' => \App\Models\FeeConcession::class,
                'description' => 'Fee concessions require approval.',
            ],
            'fee_refund' => [
                'label' => 'Fee Refund',
                'permission_approve' => 'refunds.approve',
                'model' => \App\Models\FeeRefund::class,
                'description' => 'Fee refunds require approval.',
            ],
        ],
        'certificates' => [
            'certificate_issue' => [
                'label' => 'Certificate Issuance',
                'permission_approve' => 'certificates.issue',
                'model' => \App\Models\Certificate::class,
                'description' => 'Certificate issuance requires approval.',
            ],
        ],
        'academics' => [
            'timetable_change' => [
                'label' => 'Timetable Change',
                'permission_approve' => 'academic_timetables.update',
                'model' => \App\Models\AcademicTimetable::class,
                'description' => 'Timetable changes require approval.',
            ],
        ],
        'library' => [
            'book_issue' => [
                'label' => 'Library Book Issue',
                'permission_approve' => 'library_transactions.create',
                'model' => \App\Models\LibraryTransaction::class,
                'description' => 'Library transactions may require approval.',
            ],
        ],
    ];

    public static function isSupported(string $module, string $action): bool
    {
        return isset(self::MODULES[$module][$action]);
    }

    public static function allSupported(): array
    {
        $out = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action => $cfg) {
                $out[] = ['module' => $module, 'action' => $action, 'label' => $cfg['label'], 'permission' => $cfg['permission_approve']];
            }
        }
        return $out;
    }

    public static function labelFor(string $module, string $action): ?string
    {
        return self::MODULES[$module][$action]['label'] ?? null;
    }

    public static function permissionFor(string $module, string $action): ?string
    {
        return self::MODULES[$module][$action]['permission_approve'] ?? null;
    }

    public static function modelClassForTarget(string $targetType): ?string
    {
        // target_type is stored as "module.action" key or model class alias
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action => $cfg) {
                $key = $module . '.' . $action;
                if ($key === $targetType) {
                    return $cfg['model'];
                }
                if ($cfg['model'] === $targetType) {
                    return $cfg['model'];
                }
            }
        }
        return null;
    }

    public static function modelClassFor(string $moduleAction): ?string
    {
        if (str_contains($moduleAction, '.')) {
            [$module, $action] = explode('.', $moduleAction, 2);
            return self::MODULES[$module][$action]['model'] ?? null;
        }
        // Also allow direct class name if validated
        foreach (self::MODULES as $actions) {
            foreach ($actions as $cfg) {
                if ($cfg['model'] === $moduleAction) {
                    return $cfg['model'];
                }
            }
        }
        return null;
    }

    public static function modulesList(): array
    {
        return array_keys(self::MODULES);
    }

    public static function actionsFor(string $module): array
    {
        return array_keys(self::MODULES[$module] ?? []);
    }
}
