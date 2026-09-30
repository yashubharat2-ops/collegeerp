@extends('layouts.app')
@section('title', 'Link Existing Account')
@section('content')
@include('administration.partials.context')
<div class="panel">
    <div class="flex flex-wrap justify-between gap-3"><div><h2 class="panel-title">Associate an existing account</h2><p class="panel-subtitle">Super Admin operation. Adds a college membership to the existing identity without changing its password, other memberships or role grants.</p></div><a class="button !bg-slate-200 !text-slate-700" href="{{ route('admin.users.index') }}">Back to users</a></div>
    <form method="GET" action="{{ route('admin.users.link') }}" class="mt-6 flex flex-wrap items-end gap-3"><div class="flex-1"><label class="label" for="search">Name or email</label><input class="input" id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}"></div><button class="button" type="submit">Search</button></form>
    <div class="mt-6 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3">Name</th><th>Email</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>@forelse($users as $managedUser)<tr class="border-b"><td class="py-3 pr-4 font-medium">{{ $managedUser->name }}</td><td class="pr-4">{{ $managedUser->email }}</td><td class="pr-4">@include('administration.partials.status', ['active' => $managedUser->is_active])</td><td class="text-right"><form method="POST" action="{{ route('admin.users.link.store') }}">@csrf<input type="hidden" name="user_id" value="{{ $managedUser->id }}"><button class="font-semibold text-indigo-600" type="submit">Associate with this college</button></form></td></tr>@empty<tr><td class="py-6 text-slate-500" colspan="4">No unassociated accounts match the search.</td></tr>@endforelse</tbody></table></div>
    <div class="mt-4">{{ $users->links() }}</div>
</div>
@endsection
