<a class="nav-link sidebar-dashboard {{ request()->routeIs('dashboard') ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('dashboard') }}" title="Dashboard" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
    <x-sidebar-icon name="dashboard" class="sidebar-dashboard-icon text-slate-400" />
    <span class="sidebar-dashboard-label">Dashboard</span>
</a>
