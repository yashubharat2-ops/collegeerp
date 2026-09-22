@csrf
<div class="mt-6 grid gap-4 sm:grid-cols-2">
    @if(isset($assignment))
        <div class="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p class="text-sm">
                <span class="font-semibold">Student:</span>
                {{ $assignment->studentEnrollment?->student?->first_name }} {{ $assignment->studentEnrollment?->student?->last_name }}
                ({{ $assignment->studentEnrollment?->student?->student_number ?? '—' }})
                · <span class="font-semibold">Enrollment:</span> {{ $assignment->studentEnrollment?->enrollment_number ?? '—' }}
                · <span class="font-semibold">Academic year:</span> {{ $assignment->academicYear?->name ?? '—' }}
            </p>
            <p class="mt-1 text-xs text-slate-500">The enrollment and academic year are immutable — re-pointing would rewrite history. Cancel or delete this assignment and create a new one instead.</p>
        </div>
    @else
        <label class="block text-sm font-medium text-slate-700">
            Student enrollment *
            <select class="input mt-1" name="student_enrollment_id" required>
                <option value="">— Select existing enrollment —</option>
                @foreach($enrollments as $enrollment)
                    <option value="{{ $enrollment->id }}" @selected((int) old('student_enrollment_id') === (int) $enrollment->id)>
                        {{ $enrollment->student?->student_number }} — {{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }} ({{ $enrollment->enrollment_number }})
                    </option>
                @endforeach
            </select>
            @error('student_enrollment_id')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Academic year *
            <select class="input mt-1" name="academic_year_id" required>
                <option value="">— Select academic year —</option>
                @foreach($years as $year)
                    <option value="{{ $year->id }}" @selected((int) old('academic_year_id') === (int) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
            @error('academic_year_id')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
    @endif

    <label class="block text-sm font-medium text-slate-700">
        Route *
        <select class="input mt-1" name="transport_route_id" id="transport_route_id" required>
            <option value="">— Select route —</option>
            @foreach($routes as $route)
                <option value="{{ $route->id }}" @selected((int) old('transport_route_id', $assignment->transport_route_id ?? 0) === (int) $route->id)>{{ $route->name }} ({{ $route->code }})</option>
            @endforeach
        </select>
        @error('transport_route_id')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Stop *
        <select class="input mt-1" name="transport_stop_id" id="transport_stop_id" required>
            <option value="">— Select stop (must belong to the route) —</option>
            @foreach($stops as $stop)
                <option value="{{ $stop->id }}" data-route-id="{{ $stop->route_id }}" @selected((int) old('transport_stop_id', $assignment->transport_stop_id ?? 0) === (int) $stop->id)>
                    {{ $routes->firstWhere('id', $stop->route_id)?->name ?? '—' }} — {{ $stop->name }} (#{{ $stop->sequence }})
                </option>
            @endforeach
        </select>
        @error('transport_stop_id')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Start date *
        <input class="input mt-1" type="date" name="start_date" value="{{ old('start_date', isset($assignment) ? $assignment->start_date?->format('Y-m-d') : '') }}" required>
        @error('start_date')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        End date
        <input class="input mt-1" type="date" name="end_date" value="{{ old('end_date', isset($assignment) ? $assignment->end_date?->format('Y-m-d') : '') }}">
        @error('end_date')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700">
        Status *
        <select class="input mt-1" name="status" required>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $assignment->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        @error('status')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
        Remarks
        <textarea class="input mt-1" name="remarks" maxlength="2000" rows="2">{{ old('remarks', $assignment->remarks ?? '') }}</textarea>
        @error('remarks')<span class="text-red-600">{{ $message }}</span>@enderror
    </label>
    <div class="flex gap-2 sm:col-span-2">
        <button class="button" type="submit">{{ $submitLabel }}</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-assignments.index') }}">Cancel</a>
    </div>
</div>
@once
    @push('scripts')
    <script>
        // Convenience only: the server re-checks that the stop belongs to the route.
        (function () {
            var routeSelect = document.getElementById('transport_route_id');
            var stopSelect = document.getElementById('transport_stop_id');
            if (!routeSelect || !stopSelect) return;
            function filterStops() {
                var routeId = routeSelect.value;
                var keep = stopSelect.value;
                Array.prototype.forEach.call(stopSelect.options, function (option) {
                    if (!option.dataset.routeId) return;
                    option.hidden = routeId !== '' && option.dataset.routeId !== routeId;
                });
                var kept = stopSelect.querySelector('option[value="' + keep + '"]');
                if (kept && kept.hidden) stopSelect.value = '';
            }
            routeSelect.addEventListener('change', filterStops);
            filterStops();
        })();
    </script>
    @endpush
@endonce
