<p class="panel-subtitle">
    High-level operational counts for the active college, one group per existing module. Every figure is the headline of
    that module's own report service — the same source the module's detail screens use — so nothing here can drift from
    the operational records.
</p>

@include('consolidated_reports._cards', ['groups' => $summary['groups']])
