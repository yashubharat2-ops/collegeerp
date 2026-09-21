{{--
    Fee structure form (create + edit).

    Fee components are posted as an indexed array; the shared
    FeeStructureService re-validates missing names, non-negative amounts and
    duplicate fee heads server-side, so the browser is never trusted.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $structure->name ?? '') }}" required maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('name'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $structure->code ?? '') }}" required maxlength="50">
    <p class="mt-1 text-xs text-slate-500">Unique per college, academic year and program.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>

<div>
    <label class="label" for="academic_year_id">Academic Year</label>
    <select class="input" id="academic_year_id" name="academic_year_id" required>
        <option value="">Select academic year</option>
        @foreach($academicYears as $year)
            <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $structure->academic_year_id ?? 0) === $year->id)>{{ $year->name }} ({{ $year->code }})</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('academic_year_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="program_id">Program</label>
    <select class="input" id="program_id" name="program_id" required>
        <option value="">Select program</option>
        @foreach($programs as $program)
            <option value="{{ $program->id }}" @selected((int) old('program_id', $structure->program_id ?? 0) === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('program_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="academic_term_id">Academic Term / Semester (optional)</label>
    <select class="input" id="academic_term_id" name="academic_term_id">
        <option value="">Whole academic year</option>
        @foreach($academicTerms as $term)
            <option value="{{ $term->id }}" data-year-id="{{ $term->academic_year_id }}" @selected((int) old('academic_term_id', $structure->academic_term_id ?? 0) === $term->id)>{{ $term->name }} ({{ $term->code }})</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Leave empty to apply the structure to the whole academic year.</p>
    <p class="mt-1 text-xs text-rose-600">@error('academic_term_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $structure->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Only active structures are treated as the current fee plan.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="2" maxlength="2000">{{ old('description', $structure->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>

<div class="sm:col-span-2">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Fee Components</h3>
            <p class="mt-1 text-xs text-slate-500">
                One row per fee head. Amounts must be zero or positive; each fee head may appear only once per structure.
                Linking a fee category is optional and only classifies the head for reporting.
            </p>
        </div>
        <button class="button !bg-slate-200 !text-slate-700" id="fee-structure-add-item" type="button">+ Add fee component</button>
    </div>

    @php
        $rows = old('items', isset($structure) ? $structure->items->map(fn ($item) => [
            'id' => $item->id,
            'fee_category_id' => $item->fee_category_id,
            'name' => $item->name,
            'amount' => $item->amount,
            'description' => $item->description,
            'sort_order' => $item->sort_order,
            'status' => $item->status,
        ])->all() : [['fee_category_id' => '', 'name' => '', 'amount' => '', 'description' => '', 'sort_order' => 1, 'status' => 'active']]);
    @endphp

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm" id="fee-structure-items">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Sort</th>
                    <th>Fee Category / Name</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $row)
                    <tr class="border-b">
                        <td class="py-2 pr-2">
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $row['id'] ?? '' }}">
                            <input class="input !w-20" type="number" name="items[{{ $index }}][sort_order]" min="0" max="100000" value="{{ $row['sort_order'] ?? $index + 1 }}">
                        </td>
                        <td class="py-2 pr-2">
                            <select class="input mb-1" name="items[{{ $index }}][fee_category_id]">
                                <option value="">No category</option>
                                @foreach($feeCategories as $feeCategory)
                                    <option value="{{ $feeCategory->id }}" @selected((int) ($row['fee_category_id'] ?? 0) === $feeCategory->id)>{{ $feeCategory->name }} ({{ $feeCategory->code }})</option>
                                @endforeach
                            </select>
                            <input class="input" type="text" name="items[{{ $index }}][name]" maxlength="255" required placeholder="e.g. Tuition Fee" value="{{ $row['name'] ?? '' }}">
                            <p class="mt-1 text-xs text-rose-600">@error("items.{$index}.name"){{ $message }}@enderror</p>
                            <p class="mt-1 text-xs text-rose-600">@error("items.{$index}.fee_category_id"){{ $message }}@enderror</p>
                        </td>
                        <td class="py-2 pr-2">
                            <input class="input !w-36" type="number" step="0.01" min="0" name="items[{{ $index }}][amount]" required value="{{ $row['amount'] ?? '' }}">
                            <p class="mt-1 text-xs text-rose-600">@error("items.{$index}.amount"){{ $message }}@enderror</p>
                        </td>
                        <td class="py-2 pr-2"><input class="input" type="text" name="items[{{ $index }}][description]" maxlength="2000" value="{{ $row['description'] ?? '' }}"></td>
                        <td class="py-2 pr-2">
                            <select class="input !w-28" name="items[{{ $index }}][status]">
                                @foreach($itemStatuses as $itemStatus)
                                    <option value="{{ $itemStatus }}" @selected(($row['status'] ?? 'active') === $itemStatus)>{{ ucfirst($itemStatus) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-2 text-right">
                            <button class="button !bg-rose-100 !text-rose-700" type="button" data-remove-item>Remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-1 text-xs text-rose-600">@error('items'){{ $message }}@enderror</p>
</div>

{{-- Repeater template: the placeholder is replaced by the row index in JS. --}}
<template id="fee-structure-item-template">
    <tr class="border-b">
        <td class="py-2 pr-2">
            <input type="hidden" name="items[__INDEX__][id]" value="">
            <input class="input !w-20" type="number" name="items[__INDEX__][sort_order]" min="0" max="100000" value="0">
        </td>
        <td class="py-2 pr-2">
            <select class="input mb-1" name="items[__INDEX__][fee_category_id]">
                <option value="">No category</option>
                @foreach($feeCategories as $feeCategory)
                    <option value="{{ $feeCategory->id }}">{{ $feeCategory->name }} ({{ $feeCategory->code }})</option>
                @endforeach
            </select>
            <input class="input" type="text" name="items[__INDEX__][name]" maxlength="255" required placeholder="e.g. Tuition Fee">
        </td>
        <td class="py-2 pr-2">
            <input class="input !w-36" type="number" step="0.01" min="0" name="items[__INDEX__][amount]" required value="">
        </td>
        <td class="py-2 pr-2"><input class="input" type="text" name="items[__INDEX__][description]" maxlength="2000"></td>
        <td class="py-2 pr-2">
            <select class="input !w-28" name="items[__INDEX__][status]">
                @foreach($itemStatuses as $itemStatus)
                    <option value="{{ $itemStatus }}">{{ ucfirst($itemStatus) }}</option>
                @endforeach
            </select>
        </td>
        <td class="py-2 text-right">
            <button class="button !bg-rose-100 !text-rose-700" type="button" data-remove-item>Remove</button>
        </td>
    </tr>
</template>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('fee-structure-items');
        const addButton = document.getElementById('fee-structure-add-item');
        const template = document.getElementById('fee-structure-item-template');

        if (table && addButton && template) {
            let nextIndex = {{ count($rows) }};

            addButton.addEventListener('click', function () {
                const markup = template.innerHTML.replace(/__INDEX__/g, nextIndex++);
                const row = document.createElement('tbody');
                row.innerHTML = markup.trim();
                table.querySelector('tbody').appendChild(row.firstElementChild);
            });

            table.addEventListener('click', function (event) {
                const remove = event.target.closest('[data-remove-item]');

                if (remove) {
                    remove.closest('tr').remove();
                }
            });
        }

        // A term only belongs to one academic year, so hide the terms of the
        // years that are not selected.
        const yearSelect = document.getElementById('academic_year_id');
        const termSelect = document.getElementById('academic_term_id');

        function filterTerms() {
            if (!yearSelect || !termSelect) {
                return;
            }

            Array.from(termSelect.options).forEach(function (option) {
                if (!option.value) {
                    return;
                }

                const yearId = option.getAttribute('data-year-id');
                option.hidden = Boolean(yearSelect.value) && Boolean(yearId) && yearId !== yearSelect.value;
            });
        }

        if (yearSelect) {
            yearSelect.addEventListener('change', filterTerms);
            filterTerms();
        }
    });
</script>
@endpush
