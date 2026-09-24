{{--
    Circular form (create + edit).

    XSS safety: every value is echoed through Blade's {{ }} escaping. Status is
    not a form field — it only changes through the workflow actions. Once a
    circular has been published its number is frozen (read-only here, enforced
    by CircularService).
--}}
@php
    $numberLocked = $circular->exists && ! $circular->isDraft();
@endphp
<div>
    <label class="label" for="circular_number">Circular number</label>
    <input class="input {{ $numberLocked ? 'bg-slate-100' : '' }}" id="circular_number" name="circular_number" type="text" value="{{ old('circular_number', $circular->circular_number) }}" required maxlength="50" placeholder="e.g. CIR/2026/014" @readonly($numberLocked)>
    <p class="mt-1 text-xs text-slate-500">{{ $numberLocked ? 'Frozen after publication.' : 'Unique within this college (archived circulars included). Stored upper-cased.' }}</p>
    <p class="mt-1 text-xs text-rose-600">@error('circular_number'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="issue_date">Issue date</label>
    <input class="input" id="issue_date" name="issue_date" type="date" value="{{ old('issue_date', $circular->issue_date?->format('Y-m-d')) }}" required>
    <p class="mt-1 text-xs text-rose-600">@error('issue_date'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="title">Title</label>
    <input class="input" id="title" name="title" type="text" value="{{ old('title', $circular->title) }}" required maxlength="255" placeholder="e.g. Revised examination timetable">
    <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="subject">Subject</label>
    <input class="input" id="subject" name="subject" type="text" value="{{ old('subject', $circular->subject) }}" required maxlength="255" placeholder="The subject line of the circular">
    <p class="mt-1 text-xs text-rose-600">@error('subject'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="target_type">Target audience</label>
    <select class="input" id="target_type" name="target_type" required>
        @foreach($targets as $targetValue => $targetLabel)
            <option value="{{ $targetValue }}" @selected(old('target_type', $circular->target_type ?? 'all') === $targetValue)>{{ $targetLabel }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('target_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="publish_at">Publish at</label>
    <input class="input" id="publish_at" name="publish_at" type="datetime-local" value="{{ old('publish_at', $circular->publish_at?->format('Y-m-d\TH:i')) }}">
    <p class="mt-1 text-xs text-slate-500">Optional. Leave blank to make it effective at the moment it is published.</p>
    <p class="mt-1 text-xs text-rose-600">@error('publish_at'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="expires_at">Expires at</label>
    <input class="input" id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at', $circular->expires_at?->format('Y-m-d\TH:i')) }}">
    <p class="mt-1 text-xs text-slate-500">Optional. Not before the issue date, and after the publish date when one is set.</p>
    <p class="mt-1 text-xs text-rose-600">@error('expires_at'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="content">Content</label>
    <textarea class="input" id="content" name="content" rows="10" required maxlength="50000" placeholder="The body of the circular. Plain text; line breaks are preserved.">{{ old('content', $circular->content) }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('content'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="attachment">Attachment</label>
    @if($circular->exists && $circular->hasAttachment())
        <div class="mb-2 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm">
            <span>Current file: <strong>{{ $circular->attachment_name }}</strong> ({{ $circular->attachmentSizeLabel() }})</span>
            <label class="inline-flex items-center gap-2 text-rose-700">
                <input type="checkbox" name="remove_attachment" value="1" @checked(old('remove_attachment'))> Remove attachment
            </label>
        </div>
    @endif
    <input class="input" id="attachment" name="attachment" type="file" accept="{{ collect($extensions)->map(fn ($ext) => '.'.$ext)->implode(',') }}">
    <p class="mt-1 text-xs text-slate-500">Optional. Allowed: {{ implode(', ', $extensions) }} · max {{ number_format($maxKb / 1024, 1) }} MB. Stored privately; downloads are permission-checked.{{ $circular->exists && $circular->hasAttachment() ? ' Uploading a new file replaces the current one.' : '' }}</p>
    <p class="mt-1 text-xs text-rose-600">@error('attachment'){{ $message }}@enderror</p>
</div>
