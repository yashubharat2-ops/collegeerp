<?php

namespace Tests\Feature\Platform;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AcademicYearConstraintTest extends TestCase
{
    public function test_overlapping_academic_year_is_rejected(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        $this->actingAs($user)->post('/academic-years', ['name' => '2026-27', 'code' => 'AY26', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active'])->assertRedirect();
        $this->actingAs($user)->post('/academic-years', ['name' => 'Overlap', 'code' => 'OVERLAP', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'inactive'])->assertSessionHasErrors('starts_on');
        $this->assertSame(1, AcademicYear::count());
    }

    public function test_academic_year_model_rejects_invalid_dates(): void
    {
        $college = College::firstOrFail();
        $user = User::where('email', 'test@example.com')->firstOrFail();

        app(TenantContext::class)->set($college);

        $this->expectException(ValidationException::class);

        AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Invalid Year',
            'code' => 'INVALID',
            'starts_on' => '2027-03-31',
            'ends_on' => '2026-04-01',
            'status' => 'inactive',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_academic_year_model_rejects_equal_dates(): void
    {
        $college = College::firstOrFail();
        $user = User::where('email', 'test@example.com')->firstOrFail();

        app(TenantContext::class)->set($college);

        $this->expectException(ValidationException::class);

        AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Equal Dates',
            'code' => 'EQUAL',
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-04-01',
            'status' => 'inactive',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_academic_year_request_validation_rejects_ends_before_starts(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->actingAs($user)->post('/academic-years', [
            'name' => 'Invalid Request',
            'code' => 'BADREQ',
            'starts_on' => '2026-12-31',
            'ends_on' => '2026-01-01',
            'status' => 'active',
        ])->assertSessionHasErrors('ends_on');

        $this->assertSame(0, AcademicYear::count());
    }

    public function test_academic_year_valid_dates_are_persisted(): void
    {
        $college = College::firstOrFail();
        $user = User::where('email', 'test@example.com')->firstOrFail();

        app(TenantContext::class)->set($college);

        $academicYear = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-27 Valid',
            'code' => 'AY26VALID',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->assertDatabaseHas('academic_years', [
            'id' => $academicYear->id,
            'code' => 'AY26VALID',
        ]);

        // Database-level CHECK constraint is enforced for supported drivers.
        // Keep SQLite testing compatibility by skipping raw DB violation test on sqlite.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertTrue(true, 'SQLite testing compatibility preserved; model-level invariant covers starts_on < ends_on.');
            return;
        }

        // For MySQL, MariaDB, PostgreSQL, SQL Server: verify DB-level CHECK rejects invalid dates.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('academic_years')->insert([
            'college_id' => $college->id,
            'name' => 'DB Invalid',
            'code' => 'DBBAD',
            'starts_on' => '2027-03-31',
            'ends_on' => '2026-04-01',
            'status' => 'inactive',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_academic_year_database_check_constraint_exists_for_supported_drivers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('SQLite testing compatibility: DB-level CHECK is covered by model invariant.');
        }

        $college = College::firstOrFail();
        $user = User::where('email', 'test@example.com')->firstOrFail();

        app(TenantContext::class)->set($college);

        // Bypass model invariant with raw query to directly test database CHECK.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('academic_years')->insert([
            'college_id' => $college->id,
            'name' => 'Constraint Check',
            'code' => 'CHKTEST',
            'starts_on' => '2027-03-31',
            'ends_on' => '2026-04-01',
            'status' => 'inactive',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
