{{--
    Shared fields of the SMS / Email template form. The subject line only
    applies to the e-mail channel (SMS templates store no subject).
--}}
<div>
    <label class="label" for="name">Name</label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $template->name) }}" maxlength="255" required>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $template->code) }}" maxlength="50" placeholder="FEE_REMINDER" required>
    <p class="mt-1 text-xs text-slate-500">Unique within this college. Letters, numbers, hyphens and underscores.</p>
</div>
<div>
    <label class="label" for="channel">Channel</label>
    <select class="input" id="channel" name="channel" required>
        @foreach($channels as $value => $label)
            <option value="{{ $value }}" @selected(old('channel', $template->channel) === $value)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $template->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
</div>
<div class="sm:col-span-2">
    <label class="label" for="subject">Subject <span class="text-xs font-normal text-slate-500">(email only)</span></label>
    <input class="input" id="subject" name="subject" type="text" value="{{ old('subject', $template->subject) }}" maxlength="255">
</div>
<div class="sm:col-span-2">
    <label class="label" for="body">Body</label>
    <textarea class="input" id="body" name="body" rows="8" maxlength="5000" required>{{ old('body', $template->body) }}</textarea>
    <p class="mt-1 text-xs text-slate-500">Use <span class="font-mono">@{{ placeholder }}</span> markers (for example <span class="font-mono">@{{ student_name }}</span>) — they are substituted when the template is reused.</p>
</div>
