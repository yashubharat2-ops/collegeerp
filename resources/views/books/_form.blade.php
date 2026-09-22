{{--
    Book form (create + edit).

    Expects: $categories, $authors, $publishers, $languages, $statuses,
    $currentYear and (on edit) $book. Every select is built from the
    tenant-scoped option lists, so only the active college's masters appear.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
@php
    $selectedAuthorIds = collect(old('author_ids', isset($book) ? $book->authors->pluck('id')->all() : []))
        ->map(fn ($id) => (string) $id)
        ->all();
@endphp
<div class="sm:col-span-2">
    <label class="label" for="title">Title</label>
    <input class="input" id="title" name="title" type="text" value="{{ old('title', $book->title ?? '') }}" required maxlength="255">
    <p class="mt-1 text-xs text-rose-600">@error('title'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="code">Code</label>
    <input class="input" id="code" name="code" type="text" value="{{ old('code', $book->code ?? '') }}" required maxlength="50" placeholder="e.g. BK-0001">
    <p class="mt-1 text-xs text-slate-500">The library's own catalogue code. Unique among this college's active books; stored upper-cased.</p>
    <p class="mt-1 text-xs text-rose-600">@error('code'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="isbn">ISBN</label>
    <input class="input" id="isbn" name="isbn" type="text" value="{{ old('isbn', $book->isbn ?? '') }}" maxlength="20" placeholder="978-0-13-468599-1">
    <p class="mt-1 text-xs text-slate-500">Optional (theses, local prints and journals may not have one). ISBN-10 or ISBN-13; hyphens and spaces are ignored. Unique within the college.</p>
    <p class="mt-1 text-xs text-rose-600">@error('isbn'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="book_category_id">Category</label>
    <select class="input" id="book_category_id" name="book_category_id" required>
        <option value="">Select a category</option>
        @foreach($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('book_category_id', $book->book_category_id ?? '') === (string) $category->id)>{{ $category->name }} ({{ $category->code }}){{ $category->status !== 'active' ? ' — inactive' : '' }}</option>
        @endforeach
    </select>
    @if($categories->isEmpty())
        <p class="mt-1 text-xs text-amber-700">No book categories exist yet for this college — @can('create', App\Models\BookCategory::class)<a class="underline" href="{{ route('book-categories.create') }}">add one first</a>@else ask an administrator to add one first @endcan.</p>
    @endif
    <p class="mt-1 text-xs text-rose-600">@error('book_category_id'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="publisher_id">Publisher</label>
    <select class="input" id="publisher_id" name="publisher_id">
        <option value="">No publisher on record</option>
        @foreach($publishers as $publisher)
            <option value="{{ $publisher->id }}" @selected((string) old('publisher_id', $book->publisher_id ?? '') === (string) $publisher->id)>{{ $publisher->name }}{{ $publisher->status !== 'active' ? ' — inactive' : '' }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-rose-600">@error('publisher_id'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="author_ids">Authors</label>
    <select class="input min-h-32" id="author_ids" name="author_ids[]" multiple size="6">
        @foreach($authors as $author)
            <option value="{{ $author->id }}" @selected(in_array((string) $author->id, $selectedAuthorIds, true))>{{ $author->name }}{{ $author->status !== 'active' ? ' — inactive' : '' }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Hold Ctrl (⌘ on Mac) to credit more than one author. Authors are shared across the college's catalogue — @can('create', App\Models\Author::class)<a class="underline" href="{{ route('authors.create') }}">add a missing author</a>@else missing authors can be added under Authors / Publishers @endcan.</p>
    <p class="mt-1 text-xs text-rose-600">@error('author_ids'){{ $message }}@enderror @error('author_ids.*'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="edition">Edition</label>
    <input class="input" id="edition" name="edition" type="text" value="{{ old('edition', $book->edition ?? '') }}" maxlength="50" placeholder="e.g. 3rd ed.">
    <p class="mt-1 text-xs text-rose-600">@error('edition'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="publication_year">Publication year</label>
    <input class="input" id="publication_year" name="publication_year" type="number" inputmode="numeric" min="1000" max="{{ $currentYear + 1 }}" step="1" value="{{ old('publication_year', $book->publication_year ?? '') }}">
    <p class="mt-1 text-xs text-rose-600">@error('publication_year'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="language">Language</label>
    <input class="input" id="language" name="language" type="text" list="language-options" value="{{ old('language', $book->language ?? '') }}" maxlength="50" placeholder="e.g. English">
    <datalist id="language-options">
        @foreach($languages as $language)
            <option value="{{ $language }}"></option>
        @endforeach
    </datalist>
    <p class="mt-1 text-xs text-rose-600">@error('language'){{ $message }}@enderror</p>
</div>
<div>
    <label class="label" for="status">Status</label>
    <select class="input" id="status" name="status" required>
        @foreach($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $book->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Inactive titles stay in the catalogue but are flagged as withdrawn from active use.</p>
    <p class="mt-1 text-xs text-rose-600">@error('status'){{ $message }}@enderror</p>
</div>
<div class="sm:col-span-2">
    <label class="label" for="description">Description</label>
    <textarea class="input" id="description" name="description" rows="3" maxlength="5000" placeholder="Summary, subject notes, series information…">{{ old('description', $book->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-rose-600">@error('description'){{ $message }}@enderror</p>
</div>
