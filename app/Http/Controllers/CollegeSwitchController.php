<?php

namespace App\Http\Controllers;

use App\Models\College;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CollegeSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['college_id' => ['required', 'integer']]);
        $user = $request->user();
        $college = $user->isSuperAdmin()
            ? College::query()->whereKey($data['college_id'])->where('status', 'active')->firstOrFail()
            : $user->colleges()->whereKey($data['college_id'])->where('colleges.status', 'active')->firstOrFail();
        $request->session()->put('active_college_id', $college->getKey());
        return back()->with('success', 'College context switched.');
    }
}
