<?php

namespace Tests\Feature\Certificates;

use App\Models\{CertificateTemplate, CertificateType, College};
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Isolated migration fixture DB; no seeders, migrate:fresh, or RefreshDatabase transactions. */
class CertificateSchemaUpgradeTest extends TestCase
{
    private const CREATE = '2026_10_01_000001_create_certificate_management_tables.php';
    private const RECONCILE = '2026_10_01_000002_reconcile_existing_certificate_management_tables.php';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'certificate_upgrade_test', 'database.connections.certificate_upgrade_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('certificate_upgrade_test');
        // Existing upstream tables, intentionally minimal. No student/enrollment data is copied.
        Schema::create('colleges', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        foreach (['users', 'students', 'student_enrollments', 'student_transfers'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
        DB::table('colleges')->insert([['id' => 1, 'name' => 'College A'], ['id' => 2, 'name' => 'College B']]);
        DB::table('users')->insert(['id' => 1]);
    }

    private function upgrade(string $file = self::CREATE): void
    {
        $migration = require database_path('migrations/'.$file);
        $migration->up();
    }

    private function legacySchema(bool $types = true): void
    {
        if ($types) {
            Schema::create('certificate_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('college_id')->constrained();
                $table->string('code', 64)->unique(); // Old global constraint must become college-local.
                $table->string('slug'); // Required legacy field which new writes no longer supply.
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }
        Schema::create('certificate_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['college_id', 'type', 'is_active']);
        });
    }

    private function legacyType(int $id, int $college, string $code, string $name): void
    {
        DB::table('certificate_types')->insert([
            'id' => $id, 'college_id' => $college, 'code' => $code, 'slug' => $code, 'name' => $name,
            'description' => 'Original description', 'is_active' => false, 'created_by' => 1,
            'created_at' => '2025-01-01 09:00:00', 'updated_at' => '2025-02-01 09:00:00',
        ]);
    }

    private function legacyTemplate(int $id, int $college, string $type): void
    {
        DB::table('certificate_templates')->insert([
            'id' => $id, 'college_id' => $college, 'type' => $type, 'name' => 'Original template '.$id,
            'body' => "Original text {{ student_name }}\nSecond line", 'is_active' => false, 'created_by' => 1,
            'created_at' => '2025-03-01 09:00:00', 'updated_at' => '2025-04-01 09:00:00',
        ]);
    }

    public function test_fresh_install_creates_all_tables_and_seven_types_per_college_without_seeders(): void
    {
        $this->upgrade();
        foreach (['certificate_types', 'certificate_templates', 'certificates'] as $name) {
            $this->assertTrue(Schema::hasTable($name));
        }
        foreach ([1, 2] as $college) {
            $codes = DB::table('certificate_types')->where('college_id', $college)->orderBy('id')->pluck('code')->all();
            $this->assertSame(['TC', 'BON', 'CHAR', 'CC', 'MIG', 'PROV', 'CUSTOM'], $codes);
        }
        $this->assertTrue(Schema::hasColumns('certificates', ['student_id', 'student_enrollment_id', 'student_transfer_id', 'number', 'status', 'data_snapshot', 'issued_at', 'verification_count']));
        $this->assertSame(14, DB::table('certificate_types')->count());
        $this->assertSame(0, DB::table('certificates')->count());
    }

    public function test_legacy_upgrade_preserves_ids_content_flags_authors_timestamps_and_maps_within_college(): void
    {
        $this->legacySchema();
        $this->legacyType(10, 1, 'bonafide', 'Bonafide Certificate');
        $this->legacyType(20, 2, 'character', 'Character Certificate');
        $this->legacyType(30, 1, 'study', 'Study Certificate');
        $this->legacyTemplate(100, 1, 'bonafide');
        $this->legacyTemplate(101, 2, 'bonafide');
        $this->legacyTemplate(102, 1, 'study');
        $this->legacyTemplate(103, 2, 'study');
        $templates = DB::table('certificate_templates')->orderBy('id')->get();
        $types = DB::table('certificate_types')->orderBy('id')->get();
        $this->upgrade();
        foreach ($templates as $original) {
            $upgraded = DB::table('certificate_templates')->find($original->id);
            foreach ((array) $original as $column => $value) {
                $targetColumn = $column === 'type' ? 'legacy_type' : $column;
                $this->assertSame($value, $upgraded->$targetColumn, $column);
            }
            $target = DB::table('certificate_types')->find($upgraded->certificate_type_id);
            $this->assertSame($original->college_id, $target->college_id);
        }
        foreach ($types as $original) {
            $upgraded = DB::table('certificate_types')->find($original->id);
            foreach (['name', 'description', 'slug', 'is_active', 'created_by', 'created_at', 'updated_at'] as $column) $this->assertSame($original->$column, $upgraded->$column);
            $this->assertSame($original->code, $upgraded->legacy_code);
        }
        $this->assertSame(10, DB::table('certificate_templates')->find(100)->certificate_type_id);
        $this->assertNotSame(10, DB::table('certificate_templates')->find(101)->certificate_type_id);
        $this->assertSame(30, DB::table('certificate_templates')->find(102)->certificate_type_id);
        $this->assertNotSame(30, DB::table('certificate_templates')->find(103)->certificate_type_id);
        $this->assertSame('BON', DB::table('certificate_types')->find(10)->code);
        $this->assertSame('STUDY', DB::table('certificate_types')->find(30)->code);
        $this->assertSame(16, DB::table('certificate_types')->count());
        $this->assertTrue(Schema::hasTable('certificates'));
    }

    public function test_templates_only_legacy_install_maps_all_builtin_aliases_and_preserves_unknown_types(): void
    {
        $this->legacySchema(false);
        $aliases = ['tc' => 'TC', 'Bonafide Certificate' => 'BON', 'character' => 'CHAR', 'course_completion' => 'CC', 'migration' => 'MIG', 'provisional' => 'PROV', 'custom' => 'CUSTOM', 'No Dues' => 'NO_DUES'];
        $id = 1;
        foreach ($aliases as $legacy => $code) $this->legacyTemplate($id++, 1, $legacy);
        $this->upgrade();
        foreach (DB::table('certificate_templates')->get() as $template) {
            $this->assertSame($aliases[$template->legacy_type], DB::table('certificate_types')->find($template->certificate_type_id)->code);
        }
        $this->assertSame(15, DB::table('certificate_types')->count());
    }

    public function test_legacy_child_references_survive_sqlite_table_rebuilds(): void
    {
        $this->legacySchema();
        $this->legacyType(10, 1, 'tc', 'Transfer Certificate (TC)');
        $this->legacyTemplate(100, 1, 'tc');
        Schema::create('certificate_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('certificate_templates')->cascadeOnDelete();
            $table->text('rendered_content');
        });
        DB::table('certificate_issuances')->insert(['id' => 15, 'template_id' => 100, 'rendered_content' => 'Historical issued document']);
        Schema::table('student_transfers', fn (Blueprint $table) => $table->foreignId('certificate_template_id')->nullable()->constrained('certificate_templates')->nullOnDelete());
        DB::table('student_transfers')->insert(['id' => 9, 'certificate_template_id' => 100]);
        $this->upgrade();
        $this->assertSame('Historical issued document', DB::table('certificate_issuances')->find(15)->rendered_content);
        $this->assertSame(100, DB::table('student_transfers')->find(9)->certificate_template_id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_new_application_models_can_insert_without_legacy_type_or_slug_columns(): void
    {
        $this->legacySchema();
        $this->legacyTemplate(1, 1, 'bonafide');
        $this->upgrade();
        app(TenantContext::class)->set(College::findOrFail(1));
        $type = CertificateType::create(['code' => 'INTERNSHIP', 'name' => 'Internship Certificate', 'description' => 'New type']);
        $template = CertificateTemplate::create(['certificate_type_id' => $type->id, 'name' => 'New template', 'body' => 'New body']);
        $this->assertSame(1, $template->college_id);
        $this->assertSame($type->id, $template->fresh()->type->id);
        $this->assertFalse(Schema::hasColumn('certificate_templates', 'type'));
        $this->assertNull(DB::table('certificate_templates')->find($template->id)->legacy_type);
        $this->assertNull(DB::table('certificate_types')->find($type->id)->slug);
        app(TenantContext::class)->set(College::findOrFail(2));
        $this->assertNull(CertificateTemplate::find($template->id));
        $this->assertNull(CertificateType::find($type->id));
    }

    public function test_composite_foreign_key_rejects_cross_college_template_links(): void
    {
        $this->upgrade();
        $foreignType = DB::table('certificate_types')->where('college_id', 2)->first();
        $this->expectException(QueryException::class);
        DB::table('certificate_templates')->insert(['college_id' => 1, 'certificate_type_id' => $foreignType->id, 'name' => 'Invalid', 'body' => 'Invalid']);
    }

    public function test_reconciliation_is_repeatable_preserves_current_certificates_and_protects_numbering(): void
    {
        $this->legacySchema();
        $this->legacyType(10, 1, 'bonafide', 'Bonafide Certificate');
        $this->legacyTemplate(100, 1, 'bonafide');
        $this->upgrade();
        DB::table('students')->insert(['id' => 1]);
        DB::table('student_enrollments')->insert(['id' => 1]);
        DB::table('certificates')->insert([
            'id' => 8, 'college_id' => 1, 'certificate_type_id' => 10, 'certificate_template_id' => 100,
            'student_id' => 1, 'student_enrollment_id' => 1, 'status' => 'issued', 'number' => 'BON-2026-000042',
            'template_snapshot' => 'Original issued text', 'data_snapshot' => '{"student_name":"Original"}',
            'issued_at' => '2026-01-01 09:00:00', 'verification_count' => 3,
        ]);
        $before = DB::table('certificates')->find(8);
        $template = DB::table('certificate_templates')->find(100);
        $this->upgrade(self::RECONCILE);
        $this->upgrade();
        $this->assertEquals($before, DB::table('certificates')->find(8));
        $this->assertEquals($template, DB::table('certificate_templates')->find(100));
        $this->assertSame(14, DB::table('certificate_types')->count());
        $this->assertSame(43, DB::table('certificate_types')->find(10)->next_number);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_partial_old_create_attempt_with_empty_modern_types_and_legacy_templates_is_reconciled(): void
    {
        $this->legacySchema(false);
        $this->legacyTemplate(100, 1, 'bonafide');
        Schema::create('certificate_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained();
            $table->string('name');
            $table->string('code', 30);
            $table->text('description')->nullable();
            $table->string('builtin_key', 30)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['college_id', 'code']);
            $table->unique(['college_id', 'builtin_key']);
        });
        $this->upgrade();
        $template = DB::table('certificate_templates')->find(100);
        $this->assertSame('BON', DB::table('certificate_types')->find($template->certificate_type_id)->code);
        $this->assertSame('bonafide', $template->legacy_type);
        $this->assertSame(14, DB::table('certificate_types')->count());
        $this->assertTrue(Schema::hasTable('certificates'));
    }

    public function test_existing_modern_custom_type_is_not_reclassified_by_its_display_name(): void
    {
        $this->upgrade();
        $id = DB::table('certificate_types')->insertGetId([
            'college_id' => 1, 'code' => 'LOCAL_BON', 'name' => 'Bonafide Certificate',
            'builtin_key' => null, 'next_number' => 7,
        ]);
        $this->upgrade(self::RECONCILE);
        $this->assertNull(DB::table('certificate_types')->find($id)->builtin_key);
        $this->assertSame('LOCAL_BON', DB::table('certificate_types')->find($id)->code);
        $this->assertSame(7, DB::table('certificate_types')->find($id)->next_number);
        $this->assertSame(15, DB::table('certificate_types')->count());
    }

    public function test_ambiguous_legacy_type_mapping_fails_before_schema_or_data_changes(): void
    {
        $this->legacySchema();
        $this->legacyType(1, 1, 'bon', 'Bonafide');
        $this->legacyType(2, 1, 'bonafide', 'Bonafide Certificate');
        $before = DB::table('certificate_types')->orderBy('id')->get()->toArray();
        try {
            $this->upgrade();
            $this->fail('Expected an ambiguity error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('duplicate code BON', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('certificate_types', 'builtin_key'));
        $this->assertFalse(Schema::hasColumn('certificate_templates', 'certificate_type_id'));
        $this->assertEquals($before, DB::table('certificate_types')->orderBy('id')->get()->toArray());
    }

    public function test_existing_cross_tenant_type_reference_is_rejected_not_silently_reassigned(): void
    {
        $this->legacySchema();
        $this->legacyType(10, 2, 'bonafide', 'Bonafide Certificate');
        $this->legacyTemplate(100, 1, 'bonafide');
        Schema::table('certificate_templates', fn (Blueprint $table) => $table->unsignedBigInteger('certificate_type_id')->nullable());
        DB::table('certificate_templates')->where('id', 100)->update(['certificate_type_id' => 10]);
        try {
            $this->upgrade();
            $this->fail('Expected tenant validation error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('foreign-college', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('certificate_types', 'builtin_key'));
        $this->assertSame(10, DB::table('certificate_templates')->find(100)->certificate_type_id);
    }

    public function test_pending_migration_runs_normally_against_legacy_tables_without_manual_history_changes(): void
    {
        $this->legacySchema();
        $this->legacyTemplate(100, 1, 'tc');
        foreach ([self::CREATE, self::RECONCILE] as $file) {
            $this->artisan('migrate', ['--path' => database_path('migrations/'.$file), '--realpath' => true, '--force' => true])->assertExitCode(0);
            $this->assertTrue(DB::table('migrations')->where('migration', pathinfo($file, PATHINFO_FILENAME))->exists());
        }
        $this->assertSame(2, DB::table('migrations')->count());
        $this->assertNotNull(DB::table('certificate_templates')->find(100)->certificate_type_id);
    }

    public function test_rollback_refuses_to_drop_adopted_data(): void
    {
        $this->legacySchema();
        $this->legacyTemplate(100, 1, 'tc');
        $this->upgrade();
        $migration = require database_path('migrations/'.self::CREATE);
        try {
            $migration->down();
            $this->fail('Expected forward-only rollback guard.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
        $this->assertSame(1, DB::table('certificate_templates')->count());
        $this->assertTrue(Schema::hasTable('certificates'));
    }
}
