<?php

namespace App\Support\Listing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
    protected bool $sortsApplied = false;

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
     * Related-model columns may be searched too, so a single term covers data
     * that lives on a child record (e.g. a student's enrollment number). Every
     * related match is OR-ed INSIDE the same group as the column matches, so the
     * search term is still AND-ed with all other filters.
     *
     * @param array<int, string> $columns Columns on the root query.
     * @param array<string, array<int, string>> $relations Map of relation => columns
     *        to match with whereHas (a relation is skipped when its column list is empty).
     */
    public function search(array $columns, string $param = 'search', array $relations = []): static
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

        $this->query->where(function (Builder $builder) use ($columns, $relations, $term): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, 'like', "%{$term}%");
                } else {
                    $builder->orWhere($column, 'like', "%{$term}%");
                }
            }

            foreach ($relations as $relation => $relationColumns) {
                if (! is_string($relation) || empty($relationColumns)) {
                    continue;
                }

                $builder->orWhereHas($relation, function (Builder $related) use ($relationColumns, $term): void {
                    foreach ($relationColumns as $index => $column) {
                        if ($index === 0) {
                            $related->where($column, 'like', "%{$term}%");
                        } else {
                            $related->orWhere($column, 'like', "%{$term}%");
                        }
                    }
                });
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
     * Apply a custom filter group when ANY of the given parameters is present.
     *
     * This exists for filter sets that describe ONE related record: the callback
     * runs once with the map of present parameters, so a single correlated
     * subquery (`whereHas`) can be built instead of one per parameter — the
     * difference between "a student with year A and a different enrollment in
     * program B" and "a student whose enrollment is year A AND program B".
     *
     * Present parameters are recorded in the applied-filter state exactly like
     * the built-in filters; the callback decides whether a value is usable
     * (whitelist / numeric check), and ignorable values must simply not be
     * translated into a constraint.
     *
     * @param array<int, string> $params
     * @param callable(Builder, array<string, mixed>): void $callback
     */
    public function filterAny(array $params, callable $callback): static
    {
        $present = [];

        foreach ($params as $param) {
            $val = $this->request->input($param);

            if ($val === null || $val === '' || $val === []) {
                continue;
            }

            $present[$param] = $val;
            $this->appliedFilters[$param] = $val;
        }

        if ($present !== []) {
            $callback($this->query, $present);
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
        // A new sort configuration always re-applies, so the order is never
        // silently pinned to a previous applySorts() call.
        $this->sortsApplied = false;

        return $this;
    }

    /**
     * Apply sorting to the query.
     *
     * Idempotent: the configured sort is applied at most once, so a caller may
     * apply sorts explicitly (e.g. to inspect getCurrentSort(), or to add a
     * tiebreak) and then paginate() without the order clause being duplicated.
     */
    public function applySorts(string $sortParam = 'sort', string $directionParam = 'direction'): static
    {
        if ($this->sortsApplied) {
            return $this;
        }

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

        $this->sortsApplied = true;

        return $this;
    }

    /**
     * Append a stable tiebreak column AFTER the configured sort.
     *
     * Without it two rows sharing a sort value (e.g. students created in the same
     * second) may swap places between pages, which makes a paginated list show
     * one row twice and skip another. The tiebreak follows the primary direction
     * so "newest first" lists stay newest-first on equal keys.
     */
    public function tiebreaker(string $column, ?string $direction = null): static
    {
        $this->applySorts();

        $direction = in_array(strtolower((string) $direction), ['asc', 'desc'], true)
            ? strtolower((string) $direction)
            : ($this->currentSortDirection !== '' ? $this->currentSortDirection : 'asc');

        $this->query->orderBy($column, $direction);

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

        // Honor request's explicit page parameter if the global Paginator resolver hasn't caught it yet
        $page = (int) $this->request->input($pageName, Paginator::resolveCurrentPage($pageName));
        if ($page <= 0) {
            $page = 1;
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $this->query->paginate($perPageInput, $columns, $pageName, $page);

        // Keep all request query parameters attached to the pagination links
        return $paginator->appends($this->request->query());
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
