<?php

namespace App\Support\Listing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Throwable;

class ListQueryBuilder
{
    protected Builder $query;
    protected Request $request;
    /** @var array<string, mixed> */
    protected array $appliedFilters = [];
    /** @var array<string, string> */
    protected array $allowedSorts = [];
    protected ?string $defaultSortField = null;
    protected string $defaultSortDirection = 'asc';
    protected int $defaultPerPage = 15;
    protected int $maxPerPage = 100;
    protected string $currentSortField = '';
    protected string $currentSortDirection = '';

    public function __construct(Builder $query, ?Request $request = null)
    {
        $this->query = $query;
        $this->request = $request ?? request();
    }

    public static function for(Builder $query, ?Request $request = null): static
    {
        return new static($query, $request);
    }

    /**
     * Search across one or more columns with LIKE %search%.
     *
     * @param array<int, string> $columns
     */
    public function search(array $columns, string $param = 'search'): static
    {
        $val = $this->request->input($param);
        if (! is_string($val)) {
            return $this;
        }

        $term = trim($val);
        if ($term === '') {
            return $this;
        }

        $this->appliedFilters[$param] = $term;

        $this->query->where(function (Builder $builder) use ($columns, $term): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, 'like', "%{$term}%");
                } else {
                    $builder->orWhere($column, 'like', "%{$term}%");
                }
            }
        });

        return $this;
    }

    /**
     * Exact match filter (e.g. status, id, code).
     *
     * @param array<int, mixed>|null $allowedValues Optional whitelist of permitted values
     */
    public function filterExact(string $param, ?string $column = null, ?array $allowedValues = null): static
    {
        $column = $column ?? $param;
        $val = $this->request->input($param);

        if ($val === null || $val === '') {
            return $this;
        }

        if (is_array($allowedValues) && ! in_array($val, $allowedValues, true)) {
            return $this;
        }

        $this->appliedFilters[$param] = $val;
        $this->query->where($column, $val);

        return $this;
    }

    /**
     * Boolean filter (matches '1'/'true'/true/1 or '0'/'false'/false/0).
     */
    public function filterBoolean(string $param, ?string $column = null): static
    {
        $column = $column ?? $param;
        $val = $this->request->input($param);

        if ($val === null || $val === '') {
            return $this;
        }

        $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolVal === null) {
            return $this;
        }

        $this->appliedFilters[$param] = $boolVal ? '1' : '0';
        $this->query->where($column, $boolVal);

        return $this;
    }

    /**
     * Multi-select filter (whereIn).
     *
     * @param array<int, mixed>|null $allowedValues Optional whitelist
     */
    public function filterIn(string $param, ?string $column = null, ?array $allowedValues = null): static
    {
        $column = $column ?? $param;
        $val = $this->request->input($param);

        if (empty($val)) {
            return $this;
        }

        $items = is_array($val) ? $val : explode(',', (string) $val);
        $items = array_values(array_filter(array_map('trim', $items), fn ($item) => $item !== ''));

        if (empty($items)) {
            return $this;
        }

        if (is_array($allowedValues)) {
            $items = array_values(array_intersect($items, $allowedValues));
            if (empty($items)) {
                return $this;
            }
        }

        $this->appliedFilters[$param] = $items;
        $this->query->whereIn($column, $items);

        return $this;
    }

    /**
     * Date filter (Y-m-d).
     */
    public function filterDate(string $param, ?string $column = null, string $operator = '='): static
    {
        $column = $column ?? $param;
        $val = $this->request->input($param);

        if (! is_string($val) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $this;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $val);
            if (! $parsed || $parsed->format('Y-m-d') !== $val) {
                return $this;
            }
        } catch (Throwable) {
            return $this;
        }

        $this->appliedFilters[$param] = $val;
        $this->query->whereDate($column, $operator, $val);

        return $this;
    }

    /**
     * Date range filter (from and to parameters).
     */
    public function filterDateRange(string $fromParam = 'from_date', string $toParam = 'to_date', ?string $column = null): static
    {
        $column = $column ?? 'created_at';
        $from = $this->request->input($fromParam);
        $to = $this->request->input($toParam);

        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $this->appliedFilters[$fromParam] = $from;
            $this->query->whereDate($column, '>=', $from);
        }

        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->appliedFilters[$toParam] = $to;
            $this->query->whereDate($column, '<=', $to);
        }

        return $this;
    }

    /**
     * Related model filter via whereHas.
     */
    public function filterRelation(string $param, string $relation, string $relatedColumn, ?callable $callback = null): static
    {
        $val = $this->request->input($param);
        if ($val === null || $val === '') {
            return $this;
        }

        $this->appliedFilters[$param] = $val;

        $this->query->whereHas($relation, function (Builder $relQuery) use ($relatedColumn, $val, $callback): void {
            if ($callback !== null) {
                $callback($relQuery, $val);
            } else {
                $relQuery->where($relatedColumn, $val);
            }
        });

        return $this;
    }

    /**
     * Custom filter callback.
     */
    public function filterWhen(string $param, callable $callback): static
    {
        $val = $this->request->input($param);
        if ($val !== null && $val !== '') {
            $this->appliedFilters[$param] = $val;
            $callback($this->query, $val);
        }

        return $this;
    }

    /**
     * Configure allowed sorting columns and default sort.
     *
     * @param array<string, string> $allowedSorts Map of user sort keys => database column expressions
     */
    public function sorts(array $allowedSorts, ?string $defaultField = null, string $defaultDirection = 'asc'): static
    {
        $this->allowedSorts = $allowedSorts;
        $this->defaultSortField = $defaultField;
        $this->defaultSortDirection = strtolower($defaultDirection) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    /**
     * Apply sorting to the query.
     */
    public function applySorts(string $sortParam = 'sort', string $directionParam = 'direction'): static
    {
        $sort = (string) $this->request->input($sortParam, '');
        $dir = strtolower((string) $this->request->input($directionParam, ''));

        if ($sort !== '' && isset($this->allowedSorts[$sort])) {
            $direction = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';
            $column = $this->allowedSorts[$sort];
            $this->query->orderBy($column, $direction);
            $this->currentSortField = $sort;
            $this->currentSortDirection = $direction;
        } elseif ($this->defaultSortField !== null && isset($this->allowedSorts[$this->defaultSortField])) {
            $column = $this->allowedSorts[$this->defaultSortField];
            $this->query->orderBy($column, $this->defaultSortDirection);
            $this->currentSortField = $this->defaultSortField;
            $this->currentSortDirection = $this->defaultSortDirection;
        }

        return $this;
    }

    /**
     * Paginate the query preserving query-string parameters.
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page'): LengthAwarePaginator
    {
        $this->applySorts();

        $perPageInput = (int) $this->request->input('per_page', $perPage ?? $this->defaultPerPage);
        if ($perPageInput <= 0 || $perPageInput > $this->maxPerPage) {
            $perPageInput = $perPage ?? $this->defaultPerPage;
        }

        return $this->query->paginate($perPageInput, $columns, $pageName)->withQueryString();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAppliedFilters(): array
    {
        return $this->appliedFilters;
    }

    public function getCurrentSort(): array
    {
        return [
            'field' => $this->currentSortField,
            'direction' => $this->currentSortDirection,
        ];
    }
}
