<?php

namespace Database\Migrations\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Frozen migration logic: deliberately independent of application models and seeders. */
final class ReconcileCertificateSchema
{
    private const BUILT_INS = [
        'transfer' => ['TC', 'Transfer Certificate (TC)', ['tc', 'transfer', 'transfercertificate', 'transfercertificatetc']],
        'bonafide' => ['BON', 'Bonafide Certificate', ['bon', 'bonafide', 'bonafidecertificate']],
        'character' => ['CHAR', 'Character Certificate', ['char', 'character', 'charactercertificate']],
        'course_completion' => ['CC', 'Course Completion Certificate', ['cc', 'coursecompletion', 'coursecompletioncertificate']],
        'migration' => ['MIG', 'Migration Certificate', ['mig', 'migration', 'migrationcertificate']],
        'provisional' => ['PROV', 'Provisional Certificate', ['prov', 'provisional', 'provisionalcertificate']],
        'custom' => ['CUSTOM', 'Custom Certificate', ['custom', 'customcertificate']],
    ];

    private array $types = [];
    private array $aliases = [];
    private array $templateTypes = [];

    public function up(): void
    {
        // MySQL DDL implicitly commits. Complete validation/planning before changing any schema.
        $this->plan();
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        // SQLite rebuilds tables for CHANGE COLUMN. Disable FK actions outside a transaction
        // so rebuilding a parent never cascades into legacy issuances/StudentTransfer rows.
        $this->require(! $sqlite || DB::transactionLevel() === 0, 'Run this migration outside a transaction on SQLite.');
        $foreignKeysEnabled = $sqlite && (bool) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        if ($foreignKeysEnabled) Schema::disableForeignKeyConstraints();
        try {
            $this->prepareTypes();
            $this->prepareTemplates();
            DB::transaction(function (): void {
                foreach ($this->types as $key => $type) {
                    $values = [
                        'college_id' => $type['college_id'], 'code' => $type['code'],
                        'builtin_key' => $type['builtin_key'], 'next_number' => $type['next_number'],
                    ];
                    if ($type['id'] !== null) {
                        if ($type['old_code'] !== null && $type['old_code'] !== $type['code']) {
                            $values['legacy_code'] = $type['legacy_code'] ?? $type['old_code'];
                        }
                        DB::table('certificate_types')->where('id', $type['id'])->update($values);
                    } else {
                        $this->types[$key]['id'] = DB::table('certificate_types')->insertGetId($values + [
                            'name' => $type['name'], 'description' => $type['description'],
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                foreach ($this->templateTypes as $id => $key) {
                    DB::table('certificate_templates')->where('id', $id)->update([
                        'certificate_type_id' => $this->types[$key]['id'],
                    ]);
                }
            });
            $this->finishConstraints();
            $this->createCertificates();
        } finally {
            if ($foreignKeysEnabled) Schema::enableForeignKeyConstraints();
        }
        if ($sqlite) {
            foreach (['certificate_types', 'certificate_templates', 'certificates'] as $table) {
                $this->require(DB::select('PRAGMA foreign_key_check("'.$table.'")') === [], "Foreign-key validation failed for $table; restore/repair the source data before retrying.");
            }
        }
    }

    private function plan(): void
    {
        foreach (['certificate_types' => ['id', 'college_id', 'name'], 'certificate_templates' => ['id', 'college_id', 'name', 'body']] as $table => $required) {
            if (! Schema::hasTable($table)) continue;
            foreach ($required as $column) {
                $this->require(Schema::hasColumn($table, $column), "$table is missing $column; supply an explicit, tenant-safe mapping before retrying.");
            }
            $this->validateLegacyColumns($table);
            $this->require(! DB::table($table)->whereRaw('length(name) > 255')->exists(), "$table has names longer than 255 characters; shorten them explicitly before upgrading.");
        }
        $this->require(! (Schema::hasColumn('certificate_templates', 'type') && Schema::hasColumn('certificate_templates', 'legacy_type')),
            'certificate_templates contains both type and legacy_type; resolve the historical-column ambiguity before upgrading.');
        // A global code uniqueness rule belongs to the old catalog, not a tenant catalog.
        // Do not remove one if another table uses it as a referenced key.
        if (Schema::hasTable('certificate_types')) {
            foreach (Schema::getTables() as $table) {
                foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                    if ($foreign['foreign_table'] === 'certificate_types') {
                        $this->require(! array_intersect(['code', 'builtin_key'], $foreign['foreign_columns']), "{$table['name']} references a legacy certificate type code; migrate that reference to IDs explicitly before retrying.");
                    }
                }
            }
        }
        if (Schema::hasTable('certificates')) {
            foreach (['id', 'college_id', 'certificate_type_id', 'certificate_template_id', 'student_id', 'student_enrollment_id', 'student_transfer_id', 'status', 'purpose', 'number', 'template_snapshot', 'data_snapshot', 'generated_at', 'issued_at', 'last_verified_at', 'verification_count', 'requested_by', 'generated_by', 'issued_by', 'last_verified_by', 'created_at', 'updated_at'] as $column) {
                $this->require(Schema::hasColumn('certificates', $column), "Existing certificates table is incompatible (missing $column); no records have been changed.");
            }
        }
        $colleges = DB::table('colleges')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        if (Schema::hasTable('certificate_types')) {
            $modernTypes = Schema::hasColumns('certificate_types', ['code', 'builtin_key', 'next_number']);
            foreach (DB::table('certificate_types')->orderBy('id')->get() as $row) {
                $college = (int) $row->college_id;
                $this->require(isset($colleges[$college]), "Certificate type {$row->id} has no valid college.");
                $tokens = array_filter([$row->code ?? null, $row->short_code ?? null, $row->slug ?? null, $row->type ?? null, $row->name], fn ($v) => $v !== null && trim((string) $v) !== '');
                foreach ($tokens as $token) $this->require(strlen((string) $token) <= 255, "Certificate type {$row->id} has a legacy identifier longer than 255 bytes; map it explicitly before upgrading.");
                $builtin = $row->builtin_key ?? null;
                $this->require($builtin === null || isset(self::BUILT_INS[$builtin]), "Certificate type {$row->id} has an unknown builtin_key.");
                // Existing modern custom types may intentionally share a display name with a
                // built-in. Only legacy rows (or partially backfilled canonical codes) are inferred.
                if (! $modernTypes || ($builtin === null && $this->builtin((string) ($row->code ?? '')) !== null)) {
                    foreach ($tokens as $token) {
                        $match = $this->builtin((string) $token);
                        $this->require($match === null || $builtin === null || $match === $builtin, "Certificate type {$row->id} has conflicting built-in identifiers.");
                        $builtin ??= $match;
                    }
                }
                $code = $builtin !== null ? self::BUILT_INS[$builtin][0] : $this->customCode((string) (reset($tokens) ?: 'TYPE_'.$row->id));
                $key = $college.':'.$code;
                $this->require(! isset($this->types[$key]), "Types in college $college map to duplicate code $code. Resolve the ambiguity without deleting history, then retry.");
                $counter = $row->next_number ?? 1;
                $this->require((bool) preg_match('/^[1-9][0-9]*$/', (string) $counter), "Certificate type {$row->id} has an invalid next_number.");
                $this->types[$key] = [
                    'id' => $row->id, 'college_id' => $college, 'code' => $code, 'builtin_key' => $builtin,
                    'name' => $row->name, 'description' => $row->description ?? null, 'next_number' => $counter,
                    'old_code' => $row->code ?? null, 'legacy_code' => $row->legacy_code ?? null,
                ];
                foreach ([...$tokens, $row->legacy_code ?? '', $code] as $token) $this->alias($college, (string) $token, $key);
            }
        }
        foreach (array_keys($colleges) as $college) {
            foreach (self::BUILT_INS as $builtin => [$code, $name, $aliases]) {
                $key = $college.':'.$code;
                $this->types[$key] ??= $this->newType($college, $code, $name, $builtin);
                foreach ([...$aliases, $name, $code, $builtin] as $alias) $this->alias($college, $alias, $key);
            }
        }
        if (Schema::hasTable('certificates')) {
            foreach (DB::table('certificates')->whereNotNull('number')->get(['certificate_type_id', 'college_id', 'number']) as $certificate) {
                foreach ($this->types as &$type) {
                    if ($type['id'] !== null && (int) $type['id'] === (int) $certificate->certificate_type_id && $type['college_id'] === (int) $certificate->college_id
                        && preg_match('/-(\d+)$/', $certificate->number, $match)) {
                        $type['next_number'] = max($type['next_number'], (int) $match[1] + 1);
                    }
                }
                unset($type);
            }
        }
        if (! Schema::hasTable('certificate_templates')) return;
        DB::table('certificate_templates')->orderBy('id')->chunkById(500, function ($rows) use ($colleges): void {
            foreach ($rows as $row) {
                $college = (int) $row->college_id;
                $this->require(isset($colleges[$college]), "Template {$row->id} has no valid college.");
                $key = null;
                if (($row->certificate_type_id ?? null) !== null) {
                    foreach ($this->types as $candidate => $type) {
                        if ($type['id'] !== null && (int) $type['id'] === (int) $row->certificate_type_id && $type['college_id'] === $college) $key = $candidate;
                    }
                    $this->require($key !== null, "Template {$row->id} references a missing or foreign-college certificate type.");
                }
                $legacyType = $row->type ?? $row->legacy_type ?? null;
                if ($key === null && $legacyType !== null && trim((string) $legacyType) !== '') {
                    $token = $this->token((string) $legacyType);
                    $this->require($token !== '', "Template {$row->id} has an unmappable legacy type.");
                    $matches = array_keys($this->aliases[$college][$token] ?? []);
                    $this->require(count($matches) <= 1, "Template {$row->id} has an ambiguous legacy type in college $college.");
                    if (! $matches) {
                        $code = $this->customCode((string) $legacyType);
                        $newKey = $college.':'.$code;
                        $this->require(! isset($this->types[$newKey]), "Template {$row->id} has a colliding custom type code $code.");
                        $this->types[$newKey] = $this->newType($college, $code, trim((string) $legacyType), null);
                        $this->alias($college, (string) $legacyType, $newKey);
                        $matches = [$newKey];
                    }
                    $key = $matches[0];
                }
                $this->require($key !== null, "Template {$row->id} has neither a valid legacy type nor a certificate_type_id.");
                $this->templateTypes[$row->id] = $key;
            }
        });
    }

    private function validateLegacyColumns(string $table): void
    {
        $allowed = $table === 'certificate_types'
            ? ['id', 'college_id', 'name', 'code', 'short_code', 'slug', 'type', 'created_at', 'updated_at']
            : ['id', 'college_id', 'name', 'body', 'type', 'legacy_type', 'certificate_type_id', 'created_at', 'updated_at'];
        foreach (Schema::getColumns($table) as $column) {
            $this->require(in_array($column['name'], $allowed, true) || $column['nullable'] || $column['default'] !== null || ($column['auto_increment'] ?? false),
                "$table.{$column['name']} is required without a default; configure a safe default before upgrading so new application writes remain valid.");
        }
    }

    private function prepareTypes(): void
    {
        if (! Schema::hasTable('certificate_types')) {
            Schema::create('certificate_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('college_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->text('description')->nullable();
                $table->string('builtin_key', 30)->nullable();
                $table->unsignedBigInteger('next_number')->default(1);
                $table->text('legacy_code')->nullable();
                $table->timestamps();
            });
        } else {
            $missing = array_diff(['code', 'description', 'builtin_key', 'next_number', 'legacy_code', 'created_at', 'updated_at'], Schema::getColumnListing('certificate_types'));
            Schema::table('certificate_types', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    match ($column) {
                        'code', 'builtin_key' => $table->string($column, 30)->nullable(),
                        'description', 'legacy_code' => $table->text($column)->nullable(),
                        'next_number' => $table->unsignedBigInteger($column)->default(1),
                        'created_at', 'updated_at' => $table->timestamp($column)->nullable(),
                        default => $table->string($column)->nullable(),
                    };
                }
            });
            // Keep legacy tokens for audit/reference, but do not require the new app to write them.
            foreach (['type', 'slug', 'short_code'] as $column) {
                if (Schema::hasColumn('certificate_types', $column) && ! $this->column('certificate_types', $column)['nullable']) {
                    Schema::table('certificate_types', fn (Blueprint $table) => $table->string($column)->nullable()->change());
                }
            }
        }
        if ($this->column('certificate_types', 'code')['nullable'] || (DB::connection()->getDriverName() !== 'sqlite' && ! in_array($this->column('certificate_types', 'code')['type'], ['varchar(30)', 'character varying(30)'], true))) {
            Schema::table('certificate_types', fn (Blueprint $table) => $table->string('code', 255)->nullable()->change());
        }
        foreach (Schema::getIndexes('certificate_types') as $index) {
            if ($index['unique'] && in_array($index['columns'], [['code'], ['builtin_key']], true)) {
                Schema::table('certificate_types', fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
    }

    private function prepareTemplates(): void
    {
        if (! Schema::hasTable('certificate_templates')) {
            Schema::create('certificate_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('college_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('certificate_type_id')->nullable();
                $table->string('name');
                $table->text('body');
                $table->timestamps();
            });
        } else {
            if (! Schema::hasColumn('certificate_templates', 'certificate_type_id')) {
                Schema::table('certificate_templates', fn (Blueprint $table) => $table->unsignedBigInteger('certificate_type_id')->nullable());
            }
            if (Schema::hasColumn('certificate_templates', 'type')) {
                // `type` would shadow CertificateTemplate::type() in Eloquent. Retain its
                // original contents under an archival name, not a live relationship name.
                Schema::table('certificate_templates', fn (Blueprint $table) => $table->renameColumn('type', 'legacy_type'));
            }
            if (Schema::hasColumn('certificate_templates', 'legacy_type') && ! $this->column('certificate_templates', 'legacy_type')['nullable']) {
                Schema::table('certificate_templates', fn (Blueprint $table) => $table->string('legacy_type')->nullable()->change());
            }
            foreach (['created_at', 'updated_at'] as $column) {
                if (! Schema::hasColumn('certificate_templates', $column)) {
                    Schema::table('certificate_templates', fn (Blueprint $table) => $table->timestamp($column)->nullable());
                }
            }
        }
        // The new HTTP validation accepts names of up to 255 characters.
        foreach (['certificate_types', 'certificate_templates'] as $name) {
            if (DB::connection()->getDriverName() !== 'sqlite' && ! in_array($this->column($name, 'name')['type'], ['varchar(255)', 'character varying(255)'], true)) {
                Schema::table($name, fn (Blueprint $table) => $table->string('name')->change());
            }
        }
    }

    private function finishConstraints(): void
    {
        if ($this->column('certificate_types', 'code')['nullable'] || (DB::connection()->getDriverName() !== 'sqlite' && ! in_array($this->column('certificate_types', 'code')['type'], ['varchar(30)', 'character varying(30)'], true))) {
            Schema::table('certificate_types', fn (Blueprint $table) => $table->string('code', 30)->change());
        }
        if ($this->column('certificate_templates', 'certificate_type_id')['nullable']) {
            Schema::table('certificate_templates', fn (Blueprint $table) => $table->unsignedBigInteger('certificate_type_id')->change());
        }
        $this->unique('certificate_types', ['college_id', 'code'], 'certificate_types_college_id_code_unique');
        $this->unique('certificate_types', ['college_id', 'builtin_key'], 'certificate_types_college_id_builtin_key_unique');
        $this->unique('certificate_types', ['id', 'college_id'], 'certificate_types_id_college_unique');
        foreach (['certificate_types', 'certificate_templates'] as $name) {
            $this->foreign($name, ['college_id'], 'colleges', ['id'], $name.'_college_id_foreign');
        }
        $this->foreign('certificate_templates', ['certificate_type_id', 'college_id'], 'certificate_types', ['id', 'college_id'], 'certificate_templates_type_college_foreign');
    }

    private function createCertificates(): void
    {
        if (Schema::hasTable('certificates')) return;
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_transfer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('requested');
            $table->text('purpose')->nullable();
            $table->string('number', 100)->nullable();
            $table->text('template_snapshot')->nullable();
            $table->json('data_snapshot')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedInteger('verification_count')->default(0);
            foreach (['requested_by', 'generated_by', 'issued_by', 'last_verified_by'] as $column) {
                $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->timestamps();
            $table->unique(['college_id', 'number']);
            $table->index(['college_id', 'certificate_type_id', 'status']);
        });
    }

    private function column(string $table, string $name): array
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === $name) return $column;
        }
        throw new RuntimeException("Missing $table.$name");
    }

    private function unique(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
    }

    private function foreign(string $table, array $columns, string $parent, array $references, string $name): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === $columns && $foreign['foreign_table'] === $parent && $foreign['foreign_columns'] === $references) return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($columns, $name)->references($references)->on($parent)->restrictOnDelete());
    }

    private function newType(int $college, string $code, string $name, ?string $builtin): array
    {
        return ['id' => null, 'college_id' => $college, 'code' => $code, 'builtin_key' => $builtin, 'name' => $name, 'description' => $builtin ? $name.' — Group 1' : 'Preserved legacy certificate type', 'next_number' => 1];
    }

    private function alias(int $college, string $token, string $key): void
    {
        if ($this->token($token) !== '') $this->aliases[$college][$this->token($token)][$key] = true;
    }

    private function builtin(string $token): ?string
    {
        foreach (self::BUILT_INS as $key => [$code, $name, $aliases]) {
            if (in_array($this->token($token), $aliases, true)) return $key;
        }
        return null;
    }

    private function token(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    private function customCode(string $value): string
    {
        $code = trim(strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $value)), '_');
        if (! preg_match('/^[A-Z]/', $code)) $code = 'TYPE_'.$code;
        return strlen($code) <= 30 ? $code : substr($code, 0, 21).'_'.strtoupper(substr(hash('sha256', $value), 0, 8));
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException('Certificate schema reconciliation: '.$message);
    }
}
