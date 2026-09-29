@extends('layouts.app')

@section('title', 'Inventory / Asset Reports')

@section('content')
<div class="space-y-5">
    <section class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="panel-title">Inventory / Asset Reports</h2>
                <p class="panel-subtitle">Read-only views of the existing Inventory item master, stock ledger, purchase orders, issues, asset custody and maintenance records. Stock balances always come from the authoritative movement ledger.</p>
            </div>
            <span class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700">{{ $reports[$report] }}</span>
        </div>

        @include('inventory_reports._navigation')
        @if($visible !== [])
            @include('inventory_reports._filters')
        @endif
    </section>

    <section class="panel">
        <h3 class="panel-title">{{ $reports[$report] }}</h3>
        @include('inventory_reports.reports.'.$report)
    </section>
</div>
@endsection
