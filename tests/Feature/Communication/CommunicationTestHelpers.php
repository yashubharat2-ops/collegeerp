<?php

namespace Tests\Feature\Communication;

use App\Models\Circular;
use App\Models\College;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\CommunicationTemplate;
use App\Models\Faculty;
use App\Models\Notice;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for the Communication Management (Phase 1) tests.
 *
 * Reuses the project-wide fixtures (college, RBAC users, super admin,
 * asCollege) and adds only what the Communication module owns. Fixtures are
 * created directly (not through HTTP) with an explicit college_id so each test
 * exercises one behaviour.
 */
trait CommunicationTestHelpers
{
    use ExamAttendanceTestHelpers;

    /** Every Communication Phase 1 permission slug. */
    private const COMMUNICATION_PERMISSIONS = [
        'communication_dashboard.view',
        'notices.view', 'notices.create', 'notices.update', 'notices.delete', 'notices.publish',
        'circulars.view', 'circulars.create', 'circulars.update', 'circulars.delete', 'circulars.publish',
        'notifications.view', 'notifications.create', 'notifications.update', 'notifications.delete',
    ];

    private const NOTICE_PERMISSIONS = ['notices.view', 'notices.create', 'notices.update', 'notices.delete', 'notices.publish'];

    private const CIRCULAR_PERMISSIONS = ['circulars.view', 'circulars.create', 'circulars.update', 'circulars.delete', 'circulars.publish'];

    private const NOTIFICATION_PERMISSIONS = ['notifications.view', 'notifications.create', 'notifications.update', 'notifications.delete'];

    /** Every Communication Phase 2 permission slug (templates, logs, tracking, reports). */
    private const COMMUNICATION_PHASE2_PERMISSIONS = [
        'communication_templates.view', 'communication_templates.create', 'communication_templates.update', 'communication_templates.delete',
        'communication_logs.view',
        'communication_tracking.view',
        'communication_reports.view',
    ];

    private const TEMPLATE_PERMISSIONS = [
        'communication_templates.view', 'communication_templates.create', 'communication_templates.update', 'communication_templates.delete',
    ];

    /**
     * Run a callback with the tenant context bound to $college (CollegeScope
     * resolves to "1 = 0" outside a request otherwise).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeNotice(College $college, array $overrides = []): Notice
    {
        return $this->forceCreate(new Notice, array_merge([
            'college_id' => $college->id,
            'title' => 'Notice '.Str::upper(Str::random(6)),
            'slug' => 'notice-'.Str::lower(Str::random(12)),
            'notice_type' => 'general',
            'content' => 'Notice body text.',
            'publish_at' => now()->subHour(),
            'expires_at' => null,
            'status' => 'draft',
            'priority' => 'normal',
            'target_type' => 'all',
            'target_id' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCircular(College $college, array $overrides = []): Circular
    {
        return $this->forceCreate(new Circular, array_merge([
            'college_id' => $college->id,
            'circular_number' => 'CIR/'.Str::upper(Str::random(6)),
            'title' => 'Circular '.Str::upper(Str::random(6)),
            'subject' => 'Subject line',
            'content' => 'Circular body text.',
            'issue_date' => now()->toDateString(),
            'publish_at' => null,
            'expires_at' => null,
            'status' => 'draft',
            'target_type' => 'all',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeNotification(College $college, string $recipientType, int $recipientId, array $overrides = []): CommunicationNotification
    {
        return $this->forceCreate(new CommunicationNotification, array_merge([
            'college_id' => $college->id,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'title' => 'Notification '.Str::upper(Str::random(6)),
            'message' => 'Notification message.',
            'notification_type' => 'general',
            'priority' => 'normal',
            'read_at' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTemplate(College $college, array $overrides = []): CommunicationTemplate
    {
        return $this->forceCreate(new CommunicationTemplate, array_merge([
            'college_id' => $college->id,
            'name' => 'Template '.Str::upper(Str::random(6)),
            'code' => 'TPL'.Str::upper(Str::random(6)),
            'channel' => 'email',
            'subject' => 'Subject line',
            'body' => 'Dear {{ name }}, this is a reusable message.',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLog(College $college, array $overrides = []): CommunicationLog
    {
        return $this->forceCreate(new CommunicationLog, array_merge([
            'college_id' => $college->id,
            'channel' => 'email',
            'recipient' => Str::lower(Str::random(8)).'@example.test',
            'subject' => 'Subject line',
            'content' => 'Message content.',
            'status' => 'queued',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function templatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fee reminder',
            'code' => 'FEE_REMINDER',
            'channel' => 'email',
            'subject' => 'Your fee instalment is due',
            'body' => 'Dear {{ student_name }}, your instalment is due on {{ due_date }}.',
            'status' => 'active',
        ], $overrides);
    }

    /**
     * Persist a fixture with every attribute force-filled, so tests may also
     * pin created_at (kept by Eloquent when explicitly set).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function forceCreate(Model $model, array $attributes): Model
    {
        $model->forceFill($attributes)->save();

        return $model;
    }

    private function makeStudent(College $college, string $firstName = 'Asha'): Student
    {
        return Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-'.Str::upper(Str::random(6)),
            'first_name' => $firstName,
            'last_name' => 'Learner',
            'status' => 'active',
        ]);
    }

    private function makeStaff(College $college, string $firstName = 'Ravi'): Faculty
    {
        return Faculty::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'employee_code' => 'EMP-'.Str::upper(Str::random(6)),
            'first_name' => $firstName,
            'last_name' => 'Teacher',
            'status' => 'active',
        ]);
    }

    private function makeProgram(College $college, string $name = 'B.Sc Physics'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => $name,
            'code' => 'P-'.Str::upper(Str::random(5)),
            'status' => 'active',
        ]);
    }

    /** A plain active member of $college with no roles. */
    private function makeMember(College $college, string $name = 'Member User'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::lower(Str::random(10)).'@member.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function noticePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Campus closed on Friday',
            'notice_type' => 'administrative',
            'content' => "The campus will remain closed on Friday.\nClasses resume on Monday.",
            'publish_at' => now()->subMinutes(5)->format('Y-m-d\TH:i'),
            'expires_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
            'priority' => 'important',
            'target_type' => 'all',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function circularPayload(array $overrides = []): array
    {
        return array_merge([
            'circular_number' => 'CIR/2026/001',
            'title' => 'Revised examination timetable',
            'subject' => 'Changes to the mid-term schedule',
            'content' => 'The mid-term examinations are rescheduled.',
            'issue_date' => now()->toDateString(),
            'target_type' => 'students',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function notificationPayload(string $recipientType, int $recipientId, array $overrides = []): array
    {
        return array_merge([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'title' => 'Fee receipt available',
            'message' => 'Your fee receipt can be collected from the office.',
            'notification_type' => 'reminder',
            'priority' => 'normal',
        ], $overrides);
    }
}
