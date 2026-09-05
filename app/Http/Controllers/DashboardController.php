<?php

namespace App\Http\Controllers;

use App\Models\College;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();
        $colleges = $user->isSuperAdmin() ? College::query()->where('status', 'active')->orderBy('name')->get() : $user->colleges()->where('status', 'active')->orderBy('name')->get();
        return view('dashboard.index', compact('colleges'));
    }
}
