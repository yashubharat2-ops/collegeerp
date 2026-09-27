@extends('layouts.app')
@section('title', 'Certificate Templates')
@section('content')
@include('certificates._navigation')
<div class="panel mb-6">
    <h2 class="panel-title">Add a template</h2>
    <p class="panel-subtitle">Each certificate type supports multiple templates. Templates are plain text, not executable HTML or Blade. Add a new template for a revision; generated documents keep their original snapshot.</p>
    <form method="POST" action="{{ route('certificates.templates.store') }}" class="space-y-4">@csrf
        <label class="block">Certificate type<select class="input" name="certificate_type_id" required>@foreach($types as $type)<option value="{{ $type->id }}" @selected(old('certificate_type_id') == $type->id)>{{ $type->name }}</option>@endforeach</select></label>
        <label class="block">Template name<input class="input" name="name" required maxlength="255" value="{{ old('name') }}"></label>
        <label class="block">Body<textarea class="input font-mono" name="body" rows="10" required maxlength="20000">{{ old('body') }}</textarea></label>
        <p class="text-sm">Supported placeholders:</p><div class="flex flex-wrap gap-3 text-sm font-mono">@foreach(\App\Services\Certificates\CertificateWorkflow::PLACEHOLDERS as $placeholder)<code>&#123;&#123; {{ $placeholder }} &#125;&#125;</code>@endforeach</div>
        <button class="button">Add template</button>
    </form>
</div>
@forelse($templates as $template)<div class="panel mb-4"><h2 class="panel-title">{{ $template->name }} — {{ $template->type?->name }}</h2><pre class="whitespace-pre-wrap font-sans">{{ $template->body }}</pre></div>@empty<p>No templates yet. Add a college-approved template before generating a certificate.</p>@endforelse
{{ $templates->links() }}
@endsection
