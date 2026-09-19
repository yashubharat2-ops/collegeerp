<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\{AcademicTerm, AcademicYear, AuditLog};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AcademicTermManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_academic_term_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('ATM');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.create']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('academic-terms.store'), [
                'academic_year_id' => $year->id,
                'name' => 'Semester 1',
                'code' => 'SEM-1',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'active',
                'description' => 'First semester',
            ], ['Referer' => route('academic-terms.index')])
            ->assertRedirect(route('academic-terms.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('academic_terms', [
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Semester 1',
            'code' => 'SEM-1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);
    }

    public function test_validation_rejects_missing_fields_and_invalid_data(): void
    {
        $college = $this->makeCollege('ATV');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.create']);

        $this->asCollege($college, $admin)
            ->post(route('academic-terms.store'), [
                'academic_year_id' => '',
                'name' => '',
                'code' => '',
                'type' => 'invalid_type',
                'sequence' => -1,
                'status' => 'archived',
            ], ['Referer' => route('academic-terms.index')])
            ->assertSessionHasErrors(['academic_year_id', 'name', 'code', 'type', 'sequence', 'status']);

        $this->assertSame(0, AcademicTerm::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_code_in_same_year_is_blocked_but_allowed_in_different_year(): void
    {
        $college = $this->makeCollege('ATD');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.create', 'academic_terms.update']);
        $year1 = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $year2 = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2027-2028',
            'code' => 'AY-2027',
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
            'status' => 'inactive',
        ]);

        AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year1->id,
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        // Duplicate in same year: blocked
        $this->asCollege($college, $admin)
            ->post(route('academic-terms.store'), [
                'academic_year_id' => $year1->id,
                'name' => 'Semester 1 Duplicate',
                'code' => 'SEM1',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'active',
            ], ['Referer' => route('academic-terms.index')])
            ->assertSessionHasErrors('code');

        // Same code in different year: allowed
        $this->asCollege($college, $admin)
            ->post(route('academic-terms.store'), [
                'academic_year_id' => $year2->id,
                'name' => 'Semester 1 Next Year',
                'code' => 'SEM1',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'active',
            ], ['Referer' => route('academic-terms.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, AcademicTerm::withoutGlobalScopes()->where('college_id', $college->id)->where('code', 'SEM1')->count());

        // Update own record retains same code
        $term = AcademicTerm::withoutGlobalScopes()->where('academic_year_id', $year1->id)->where('code', 'SEM1')->firstOrFail();
        $this->asCollege($college, $admin)
            ->put(route('academic-terms.update', $term), [
                'academic_year_id' => $year1->id,
                'name' => 'Semester 1 Updated',
                'code' => 'SEM1',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'inactive',
            ], ['Referer' => route('academic-terms.edit', $term)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('inactive', $term->fresh()->status);
    }

    public function test_admin_can_update_and_soft_delete_academic_term(): void
    {
        $college = $this->makeCollege('ATU');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.update', 'academic_terms.delete']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Term 1',
            'code' => 'T1',
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('academic-terms.update', $term), [
                'academic_year_id' => $year->id,
                'name' => 'Trimester 1',
                'code' => 'TRI-1',
                'type' => 'trimester',
                'sequence' => 1,
                'status' => 'active',
                'description' => 'Updated desc',
            ], ['Referer' => route('academic-terms.edit', $term)])
            ->assertSessionHas('success');

        $term->refresh();
        $this->assertSame('Trimester 1', $term->name);
        $this->assertSame('TRI-1', $term->code);
        $this->assertSame('trimester', $term->type);
        $this->assertSame('Updated desc', $term->description);

        $this->asCollege($college, $admin)
            ->delete(route('academic-terms.destroy', $term), [], ['Referer' => route('academic-terms.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('academic_terms', ['id' => $term->id]);
        $this->asCollege($college, $admin)->get(route('academic-terms.index'))->assertDontSee('Trimester 1');
    }

    public function test_index_supports_search_type_status_filters_and_pagination(): void
    {
        $college = $this->makeCollege('ATF');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        foreach (range(1, 16) as $i) {
            AcademicTerm::create([
                'college_id' => $college->id,
                'academic_year_id' => $year->id,
                'name' => sprintf('Semester %02d', $i),
                'code' => sprintf('SEM%02d', $i),
                'type' => 'semester',
                'sequence' => $i,
                'status' => $i === 16 ? 'inactive' : 'active',
            ]);
        }

        $this->asCollege($college, $admin)->get(route('academic-terms.index'))
            ->assertSee('Semester 01')
            ->assertSee('Semester 15')
            ->assertDontSee('Semester 16')
            ->assertSee('Showing 1–15 of 16 terms.');

        $this->asCollege($college, $admin)->get(route('academic-terms.index', ['page' => 2]))
            ->assertSee('Semester 16')
            ->assertDontSee('Semester 01');

        $this->asCollege($college, $admin)->get(route('academic-terms.index', ['search' => 'SEM07']))
            ->assertSee('Semester 07');

        $this->asCollege($college, $admin)->get(route('academic-terms.index', ['status' => 'inactive']))
            ->assertSee('Semester 16')
            ->assertDontSee('Semester 01');
    }

    public function test_audit_logs_record_academic_term_actions(): void
    {
        $college = $this->makeCollege('ATA');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.create', 'academic_terms.update', 'academic_terms.delete']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)->post(route('academic-terms.store'), [
            'academic_year_id' => $year->id,
            'name' => 'Audit Term',
            'code' => 'AUD-T',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ], ['Referer' => route('academic-terms.index')])->assertSessionHasNoErrors();

        $term = AcademicTerm::withoutGlobalScopes()->where('code', 'AUD-T')->firstOrFail();

        $this->asCollege($college, $admin)->put(route('academic-terms.update', $term), [
            'academic_year_id' => $year->id,
            'name' => 'Audit Term Renamed',
            'code' => 'AUD-T',
            'type' => 'semester',
            'sequence' => 2,
            'status' => 'active',
        ], ['Referer' => route('academic-terms.edit', $term)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('academic-terms.destroy', $term), [], ['Referer' => route('academic-terms.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => AcademicTerm::class,
            'subject_id' => $term->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'academic_term.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'academic_term.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'academic_term.deleted']);

        $updated = AuditLog::where($base + ['action' => 'academic_term.updated'])->firstOrFail();
        $this->assertSame('Audit Term', $updated->old_values['name']);
        $this->assertSame('Audit Term Renamed', $updated->new_values['name']);
        $this->assertSame(1, $updated->old_values['sequence']);
        $this->assertSame(2, $updated->new_values['sequence']);
    }
}
