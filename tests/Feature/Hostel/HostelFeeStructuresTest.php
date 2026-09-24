<?php

namespace Tests\Feature\Hostel;

use App\Models\AcademicYear;
use App\Models\HostelFeeStructure;
use Illuminate\Support\Str;
use Tests\TestCase;

class HostelFeeStructuresTest extends TestCase
{
    use HostelTestHelpers;

    private function year(\App\Models\College $college): AcademicYear
    {
        return AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Year '.Str::upper(Str::random(4)),
            'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
    }

    public function test_fee_structure_crud_and_tenant_isolation(): void
    {
        $college = $this->makeCollege('HFS01');
        $other = $this->makeCollege('HFS01X');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create', 'hostel_fees.update', 'hostel_fees.delete']);
        $year = $this->year($college);

        $this->asCollege($college, $user)
            ->post(route('hostel-fee-structures.store'), [
                'academic_year_id' => $year->id,
                'name' => 'Hostel Annual Fee',
                'code' => 'haf',
                'amount' => 12000,
                'frequency' => 'yearly',
                'status' => 'active',
                'description' => 'Annual hostel fee',
            ])
            ->assertRedirect(route('hostel-fee-structures.index'));

        $structure = $this->withTenant($college, fn () => HostelFeeStructure::firstOrFail());
        $this->assertEquals('HAF', $structure->code, 'Code must be upper-cased.');
        $this->assertEquals($college->id, $structure->college_id);

        $this->asCollege($college, $user)->get(route('hostel-fee-structures.index'))->assertOk()->assertSee('HAF');

        // Other college cannot see
        $otherUser = $this->makeUserWithPermissions($other, ['hostel_fees.view']);
        $this->asCollege($other, $otherUser)->get(route('hostel-fee-structures.index'))->assertOk()->assertDontSee('HAF');
        $this->asCollege($other, $otherUser)->get(route('hostel-fee-structures.edit', $structure))->assertNotFound();

        // Update
        $this->asCollege($college, $user)->put(route('hostel-fee-structures.update', $structure), [
            'academic_year_id' => $year->id,
            'name' => 'Hostel Annual Fee Updated',
            'code' => 'HAF',
            'amount' => 13000,
            'frequency' => 'yearly',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertEquals(13000, (float) $this->withTenant($college, fn () => HostelFeeStructure::firstOrFail()->amount));

        // Delete
        $this->asCollege($college, $user)->delete(route('hostel-fee-structures.destroy', $structure))->assertRedirect();
        $this->assertSoftDeleted('hostel_fee_structures', ['id' => $structure->id]);
    }

    public function test_code_stays_reserved_after_soft_delete(): void
    {
        $college = $this->makeCollege('HFS02');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create', 'hostel_fees.delete']);
        $year = $this->year($college);

        $structure = $this->makeHostelFeeStructure($college, $year, ['code' => 'RSVD']);

        $this->asCollege($college, $user)->delete(route('hostel-fee-structures.destroy', $structure))->assertRedirect();

        $this->asCollege($college, $user)->post(route('hostel-fee-structures.store'), [
            'academic_year_id' => $year->id,
            'name' => 'Duplicate',
            'code' => 'RSVD',
            'amount' => 5000,
            'status' => 'active',
        ])->assertSessionHasErrors('code');
    }

    public function test_rbac(): void
    {
        $college = $this->makeCollege('HFS03');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_fees.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $year = $this->year($college);
        $structure = $this->makeHostelFeeStructure($college, $year);

        $this->asCollege($college, $nobody)->get(route('hostel-fee-structures.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-fee-structures.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-fee-structures.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-fee-structures.store'), [])->assertForbidden();
    }
}
