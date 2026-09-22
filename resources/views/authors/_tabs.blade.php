{{--
    Authors / Publishers share one navigation entry; these tabs switch between
    the two masters. Each tab is permission-gated exactly like the routes.
--}}
<div class="mt-4 flex gap-2 border-b border-slate-200 text-sm">
    @can('viewAny', App\Models\Author::class)
        <a class="-mb-px border-b-2 px-3 py-2 font-medium {{ $active === 'authors' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}" href="{{ route('authors.index') }}">Authors</a>
    @endcan
    @can('viewAny', App\Models\Publisher::class)
        <a class="-mb-px border-b-2 px-3 py-2 font-medium {{ $active === 'publishers' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}" href="{{ route('publishers.index') }}">Publishers</a>
    @endcan
</div>
