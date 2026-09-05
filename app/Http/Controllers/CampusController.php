<?php

namespace App\Http\Controllers;

use App\Http\Requests\Campus\StoreCampusRequest;
use App\Models\Campus;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CampusController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Campus::class);
        return view('campuses.index', ['campuses' => Campus::latest()->paginate(15)]);
    }

    public function store(StoreCampusRequest $request, AuditLogService $audit): RedirectResponse
    {
        $campus = Campus::create($request->validated());
        $audit->record('campus.created', $campus, [], $campus->only(['id', 'name', 'code', 'status']));
        return back()->with('success', 'Campus created.');
    }
}
