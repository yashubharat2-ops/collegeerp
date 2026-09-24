{{--
    Content fields shared by the send and edit notification forms.
    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div class="sm:col-span-2">
    <label class="label" for="title">Title</label>
    <input class="input" id="title" name="title" type="text" value="{{ old('title', $notification->title) }}" required maxlength="255" placeholder="e.g. Fee receipt available">
    <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="notification_type">Notification type</label>
    <input class="input" id="notification_type" name="notification_type" type="text" list="notification-form-type-options" value="{{ old('notification_type', $notification->notification_type) }}" required maxlength="50" placeholder="e.g. reminder">
    <datalist id="notification-form-type-options">
        @foreach($types as $typeValue => $typeLabel)
            <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
        @endforeach
    </datalist>
    <p class="mt-1 text-xs text-slate-500">Pick a suggestion or type your own category.</p>
    <p class="mt-1 text-xs text-rose-600">@error('notification_type'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="priority">Priority</label>
    <select class="input" id="priority" name="priority" required>
        @foreach($priorities as $priority)
            <option value="{{ $priority }}" @selected(old('priority', $notification->priority ?? 'normal') === $priority)>{{ ucfirst($priority) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('priority'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="message">Message</label>
    <textarea class="input" id="message" name="message" rows="6" required maxlength="5000" placeholder="Plain text; line breaks are preserved.">{{ old('message', $notification->message) }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('message'){{ $message }}@enderror</p>
</div>
