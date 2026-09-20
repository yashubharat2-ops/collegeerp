<?php

namespace App\Http\Requests\ExamSchedule;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreExamScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamSchedule::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');

        $collegeId = app(TenantContext::class)->id();

        // Auto-fill academic_year_id or academic_term_id from examination if not provided
        if ($this->filled('examination_id')) {
            $exam = Examination::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->find($this->input('examination_id'));

            if ($exam) {
                if (! $this->filled('academic_year_id')) {
                    $this->merge(['academic_year_id' => $exam->academic_year_id]);
                }
                if (! $this->filled('academic_term_id')) {
                    $this->merge(['academic_term_id' => $exam->academic_term_id]);
                }
            }
        }

        // Normalize time inputs to HH:MM format
        foreach (['start_time', 'end_time'] as $timeKey) {
            if ($this->filled($timeKey)) {
                $val = trim((string) $this->input($timeKey));
                if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $val, $matches)) {
                    $this->merge([$timeKey => sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2])]);
                }
            }
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $programId = (int) $this->input('program_id');

        return [
            'examination_id' => [
                'required',
                'integer',
                Rule::exists('examinations', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_term_id' => [
                'required',
                'integer',
                Rule::exists('academic_terms', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'program_id' => [
                'required',
                'integer',
                Rule::exists('programs', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'section_id' => [
                'required',
                'integer',
                Rule::exists('sections', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('program_id', $programId)
                    ->whereNull('deleted_at'),
            ],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'faculty_id' => [
                'nullable',
                'integer',
                Rule::exists('faculties', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'campus_id' => [
                'nullable',
                'integer',
                Rule::exists('campuses', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'exam_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:100'],
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'passing_marks' => ['required', 'numeric', 'min:0', 'lte:max_marks'],
            'status' => ['required', Rule::in(ExamSchedule::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $collegeId = app(TenantContext::class)->id();
            $examId = $this->input('examination_id');
            $yearId = $this->input('academic_year_id');
            $termId = $this->input('academic_term_id');
            $progId = $this->input('program_id');
            $sectionId = $this->input('section_id');
            $subjectId = $this->input('subject_id');
            $facultyId = $this->input('faculty_id');
            $campusId = $this->input('campus_id');
            $room = trim((string) $this->input('room'));
            $examDate = $this->input('exam_date');
            $startTime = $this->input('start_time').':00';
            $endTime = $this->input('end_time').':00';

            // 1. Examination context checks
            $exam = Examination::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->find($examId);

            if ($exam) {
                if ($exam->status === Examination::STATUS_CANCELLED) {
                    $v->errors()->add('examination_id', 'Cannot schedule exams for a cancelled examination.');
                }
                if ($exam->academic_year_id != $yearId) {
                    $v->errors()->add('academic_year_id', 'The academic year must match the examination academic year.');
                }
                if ($exam->academic_term_id != $termId) {
                    $v->errors()->add('academic_term_id', 'The academic term must match the examination academic term.');
                }
            }

            // 2. Term / Year check
            $term = AcademicTerm::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->find($termId);
            if ($term && $term->academic_year_id != $yearId) {
                $v->errors()->add('academic_term_id', 'The selected academic term does not belong to the selected academic year.');
                $v->errors()->add('academic_year_id', 'The selected academic term does not belong to the selected academic year.');
            }

            // 3. Section / Year / Program check
            $section = Section::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->find($sectionId);
            if ($section) {
                if ($section->academic_year_id != $yearId) {
                    $v->errors()->add('section_id', 'The selected section does not belong to the selected academic year.');
                    $v->errors()->add('academic_year_id', 'The selected section does not belong to the selected academic year.');
                }
                if ($section->program_id != $progId) {
                    $v->errors()->add('section_id', 'The selected section does not belong to the selected program.');
                    $v->errors()->add('program_id', 'The selected section does not belong to the selected program.');
                }
            }

            // 4. Duplicate schedule entry check (same examination, section, subject, date, start_time)
            $isDuplicate = ExamSchedule::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->where('examination_id', $examId)
                ->where('section_id', $sectionId)
                ->where('subject_id', $subjectId)
                ->where('exam_date', $examDate)
                ->where('start_time', $startTime)
                ->exists();

            if ($isDuplicate) {
                $v->errors()->add('subject_id', 'This schedule entry for section, subject, date, and start time already exists.');
                $v->errors()->add('examination_id', 'This schedule entry for section, subject, date, and start time already exists.');
            }

            // 5. Timetable conflict detection (overlap logic on same exam_date within college)
            $overlapQuery = ExamSchedule::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->where('status', '!=', ExamSchedule::STATUS_CANCELLED)
                ->where('exam_date', $examDate)
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime);

            // 5a. Section conflict
            if ((clone $overlapQuery)->where('section_id', $sectionId)->exists()) {
                $v->errors()->add('section_id', 'This section is already scheduled for another exam during this time slot.');
            }

            // 5b. Faculty conflict
            if ($facultyId && (clone $overlapQuery)->where('faculty_id', $facultyId)->exists()) {
                $v->errors()->add('faculty_id', 'This faculty member is already assigned to another exam during this time slot.');
            }

            // 5c. Room conflict
            if ($room !== '') {
                $roomOverlap = (clone $overlapQuery)->where('room', $room);
                if ($campusId) {
                    $roomOverlap->where(function ($q) use ($campusId) {
                        $q->where('campus_id', $campusId)->orWhereNull('campus_id');
                    });
                }
                if ($roomOverlap->exists()) {
                    $v->errors()->add('room', 'This room is already occupied by another exam during this time slot.');
                }
            }
        });
    }
}
