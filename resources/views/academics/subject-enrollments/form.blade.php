@extends('layouts.app')
@section('title', 'Enroll Student Subject')
@section('content')
<form method="POST" action="{{ route('academic-subject-enrollments.store') }}" class="card grid gap-4 md:grid-cols-2">
    @csrf
    <label>Student
        <select class="input" name="student_id" required><option value="">Select</option>
            @foreach($students as $student)<option value="{{ $student->id }}">{{ $student->fullName() }}</option>@endforeach
        </select>
    </label>
    <label>Student enrollment
        <select class="input" name="student_enrollment_id" required><option value="">Select</option>
            @foreach($enrollments as $enrollment)<option value="{{ $enrollment->id }}">{{ $enrollment->enrollment_number }} — {{ $enrollment->student?->fullName() }}</option>@endforeach
        </select>
    </label>
    <label>Academic year
        <select class="input" name="academic_year_id" required><option value="">Select</option>
            @foreach($years as $year)<option value="{{ $year->id }}">{{ $year->name }} ({{ $year->code }})</option>@endforeach
        </select>
    </label>
    <label>Academic term
        <select class="input" name="academic_term_id" required><option value="">Select</option>
            @foreach($terms as $term)<option value="{{ $term->id }}">{{ $term->name }} ({{ $term->code }})</option>@endforeach
        </select>
    </label>
    <label>Program
        <select class="input" name="program_id" required><option value="">Select</option>
            @foreach($programs as $program)<option value="{{ $program->id }}">{{ $program->name }}{{ $program->code ? ' ('.$program->code.')' : '' }}</option>@endforeach
        </select>
    </label>
    <label>Section
        <select class="input" name="section_id" required><option value="">Select</option>
            @foreach($sections as $section)<option value="{{ $section->id }}">{{ $section->name }} ({{ $section->code }})</option>@endforeach
        </select>
    </label>
    <label>Subject
        <select class="input" name="subject_id" required><option value="">Select</option>
            @foreach($subjects as $subject)<option value="{{ $subject->id }}">{{ $subject->name }} ({{ $subject->code }})</option>@endforeach
        </select>
    </label>
    <label>Status<select class="input" name="status"><option>active</option><option>dropped</option><option>completed</option></select></label>
    <label>Enrollment date<input class="input" type="date" name="enrollment_date" value="{{ now()->toDateString() }}" required></label>
    <label class="md:col-span-2">Remarks<textarea class="input" name="remarks"></textarea></label>
    <button class="btn-primary w-fit">Save enrollment</button>
</form>
@endsection
