<?php

namespace Tests\Unit\Export;

use App\Models\AdmissionApplicant;
use App\Models\College;
use App\Support\Export\CsvStreamExport;
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

        // UTF-8 BOM + Header + rows
        $this->assertStringContainsString("ID,Name,Role", $content);
        $this->assertStringContainsString("1,Alice,Admin", $content);
        $this->assertStringContainsString("2,Bob,Student", $content);
    }

    public function test_csv_stream_from_query(): void
    {
        $college = $this->makeCollege('EXP1');
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

        $this->assertStringContainsString("First Name,Last Name,Email,Status", $content);
        $this->assertStringContainsString("John,Doe,john.doe@example.com,active", $content);
    }
}
