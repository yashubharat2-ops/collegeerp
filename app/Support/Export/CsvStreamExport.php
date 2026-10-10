<?php

namespace App\Support\Export;

use App\Models\College;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvStreamExport
{
    /** @var array<int, string> */
    protected array $headers = [];
    protected ?Closure $rowCallback = null;
    protected string $filename = 'export.csv';

    public function __construct(string $filename = 'export.csv')
    {
        $this->filename = str_ends_with($filename, '.csv') ? $filename : $filename . '.csv';
    }

    public static function make(string $filename = 'export.csv'): static
    {
        return new static($filename);
    }

    /**
     * Neutralise spreadsheet formula injection for one cell.
     *
     * A text value that a spreadsheet would read as a formula (leading =, +, -,
     * @, tab or carriage return) is prefixed with an apostrophe so it is shown as
     * text. Opt-in: the Admission-module exports apply it to every row they write;
     * other exports keep their existing output unchanged.
     */
    public static function safeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }

    /**
     * Apply {@see safeCell()} to every cell of a row.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function safeRow(array $row): array
    {
        return array_map([self::class, 'safeCell'], $row);
    }

    /**
     * Define CSV header row (column titles).
     *
     * @param array<int, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Map each record to an array of column values.
     *
     * @param Closure $callback fn($record): array
     */
    public function map(Closure $callback): static
    {
        $this->rowCallback = $callback;
        return $this;
    }

    /**
     * Stream database query in chunks to CSV response without memory bloat.
     */
    public function streamFromQuery(Builder $query, int $chunkSize = 500): StreamedResponse
    {
        $headers = $this->headers;
        $rowCallback = $this->rowCallback;

        $responseHeaders = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->filename . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($query, $headers, $rowCallback, $chunkSize): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            if (! empty($headers)) {
                fputcsv($handle, $headers);
            }

            $query->chunkById($chunkSize, function ($records) use ($handle, $rowCallback): void {
                foreach ($records as $record) {
                    $row = $rowCallback !== null ? $rowCallback($record) : (array) $record;
                    fputcsv($handle, (array) $row);
                }
            });

            fclose($handle);
        }, 200, $responseHeaders);
    }

    /**
     * Stream an in-memory iterable collection of items to CSV.
     *
     * @param iterable<int, mixed> $items
     */
    public function streamFromCollection(iterable $items): StreamedResponse
    {
        $headers = $this->headers;
        $rowCallback = $this->rowCallback;

        $responseHeaders = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->filename . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($items, $headers, $rowCallback): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");

            if (! empty($headers)) {
                fputcsv($handle, $headers);
            }

            foreach ($items as $item) {
                $row = $rowCallback !== null ? $rowCallback($item) : (array) $item;
                fputcsv($handle, (array) $row);
            }

            fclose($handle);
        }, 200, $responseHeaders);
    }
}
