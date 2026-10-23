@props(['parameters' => []])

{{-- Only server-validated canonical values belong in this projection. --}}
@foreach($parameters as $name => $value)
    @if(is_scalar($value) && (string) $value !== '')
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endif
@endforeach
