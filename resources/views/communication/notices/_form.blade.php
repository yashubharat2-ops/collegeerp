{{--
    Notice form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping; nothing
    is rendered as raw HTML. Status is not a form field — it only changes
    through the publish / unpublish / archive actions.
--}}
@php
    $selectedTarget = old('target_type', $notice->target_type ?? 'all');
    $selectedTargetId = (string) old('target_id', $notice->target_id);
@endphp
<div class="sm:col-span-2">
    <label class="label" for="title">Title</label>
    <input class="input" id="title" name="title" type="text" value="{{ old('title', $notice->title) }}" required maxlength="255" placeholder="e.g. Campus closed for maintenance">
    <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="notice_type">Notice type</label>
    <input class="input" id="notice_type" name="notice_type" type="text" list="notice-form-type-options" value="{{ old('notice_type', $notice->notice_type) }}" required maxlength="50" placeholder="e.g. academic">
    <datalist id="notice-form-type-options">
        @foreach($types as $typeValue => $typeLabel)
            <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
        @endforeach
    </datalist>
    <p class="mt-1 text-xs text-slate-500">Pick a suggestion or type your own category; it is stored in a normalised form (e.g. "Sports Event" → sports_event).</p>
    <p class="mt-1 text-xs text-rose-600">@error('notice_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="priority">Priority</label>
    <select class="input" id="priority" name="priority" required>
        @foreach($priorities as $priority)
            <option value="{{ $priority }}" @selected(old('priority', $notice->priority ?? 'normal') === $priority)>{{ ucfirst($priority) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('priority'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="target_type">Target audience</label>
    <select class="input" id="target_type" name="target_type" required data-communication-target-type>
        @foreach($targets as $targetValue => $targetLabel)
            <option value="{{ $targetValue }}" @selected($selectedTarget === $targetValue)>{{ $targetLabel }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Departments, programs and sections are the existing Platform masters of this college.</p>
    <p class="mt-1 text-xs text-rose-600">@error('target_type'){{ $message }}@enderror</p>
</div>
<div data-communication-target-record>
    <label class="label" for="target_id">Target record</label>
    <select class="input" id="target_id" name="target_id">
        <option value="">— Not needed for audience-wide targets —</option>
        @foreach($entityTargets as $entityType => $definition)
            <optgroup label="{{ $definition['label'] }}" data-target-type="{{ $entityType }}">
                @foreach($entityOptions[$entityType] as $entity)
                    <option value="{{ $entity->id }}" @selected($selectedTarget === $entityType && $selectedTargetId === (string) $entity->id)>{{ $entity->name }}{{ $entity->code ? ' ('.$entity->code.')' : '' }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Required only when the audience is a department, program or section.</p>
    <p class="mt-1 text-xs text-rose-600">@error('target_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="publish_at">Publish at</label>
    <input class="input" id="publish_at" name="publish_at" type="datetime-local" value="{{ old('publish_at', $notice->publish_at?->format('Y-m-d\TH:i')) }}" required>
    <p class="mt-1 text-xs text-slate-500">A published notice becomes visible from this moment (a future date schedules it).</p>
    <p class="mt-1 text-xs text-rose-600">@error('publish_at'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="expires_at">Expires at</label>
    <input class="input" id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at', $notice->expires_at?->format('Y-m-d\TH:i')) }}">
    <p class="mt-1 text-xs text-slate-500">Optional. Must be after the publish date.</p>
    <p class="mt-1 text-xs text-rose-600">@error('expires_at'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="content">Content</label>
    <textarea class="input" id="content" name="content" rows="10" required maxlength="50000" placeholder="Write the announcement. Plain text; line breaks are preserved.">{{ old('content', $notice->content) }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('content'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="attachment">Attachment</label>
    @if($notice->exists && $notice->hasAttachment())
        <div class="mb-2 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm">
            <span>Current file: <strong>{{ $notice->attachment_name }}</strong> ({{ $notice->attachmentSizeLabel() }})</span>
            <label class="inline-flex items-center gap-2 text-rose-700">
                <input type="checkbox" name="remove_attachment" value="1" @checked(old('remove_attachment'))> Remove attachment
            </label>
        </div>
    @endif
    <input class="input" id="attachment" name="attachment" type="file" accept="{{ collect($extensions)->map(fn ($ext) => '.'.$ext)->implode(',') }}">
    <p class="mt-1 text-xs text-slate-500">Optional. Allowed: {{ implode(', ', $extensions) }} · max {{ number_format($maxKb / 1024, 1) }} MB. Stored privately; downloads are permission-checked.{{ $notice->exists && $notice->hasAttachment() ? ' Uploading a new file replaces the current one.' : '' }}</p>
    <p class="mt-1 text-xs text-rose-600">@error('attachment'){{ $message }}@enderror</p>
</div>

@push('scripts')
<script>
    // Progressive enhancement only: show the record picker for entity targets
    // and restrict it to the matching group. The server validates regardless.
    (function () {
        const type = document.querySelector('[data-communication-target-type]');
        const wrapper = document.querySelector('[data-communication-target-record]');
        if (!type || !wrapper) return;
        const select = wrapper.querySelector('select');
        const sync = function () {
            const groups = select.querySelectorAll('optgroup[data-target-type]');
            let entity = false;
            groups.forEach(function (group) {
                const match = group.dataset.targetType === type.value;
                entity = entity || match;
                group.hidden = !match;
                group.disabled = !match;
            });
            wrapper.hidden = !entity;
            if (!entity) select.value = '';
        };
        type.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
