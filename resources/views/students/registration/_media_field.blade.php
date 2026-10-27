@php
    /*
     * One row of image uploads (photograph / signature / parent & guardian
     * photographs).
     *
     * Expects:
     *   $fields — [field => ['label' => …, 'column' => …]] (see basic.blade.php)
     *   $value  — fn (string $key) => mixed, the old()/draft lookup
     *
     * Every card keeps the full capability set: preview of the stored file
     * (streamed from the private disk by an authorised route, never a public
     * path), choose/replace, and an explicit "Remove" for a stored file. The
     * server decides what is kept; this partial only renders the controls.
     */
@endphp

<div class="erp-media-grid">
    @foreach($fields as $field => $meta)
        @php $stored = $value($meta['column']); @endphp
        <div class="erp-media">
            <span class="erp-media__label">{{ $meta['label'] }}</span>
            <div class="erp-media__preview" id="preview-{{ $field }}">
                @if($stored)
                    <img src="{{ route('students.registration.image', ['field' => $field]) }}" alt="{{ $meta['label'] }}">
                @else
                    <span class="erp-media__empty">No file yet</span>
                @endif
            </div>
            <input type="file" id="{{ $field }}" name="{{ $field }}"
                   accept="image/jpeg,image/png,image/webp"
                   aria-label="{{ $meta['label'] }}"
                   data-erp-image-input="preview-{{ $field }}">
            @if($stored)
                <label class="erp-check erp-media__remove">
                    <input type="checkbox" name="remove_{{ $field }}" value="1"> Remove
                </label>
            @endif
            <p class="erp-error">@error($field){{ $message }}@enderror</p>
        </div>
    @endforeach
</div>
