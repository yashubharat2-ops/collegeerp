<?php

namespace Tests\Unit\Export;

use App\Models\AdmissionApplicant;
use App\Models\College;
use App\Support\Export\CsvStreamExport;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class CsvStreamExportTest extends TestCase
{
    use StudentTestHelpers;

    public function test_csv_stream_from_collection(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'Admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'Student'],
        ];

        $export = CsvStreamExport::make('users.csv')
            ->withHeaders(['ID', 'Name', 'Role'])
            ->map(fn ($item) => [$item['id'], $item['name'], $item['role']]);

        $response = $export->streamFromCollection($items);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('users.csv', $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // UTF-8 BOM must be present at the start of the output
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Header and rows after stripping BOM
        $body = substr($content, 3);
        $this->assertStringContainsString("ID,Name,Role", $body);
        $this->assertStringContainsString("1,Alice,Admin", $body);
        $this->assertStringContainsString("2,Bob,Student", $body);
    }

    public function test_csv_stream_from_query(): void
    {
        $college = $this->makeCollege('EXP1');
        app(TenantContext::class)->set($college);

        AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'status' => 'active',
        ]);

        $query = AdmissionApplicant::query()->where('college_id', $college->id);

        $export = CsvStreamExport::make('applicants.csv')
            ->withHeaders(['First Name', 'Last Name', 'Email', 'Status'])
            ->map(fn (AdmissionApplicant $a) => [$a->first_name, $a->last_name, $a->email, $a->status]);

        $response = $export->streamFromQuery($query);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('applicants.csv', $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // UTF-8 BOM must be present at the start of the output
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Header and rows after stripping BOM ("First Name" and "Last Name" contain spaces, so fputcsv quotes them)
        $body = substr($content, 3);
        $this->assertStringContainsString('"First Name","Last Name",Email,Status', $body);
        $this->assertStringContainsString("John,Doe,john.doe@example.com,active", $body);
    }
}
