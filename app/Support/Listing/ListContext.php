<?php

namespace App\Support\Listing;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ListContext
{
    protected Request $request;
    /** @var array<string, mixed> */
    protected array $filters;
    protected ?LengthAwarePaginator $paginator;
    protected ?string $routeName;
    /** @var array<string, mixed> */
    protected array $routeParameters;

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $routeParameters
     */
    public function __construct(
        array $filters = [],
        ?LengthAwarePaginator $paginator = null,
        ?Request $request = null,
        ?string $routeName = null,
        array $routeParameters = []
    ) {
        $this->request = $request ?? request();
        $this->filters = $filters;
        $this->paginator = $paginator;
        $this->routeName = $routeName;
        $this->routeParameters = $routeParameters;
    }

    public static function make(
        array $filters = [],
        ?LengthAwarePaginator $paginator = null,
        ?Request $request = null,
        ?string $routeName = null,
        array $routeParameters = []
    ): static {
        return new static($filters, $paginator, $request, $routeName, $routeParameters);
    }

    /**
     * Check if a specific filter or any active filter is applied.
     */
    public function hasActiveFilters(?string $key = null): bool
    {
        if ($key !== null) {
            $val = $this->filters[$key] ?? $this->request->input($key);
            return $val !== null && $val !== '' && $val !== [];
        }

        foreach ($this->filters as $k => $v) {
            if ($v !== null && $v !== '' && $v !== []) {
                return true;
            }
        }

        // Also check common GET parameters if filters array wasn't fully supplied
        $search = $this->request->input('search');
        if ($search !== null && trim((string) $search) !== '') {
            return true;
        }

        return false;
    }

    /**
     * Get the value of a filter, falling back to request.
     */
    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $this->request->input($key, $default);
    }

    /**
     * Generate URL for resetting all filters while preserving route params if needed.
     *
     * @param array<int, string> $exceptParams Query parameters to exclude (default: all filters/search)
     */
    public function clearFiltersUrl(array $exceptParams = []): string
    {
        if ($this->routeName) {
            return route($this->routeName, $this->routeParameters);
        }

        // Return current request URL path without query string
        return $this->request->url();
    }

    /**
     * Generate sort URL toggling direction.
     */
    public function sortUrl(string $field): string
    {
        $currentField = (string) $this->request->input('sort');
        $currentDir = strtolower((string) $this->request->input('direction', 'asc'));

        $newDir = ($currentField === $field && $currentDir === 'asc') ? 'desc' : 'asc';

        $query = $this->request->query();
        $query['sort'] = $field;
        $query['direction'] = $newDir;
        // Reset page to 1 on sort change
        unset($query['page']);

        return $this->request->url() . '?' . http_build_query($query);
    }

    /**
     * Check if the current sort matches the given field and direction.
     */
    public function isSorted(string $field, ?string $direction = null): bool
    {
        $currentField = (string) $this->request->input('sort');
        if ($currentField !== $field) {
            return false;
        }

        if ($direction === null) {
            return true;
        }

        $currentDir = strtolower((string) $this->request->input('direction', 'asc'));
        return $currentDir === strtolower($direction);
    }

    public function getPaginator(): ?LengthAwarePaginator
    {
        return $this->paginator;
    }

    public function isEmpty(): bool
    {
        if ($this->paginator !== null) {
            return $this->paginator->total() === 0;
        }

        return false;
    }
}
