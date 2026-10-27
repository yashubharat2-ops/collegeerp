<?php

namespace Tests\Feature\Academics;

use App\Models\{
    AcademicCalendarEvent,
    AcademicTerm,
    AcademicTimetable,
    AcademicYear,
    Campus,
    Faculty,
    Program,
    Section,
    Subject
};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Regression cover for three confirmed Academic defects.
 *
 *  1. `storeTimetable()` validated its optional campus as
 *     `['nullable', ...refRules()]`. `nullable` only skips the remaining rules
 *     for an explicit null while `required` is an implicit rule that still
 *     fails, so the rule list stayed REQUIRED — and the create form has no
 *     campus control at all. Every "Save timetable" therefore failed validation
 *     and no timetable entry could ever be created.
 *
 *  2. The same rule shape made a calendar event's OPTIONAL academic term
 *     required, so the form's "All terms — year-wide event" option could never
 *     be saved (the column is nullable in the schema).
 *
 *  3. `academic-timetables.update/destroy` and `academic-calendar.update/destroy`
 *     were registered, permission-gated endpoints with NO control on any screen:
 *     the listings rendered no actions column, so a mistyped timetable slot or
 *     calendar event could never be corrected or removed.
 */
class AcademicTimetableCalendarWorkflowTest extends TestCase
{
    use DepartmentTestHelpers;

    private function context($college): array
    {
        $year = AcademicYear::create([
            'college_id' => $college->id, 'name' => '2026-27', 'code' => 'AY26',
            'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'Term 1',
            'code' => 'T1', 'type' => 'term', 'sequence' => 1, 'status' => 'active',
        ]);
        $campus = Campus::create(['college_id' => $college->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'B.Com', 'code' => 'BCOM', 'status' => 'active']);
        $section = Section::create([
            'college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id,
            'campus_id' => $campus->id, 'name' => 'A', 'code' => 'A', 'status' => 'active',
        ]);
        $subject = Subject::create(['college_id' => $college->id, 'code' => 'ACC101', 'name' => 'Accounting', 'status' => 'active']);
        $faculty = Faculty::create([
            'college_id' => $college->id, 'employee_code' => 'F001', 'first_name' => 'Ada',
            'last_name' => 'Lovelace', 'status' => 'active',
        ]);

        return compact('year', 'term', 'campus', 'program', 'section', 'subject', 'faculty');
    }

    private function timetablePayload(array $context, array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $context['year']->id,
            'academic_term_id' => $context['term']->id,
            'program_id' => $context['program']->id,
            'section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'faculty_id' => $context['faculty']->id,
            'day_of_week' => 2,
            'period' => 3,
            'start_time' => '11:00',
            'end_time' => '12:00',
            'room' => 'R-12',
            'status' => 'active',
        ], $overrides);
    }

    private function calendarPayload(array $context, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Foundation Day',
            'event_type' => 'holiday',
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'academic_year_id' => $context['year']->id,
            'status' => 'published',
        ], $overrides);
    }

    public function test_a_timetable_entry_can_be_saved_without_a_campus(): void
    {
        $college = $this->makeCollege('TTCAMP');
        $user = $this->makeUserWithPermissions($college, ['academic_timetables.view', 'academic_timetables.create']);
        $context = $this->context($college);

        // No campus_id key at all: exactly what the create form posts.
        $this->asCollege($college, $user)
            ->post(route('academic-timetables.store'), $this->timetablePayload($context))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-timetables.index'));

        $this->assertDatabaseHas('academic_timetables', [
            'college_id' => $college->id,
            'section_id' => $context['section']->id,
            'day_of_week' => 2,
            'campus_id' => null,
        ]);
    }

    public function test_a_calendar_event_can_be_saved_without_a_term(): void
    {
        $college = $this->makeCollege('CALNONE');
        $user = $this->makeUserWithPermissions($college, ['academic_calendar.view', 'academic_calendar.create']);
        $context = $this->context($college);

        // The form's "All terms — year-wide event" option posts an empty term.
        $this->asCollege($college, $user)
            ->post(route('academic-calendar.store'), $this->calendarPayload($context, ['academic_term_id' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-calendar.index'));

        $this->assertDatabaseHas('academic_calendar_events', [
            'college_id' => $college->id,
            'title' => 'Foundation Day',
            'academic_term_id' => null,
        ]);
    }

    public function test_a_calendar_term_must_belong_to_the_selected_academic_year(): void
    {
        $college = $this->makeCollege('CALMIX');
        $user = $this->makeUserWithPermissions($college, ['academic_calendar.view', 'academic_calendar.create']);
        $context = $this->context($college);
        $otherYear = AcademicYear::create([
            'college_id' => $college->id, 'name' => '2027-28', 'code' => 'AY27',
            'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30', 'status' => 'active',
        ]);

        // Year B with a term that belongs to year A is contradictory data.
        $this->asCollege($college, $user)
            ->post(route('academic-calendar.store'), $this->calendarPayload($context, [
                'title' => 'Mismatched event',
                'academic_year_id' => $otherYear->id,
                'academic_term_id' => $context['term']->id,
            ]))
            ->assertSessionHasErrors('academic_term_id');

        $this->assertDatabaseMissing('academic_calendar_events', ['title' => 'Mismatched event']);
    }

    public function test_a_timetable_entry_can_be_edited_and_updated_from_the_listing(): void
    {
        $college = $this->makeCollege('TTEDIT');
        $user = $this->makeUserWithPermissions($college, [
            'academic_timetables.view', 'academic_timetables.create',
            'academic_timetables.update', 'academic_timetables.delete',
        ]);
        $context = $this->context($college);
        $entry = AcademicTimetable::create($this->timetablePayload($context) + ['college_id' => $college->id]);

        $this->asCollege($college, $user)
            ->get(route('academic-timetables.edit', $entry))
            ->assertOk()
            ->assertSee('Edit timetable entry')
            ->assertSee('R-12');

        $this->asCollege($college, $user)
            ->put(route('academic-timetables.update', $entry), [
                'day_of_week' => 4,
                'period' => 5,
                'start_time' => '13:00',
                'end_time' => '14:00',
                'room' => 'R-99',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('academic_timetables', [
            'id' => $entry->id,
            'day_of_week' => 4,
            'room' => 'R-99',
        ]);
    }

    public function test_the_timetable_listing_offers_the_edit_and_delete_controls(): void
    {
        $college = $this->makeCollege('TTLIST');
        $user = $this->makeUserWithPermissions($college, [
            'academic_timetables.view', 'academic_timetables.create',
            'academic_timetables.update', 'academic_timetables.delete',
        ]);
        $context = $this->context($college);
        $entry = AcademicTimetable::create($this->timetablePayload($context) + ['college_id' => $college->id]);

        $this->asCollege($college, $user)
            ->get(route('academic-timetables.index'))
            ->assertOk()
            ->assertSee(route('academic-timetables.edit', $entry), false)
            ->assertSee(route('academic-timetables.destroy', $entry), false)
            ->assertSee('Delete');
    }

    public function test_a_timetable_entry_can_be_deleted_from_the_listing(): void
    {
        $college = $this->makeCollege('TTDEL');
        $user = $this->makeUserWithPermissions($college, [
            'academic_timetables.view', 'academic_timetables.create', 'academic_timetables.delete',
        ]);
        $context = $this->context($college);
        $entry = AcademicTimetable::create($this->timetablePayload($context) + ['college_id' => $college->id]);

        $this->asCollege($college, $user)
            ->delete(route('academic-timetables.destroy', $entry))
            ->assertRedirect();

        $this->assertSoftDeleted('academic_timetables', ['id' => $entry->id]);
    }

    public function test_a_calendar_event_can_be_edited_and_deleted_from_the_listing(): void
    {
        $college = $this->makeCollege('CALEDIT');
        $user = $this->makeUserWithPermissions($college, [
            'academic_calendar.view', 'academic_calendar.create',
            'academic_calendar.update', 'academic_calendar.delete',
        ]);
        $context = $this->context($college);
        $event = AcademicCalendarEvent::create($this->calendarPayload($context) + ['college_id' => $college->id]);
        $nextYear = AcademicYear::create([
            'college_id' => $college->id, 'name' => '2027-28', 'code' => 'AY27N',
            'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30', 'status' => 'active',
        ]);

        $this->asCollege($college, $user)
            ->get(route('academic-calendar.edit', $event))
            ->assertOk()
            ->assertSee('Edit calendar event');

        // The academic year is part of the form now (updateCalendar used to drop
        // it silently, so the control and the write path disagreed).
        $this->asCollege($college, $user)
            ->put(route('academic-calendar.update', $event), $this->calendarPayload($context, [
                'title' => 'Foundation Day (revised)',
                'academic_year_id' => $nextYear->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-calendar.index'));

        $this->assertDatabaseHas('academic_calendar_events', [
            'id' => $event->id,
            'title' => 'Foundation Day (revised)',
            'academic_year_id' => $nextYear->id,
        ]);

        $this->asCollege($college, $user)
            ->delete(route('academic-calendar.destroy', $event))
            ->assertRedirect();

        $this->assertSoftDeleted('academic_calendar_events', ['id' => $event->id]);
    }
}
