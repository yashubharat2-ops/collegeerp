{{--
    Grade scale form (create + edit).

    Grade bands are posted as an indexed array; the shared GradeScaleService
    re-validates duplicates, inverted ranges and overlaps server-side.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $scale->name ?? '') }}" required maxlength="255">
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $scale->code ?? '') }}" required maxlength="50">
    <p class="mt-1 text-xs text-slate-500">Unique within this college.</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status">
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $scale->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Only active scales can be used for calculation and publishing.</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000">{{ old('description', $scale->description ?? '') }}</textarea>
</div>

<div class="sm:col-span-2">
    <h3 class="text-sm font-semibold text-slate-900">Grade Bands</h3>
    <p class="mt-1 text-xs text-slate-500">
        Percentage ranges must fall between 0 and 100, minimum must not exceed maximum, and ranges must not overlap. Order is controlled by Sort Order.
    </p>

    @php
        $bands = old('items', isset($scale) ? $scale->items->map(fn ($i) => [
            'id' => $i->id,
            'grade' => $i->grade,
            'min_percentage' => $i->min_percentage,
            'max_percentage' => $i->max_percentage,
            'grade_point' => $i->grade_point,
            'description' => $i->description,
            'sort_order' => $i->sort_order,
            'status' => $i->status,
        ])->all() : [['grade' => '', 'min_percentage' => '', 'max_percentage' => '', 'grade_point' => '', 'description' => '', 'sort_order' => 1, 'status' => 'active']]);
    @endphp

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Sort</th>
                    <th>Grade</th>
                    <th>Min %</th>
                    <th>Max %</th>
                    <th>Grade Point</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($bands as $index => $band)
                    <tr class="border-b">
                        <td class="py-2 pr-2">
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $band['id'] ?? '' }}">
                            <input class="input !w-20" type="number" name="items[{{ $index }}][sort_order]" min="0" max="100000" value="{{ $band['sort_order'] ?? $index + 1 }}">
                        </td>
                        <td class="py-2 pr-2"><input class="input" type="text" name="items[{{ $index }}][grade]" maxlength="20" required value="{{ $band['grade'] ?? '' }}"></td>
                        <td class="py-2 pr-2"><input class="input !w-24" type="number" step="0.01" min="0" max="100" name="items[{{ $index }}][min_percentage]" required value="{{ $band['min_percentage'] ?? '' }}"></td>
                        <td class="py-2 pr-2"><input class="input !w-24" type="number" step="0.01" min="0" max="100" name="items[{{ $index }}][max_percentage]" required value="{{ $band['max_percentage'] ?? '' }}"></td>
                        <td class="py-2 pr-2"><input class="input !w-24" type="number" step="0.01" min="0" max="100" name="items[{{ $index }}][grade_point]" value="{{ $band['grade_point'] ?? '' }}"></td>
                        <td class="py-2 pr-2"><input class="input" type="text" name="items[{{ $index }}][description]" maxlength="2000" value="{{ $band['description'] ?? '' }}"></td>
                        <td class="py-2 pr-2">
                            <select class="input !w-28" name="items[{{ $index }}][status]">
                                @foreach($itemStatuses as $status)
                                    <option value="{{ $status }}" @selected(($band['status'] ?? 'active') === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-2 text-right">
                            <button class="button !bg-rose-100 !text-rose-700" type="button" onclick="this.closest('tr').remove()">Remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
