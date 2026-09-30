<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Read-only metadata from the existing immutable audit log, never raw payloads. */
class AuditLogsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $platform = $request->query('scope') === 'platform';
        $this->authorize($platform ? 'viewPlatform' : 'viewAny', AuditLog::class);
        $filters = $request->validate([
            'scope' => ['nullable', 'in:tenant,platform'],
            'college_id' => $platform ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ]);
        $scope = AuditLog::query();
        if ($platform) {
            if (! empty($filters['college_id'])) {
                $scope->where('college_id', $filters['college_id']);
            }
        } else {
            // Always establish isolation first, independently of every filter.
            $scope->where('college_id', app(TenantContext::class)->require()->getKey());
        }
        $actions = (clone $scope)->distinct()->orderBy('action')->pluck('action');
        $actors = User::query()->whereIn('id', (clone $scope)->select('user_id'))->orderBy('name')->get(['id', 'name']);
        $query = clone $scope;
        foreach (['user_id', 'action'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['module'])) {
            $query->where('action', 'like', $filters['module'].'.%');
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['date_from'], 'UTC')->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<', Carbon::createFromFormat('Y-m-d', $filters['date_to'], 'UTC')->startOfDay()->addDay());
        }

        return view('administration.audit-logs.index', [
            // Excluding payloads, user agents and headers at the SELECT boundary
            // prevents historical secrets (including unredacted legacy events)
            // from reaching the view, not just from being visually hidden.
            'logs' => $query->select(['id', 'user_id', 'college_id', 'action', 'subject_type', 'subject_id', 'route_name', 'method', 'created_at'])
                ->with(['user:id,name', 'college:id,name'])->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'actions' => $actions,
            'modules' => $actions->map(fn (string $action) => Str::before($action, '.'))->unique()->sort()->values(),
            'actors' => $actors, 'filters' => $filters, 'platform' => $platform,
            'auditColleges' => $platform ? College::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
