<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\{AcademicTerm, AcademicYear, College};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AcademicTermTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_academic_terms_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('TISA');
        $collegeB = $this->makeCollege('TISB');

        $yearA = AcademicYear::create([
            'college_id' => $collegeA->id,
            'name' => '2026-2027 A',
            'code' => 'AY-A',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $yearB = AcademicYear::create([
            'college_id' => $collegeB->id,
            'name' => '2026-2027 B',
            'code' => 'AY-B',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        AcademicTerm::create([
            'college_id' => $collegeA->id,
            'academic_year_id' => $yearA->id,
            'name' => 'Alpha Term',
            'code' => 'AT',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);
        AcademicTerm::create([
            'college_id' => $collegeB->id,
            'academic_year_id' => $yearB->id,
            'name' => 'Bravo Term',
            'code' => 'AT',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['academic_terms.view']);

        $this->asCollege($collegeA, $adminA)->get(route('academic-terms.index'))
            ->assertSee('Alpha Term')
            ->assertDontSee('Bravo Term');
    }

    public function test_cross_college_academic_year_is_rejected(): void
    {
        $collegeA = $this->makeCollege('TAYA');
        $collegeB = $this->makeCollege('TAYB');

        $yearB = AcademicYear::create([
            'college_id' => $collegeB->id,
            'name' => '2026-2027 B',
            'code' => 'AY-B',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['academic_terms.view', 'academic_terms.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('academic-terms.store'), [
                'academic_year_id' => $yearB->id,
                'name' => 'Illegal Term',
                'code' => 'ILL',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'active',
            ], ['Referer' => route('academic-terms.index')])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertDatabaseMissing('academic_terms', ['code' => 'ILL']);
    }

    public function test_cross_college_academic_terms_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('TOCA');
        $collegeB = $this->makeCollege('TOCB');

        $yearB = AcademicYear::create([
            'college_id' => $collegeB->id,
            'name' => '2026-2027 B',
            'code' => 'AY-B',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $foreign = AcademicTerm::create([
            'college_id' => $collegeB->id,
            'academic_year_id' => $yearB->id,
            'name' => 'Foreign Term',
            'code' => 'FT',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['academic_terms.view', 'academic_terms.update', 'academic_terms.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('academic-terms.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('academic-terms.update', $foreign), [
            'academic_year_id' => $yearB->id,
            'name' => 'Hijacked Term',
            'code' => 'FT',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ], ['Referer' => route('academic-terms.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('academic-terms.destroy', $foreign), [], ['Referer' => route('academic-terms.index')])->assertNotFound();

        $this->assertSame('Foreign Term', $foreign->fresh()->name);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('TSPA');
        $collegeB = $this->makeCollege('TSPB');

        $yearA = AcademicYear::create([
            'college_id' => $collegeA->id,
            'name' => '2026-2027 A',
            'code' => 'AY-A',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['academic_terms.view', 'academic_terms.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('academic-terms.store'), [
                'college_id' => $collegeB->id,
                'academic_year_id' => $yearA->id,
                'name' => 'Sneaky Term',
                'code' => 'SNK-T',
                'type' => 'semester',
                'sequence' => 1,
                'status' => 'active',
            ], ['Referer' => route('academic-terms.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('academic_terms', ['college_id' => $collegeA->id, 'code' => 'SNK-T']);
        $this->assertDatabaseMissing('academic_terms', ['college_id' => $collegeB->id, 'code' => 'SNK-T']);
    }
}
