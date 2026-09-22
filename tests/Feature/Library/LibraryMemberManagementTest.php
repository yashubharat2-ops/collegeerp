<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Services\LibraryMemberService;
use App\Models\AuditLog;
use App\Models\LibraryMember;
use App\Models\StudentEnrollment;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Library Management — Library Members.
 *
 * A member is a membership of an existing student enrollment, not a second
 * person record. These tests cover that reference, the one-active-membership
 * rule, member-code uniqueness, deletion when history exists, tenant
 * isolation, RBAC and audit.
 */
class LibraryMemberManagementTest extends TestCase
{
    use LibraryTestHelpers;

    private const MANAGE = ['library_members.view', 'library_members.create', 'library_members.update', 'library_members.delete'];

    public function test_a_membership_references_an_existing_enrollment_and_does_not_copy_the_student(): void
    {
        $college = $this->makeCollege('LMB1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        [$student, $enrollment] = $this->makeLibraryEnrollment($college, [
            'first_name' => 'Asha',
            'last_name' => 'Nair',
            'student_number' => 'STU-ASHA',
        ]);

        $response = $this->asCollege($college, $user)->post(route('library-members.store'), [
            'student_enrollment_id' => $enrollment->id,
            'member_code' => 'lm-0001',
            'membership_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => LibraryMember::STATUS_ACTIVE,
            'remarks' => 'First year.',
            'college_id' => 999,
            'created_by' => 999,
            'first_name' => 'Forged',
        ]);

        $member = $this->withTenant($college, fn () => LibraryMember::query()->where('member_code', 'LM-0001')->firstOrFail());

        $response->assertRedirect(route('library-members.show', $member))->assertSessionHasNoErrors();

        $this->assertSame($college->id, $member->college_id);
        $this->assertSame($user->id, $member->created_by);
        $this->assertSame($enrollment->id, $member->student_enrollment_id);
        $this->assertSame('LM-0001', $member->member_code);
        $this->assertFalse(in_array('first_name', $member->getFillable(), true));
        $this->withTenant($college, function () use ($member): void {
            $member->load('studentEnrollment.student');
            $this->assertSame('Asha Nair', $member->studentName());
        });

        $this->asCollege($college, $user)
            ->get(route('library-members.show', $member))
            ->assertOk()
            ->assertSee('LM-0001')
            ->assertSee('Asha Nair')
            ->assertSee('STU-ASHA')
            ->assertSee($enrollment->enrollment_number);

        $this->assertSame('Asha', $student->fresh()->first_name, 'Creating a membership must not rewrite the student.');
    }

    public function test_an_enrollment_cannot_have_two_active_memberships_and_codes_are_unique_per_college(): void
    {
        $college = $this->makeCollege('LMB2');
        $other = $this->makeCollege('LMB2B');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $otherUser = $this->makeUserWithPermissions($other, self::MANAGE);
        [, $enrollment] = $this->makeLibraryEnrollment($college);
        [, $otherEnrollment] = $this->makeLibraryEnrollment($other);

        $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-DUP', 'status' => LibraryMember::STATUS_ACTIVE]);

        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, [
                'member_code' => 'LM-OTHER',
                'status' => LibraryMember::STATUS_ACTIVE,
            ]))
            ->assertSessionHasErrors('student_enrollment_id');

        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, [
                'member_code' => 'lm-dup',
                'status' => LibraryMember::STATUS_SUSPENDED,
            ]))
            ->assertSessionHasErrors('member_code');

        // A second, non-active membership of the same enrollment is allowed.
        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, [
                'member_code' => 'LM-OLD',
                'status' => LibraryMember::STATUS_INACTIVE,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Another college may reuse the code and is not affected by this enrollment.
        $this->asCollege($other, $otherUser)
            ->post(route('library-members.store'), $this->memberPayload($otherEnrollment, [
                'member_code' => 'LM-DUP',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $inactive = $this->withTenant($college, fn () => LibraryMember::query()->where('member_code', 'LM-OLD')->firstOrFail());

        $this->from(route('library-members.edit', $inactive))
            ->asCollege($college, $user)
            ->put(route('library-members.update', $inactive), [
                'member_code' => 'LM-OLD',
                'membership_date' => now()->toDateString(),
                'status' => LibraryMember::STATUS_ACTIVE,
            ])
            ->assertSessionHasErrors('student_enrollment_id');

        $this->assertSame(LibraryMember::STATUS_INACTIVE, $inactive->fresh()->status);
    }

    public function test_a_foreign_or_invalid_enrollment_is_rejected_and_the_link_cannot_be_retargeted(): void
    {
        $college = $this->makeCollege('LMB3');
        $other = $this->makeCollege('LMB3B');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        [, $enrollment] = $this->makeLibraryEnrollment($college);
        [, $foreign] = $this->makeLibraryEnrollment($other);
        $member = $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-KEEP']);

        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, [
                'student_enrollment_id' => $foreign->id,
                'member_code' => 'LM-FOREIGN',
            ]))
            ->assertSessionHasErrors('student_enrollment_id');

        $this->assertSame(0, LibraryMember::withTrashed()->where('member_code', 'LM-FOREIGN')->count());

        $this->withTenant($college, function () use ($college, $user, $foreign): void {
            try {
                app(LibraryMemberService::class)->create($college, [
                    'student_enrollment_id' => $foreign->id,
                    'member_code' => 'LM-SVC',
                    'membership_date' => now()->toDateString(),
                    'status' => LibraryMember::STATUS_ACTIVE,
                ], $user);
                $this->fail('A cross-tenant enrollment must be rejected by the service.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('student_enrollment_id', $e->errors());
            }
        });

        [, $second] = $this->makeLibraryEnrollment($college);
        $this->asCollege($college, $user)
            ->put(route('library-members.update', $member), [
                'member_code' => 'LM-KEEP',
                'membership_date' => now()->toDateString(),
                'status' => LibraryMember::STATUS_SUSPENDED,
                'student_enrollment_id' => $second->id,
                'remarks' => 'Card replaced.',
            ])
            ->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame($enrollment->id, $member->student_enrollment_id, 'The enrollment link is frozen.');
        $this->assertSame(LibraryMember::STATUS_SUSPENDED, $member->status);
        $this->assertSame('Card replaced.', $member->remarks);

        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, [
                'member_code' => 'LM-DATES',
                'membership_date' => now()->toDateString(),
                'expiry_date' => now()->subDay()->toDateString(),
                'status' => LibraryMember::STATUS_INACTIVE,
            ]))
            ->assertSessionHasErrors('expiry_date');
    }

    public function test_a_member_with_circulation_history_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('LMB4');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $clean = $this->makeLibraryMember($college, null, ['member_code' => 'LM-CLEAN']);
        $used = $this->makeLibraryMember($college, null, ['member_code' => 'LM-USED']);
        $this->makeIssuedTransaction($college, null, $used);

        $this->from(route('library-members.show', $used))
            ->asCollege($college, $user)
            ->delete(route('library-members.destroy', $used))
            ->assertRedirect(route('library-members.show', $used))
            ->assertSessionHasErrors('member');

        $this->assertNull($used->fresh()->deleted_at);
        $this->assertDatabaseHas('library_transactions', ['library_member_id' => $used->id]);

        $this->asCollege($college, $user)
            ->delete(route('library-members.destroy', $clean))
            ->assertRedirect(route('library-members.index'));

        $this->assertSoftDeleted('library_members', ['id' => $clean->id]);
    }

    public function test_members_are_tenant_isolated_permission_gated_and_ordered(): void
    {
        $college = $this->makeCollege('LMB5');
        $other = $this->makeCollege('LMB5B');
        $viewer = $this->makeUserWithPermissions($college, ['library_members.view']);
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $otherUser = $this->makeUserWithPermissions($other, self::MANAGE);
        [, $enrollment] = $this->makeLibraryEnrollment($college, ['first_name' => 'Meera', 'last_name' => 'Iyer']);
        $member = $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-ZED']);
        $this->makeLibraryMember($college, null, ['member_code' => 'LM-AAA']);
        $hidden = $this->makeLibraryMember($other, null, ['member_code' => 'LM-HIDDEN']);

        $this->asCollege($college, $viewer)
            ->get(route('library-members.index'))
            ->assertOk()
            ->assertSeeInOrder(['LM-AAA', 'LM-ZED'])
            ->assertDontSee('LM-HIDDEN')
            ->assertSee('Meera Iyer');

        $this->asCollege($college, $viewer)
            ->get(route('library-members.index', ['search' => 'Meera', 'status' => LibraryMember::STATUS_ACTIVE]))
            ->assertOk()
            ->assertSee('LM-ZED')
            ->assertDontSee('LM-AAA');

        $this->asCollege($college, $viewer)->get(route('library-members.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('library-members.store'), $this->memberPayload($enrollment, ['member_code' => 'LM-NOPE']))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('library-members.index'))->assertForbidden();
        $this->asCollege($other, $otherUser)->get(route('library-members.show', $member))->assertNotFound();
        $this->asCollege($other, $otherUser)->put(route('library-members.update', $member), [
            'member_code' => 'STOLEN',
            'membership_date' => now()->toDateString(),
            'status' => LibraryMember::STATUS_SUSPENDED,
        ])->assertNotFound();

        $this->assertSame('LM-ZED', $member->fresh()->member_code);
    }

    public function test_member_mutations_are_audited(): void
    {
        $college = $this->makeCollege('LMB6');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        [, $enrollment] = $this->makeLibraryEnrollment($college);

        $this->asCollege($college, $user)
            ->post(route('library-members.store'), $this->memberPayload($enrollment, ['member_code' => 'LM-AUD']))
            ->assertRedirect();

        $member = $this->withTenant($college, fn () => LibraryMember::query()->where('member_code', 'LM-AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('library-members.update', $member), [
            'member_code' => 'LM-AUD',
            'membership_date' => now()->toDateString(),
            'status' => LibraryMember::STATUS_SUSPENDED,
            'remarks' => 'Hold.',
        ])->assertRedirect();

        $this->asCollege($college, $user)->delete(route('library-members.destroy', $member))->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', LibraryMember::class)->where('subject_id', $member->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['library_members.created', 'library_members.updated', 'library_members.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'library_members.created')->where('subject_id', $member->id)->firstOrFail();
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame($enrollment->id, $created->new_values['student_enrollment_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function memberPayload(StudentEnrollment $enrollment, array $overrides = []): array
    {
        return array_merge([
            'student_enrollment_id' => $enrollment->id,
            'member_code' => 'LM-'.strtoupper(substr(uniqid(), -6)),
            'membership_date' => now()->toDateString(),
            'expiry_date' => null,
            'status' => LibraryMember::STATUS_ACTIVE,
            'remarks' => null,
        ], $overrides);
    }
}
