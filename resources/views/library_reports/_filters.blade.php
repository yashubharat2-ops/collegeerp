@php
    $show = fn (string $key): bool => in_array($key, $visible, true);
    $statusText = fn (string $status): string => ucfirst(str_replace('_', ' ', $status));
@endphp
<form method="GET" action="{{ route('library-reports.index') }}" class="no-print mt-5 border-t border-slate-200 pt-5">
    <input type="hidden" name="report" value="{{ $report }}">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if($show('search'))
            <label class="text-sm text-slate-700">{{ $searchLabel }}
                <input class="input mt-1" type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="{{ $searchPlaceholder }}">
            </label>
        @endif
        @if($show('book_id'))
            <label class="text-sm text-slate-700">Book / Title
                <select class="input mt-1" name="book_id">
                    <option value="">All books</option>
                    @foreach($books as $book)
                        <option value="{{ $book->id }}" @selected((string) $filters['book_id'] === (string) $book->id)>{{ $book->title }} ({{ $book->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('book_category_id'))
            <label class="text-sm text-slate-700">Book category
                <select class="input mt-1" name="book_category_id">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $filters['book_category_id'] === (string) $category->id)>{{ $category->name }} ({{ $category->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('author_id'))
            <label class="text-sm text-slate-700">Author
                <select class="input mt-1" name="author_id">
                    <option value="">All authors</option>
                    @foreach($authors as $author)
                        <option value="{{ $author->id }}" @selected((string) $filters['author_id'] === (string) $author->id)>{{ $author->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('publisher_id'))
            <label class="text-sm text-slate-700">Publisher
                <select class="input mt-1" name="publisher_id">
                    <option value="">All publishers</option>
                    @foreach($publishers as $publisher)
                        <option value="{{ $publisher->id }}" @selected((string) $filters['publisher_id'] === (string) $publisher->id)>{{ $publisher->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('book_copy_id'))
            <label class="text-sm text-slate-700">Book copy
                <select class="input mt-1" name="book_copy_id">
                    <option value="">All lost / damaged copies</option>
                    @foreach($copyOptions as $copy)
                        <option value="{{ $copy->id }}" @selected((string) $filters['book_copy_id'] === (string) $copy->id)>{{ $copy->accession_number }} · {{ $copy->book?->title ?? 'Copy' }} ({{ $statusText($copy->status) }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('library_member_id'))
            <label class="text-sm text-slate-700">Library member
                <select class="input mt-1" name="library_member_id">
                    <option value="">All members</option>
                    @foreach($members as $member)
                        <option value="{{ $member->id }}" @selected((string) $filters['library_member_id'] === (string) $member->id)>{{ $member->label() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('student_enrollment_id'))
            <label class="text-sm text-slate-700">Student enrollment
                <select class="input mt-1" name="student_enrollment_id">
                    <option value="">All enrollments</option>
                    @foreach($enrollments as $enrollment)
                        <option value="{{ $enrollment->id }}" @selected((string) $filters['student_enrollment_id'] === (string) $enrollment->id)>{{ $enrollment->enrollment_number }} · {{ $enrollment->student?->fullName() ?? 'Student' }} · {{ $enrollment->program?->code ?? 'No program' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('status'))
            <label class="text-sm text-slate-700">{{ $statusLabel }}
                <select class="input mt-1" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusText($status) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('availability'))
            <label class="text-sm text-slate-700">Availability
                <select class="input mt-1" name="availability">
                    <option value="">All availability</option>
                    @foreach($availability as $bucket)
                        <option value="{{ $bucket }}" @selected($filters['availability'] === $bucket)>{{ $statusText($bucket) }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($show('from'))
            <label class="text-sm text-slate-700">{{ $dateLabel }} from
                <input class="input mt-1" type="date" name="from" value="{{ $filters['from'] }}">
            </label>
            <label class="text-sm text-slate-700">{{ $dateLabel }} to
                <input class="input mt-1" type="date" name="to" value="{{ $filters['to'] }}">
            </label>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="button">Apply filters</button>
        <a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('library-reports.index', ['report' => $report]) }}">Clear filters</a>
        <span class="text-xs text-slate-500">Only records from the active college are shown.</span>
    </div>
</form>
