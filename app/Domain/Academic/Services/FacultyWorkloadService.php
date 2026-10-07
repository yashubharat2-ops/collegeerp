<?php

namespace App\Domain\Academic\Services;

use App\Models\AcademicTimetable;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Faculty Workload — the derived read model behind the workload screen.
 *
 * Workload is NOT a stored fact: no workload table exists, and nothing is
 * duplicated. Every line is a live GROUP BY over the active timetable entries
 * of one faculty member for one (subject, section, academic year, term), so
 * the screen and its CSV export can never disagree about what a line contains.
 *
 * Two consumers share this one definition:
 *
 *  - {@see self::paginate()} — the Academic → Faculty Workload screen;
 *  - {@see self::groupsForRepresentatives()} — the bulk export, which receives
 *    the representative timetable ids the user actually selected (already
 *    re-queried and authorized inside the college by the bulk action handler)
 *    and re-derives each group from them.
 *
 * Portability: duration arithmetic is performed in PHP because SQLite and MySQL
 * do not share a time-difference function. Every query runs through the model,
 * so the CollegeScope (tenant isolation) applies to all of them.
 */
class FacultyWorkloadService
{
    /**
     * The grouped, paginated workload listing.
     *
     * Each item keeps the aggregate columns the screen renders (`periods`,
     * `faculty_id`, `subject_id`, `section_id`, …) plus `rep_id`: the lowest
     * active timetable id of that group, used as the row's selection value by
     * the bulk-action bar.
     */
    public function paginate(int $perPage = 30): LengthAwarePaginator
    {
        $items = $this->groupedQuery()->paginate($perPage);

        $items->getCollection()->transform(function (Model $item): Model {
            $item->weekly_hours = $this->weeklyHoursFor($item);

            return $item;
        });

        return $items;
    }

    /**
     * The workload groups behind a set of representative timetable ids.
     *
     * @param  array<int, int>  $representativeIds
     * @return Collection<int, Model>  One item per distinct group, in the order of first appearance.
     */
    public function groupsForRepresentatives(array $representativeIds): Collection
    {
        $representatives = AcademicTimetable::query()
            ->with(['faculty', 'subject', 'section', 'academicYear', 'academicTerm'])
            ->whereIn('id', $representativeIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $groups = new Collection();
        $seen = [];

        foreach ($representatives as $representative) {
            $key = $this->groupKey($representative);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $weeklyHours = $this->weeklyHoursFor($representative);

            $group = clone $representative;
            $group->periods = $this->periodsFor($representative);
            $group->weekly_hours = $weeklyHours;
            $group->rep_id = (int) $representative->getKey();

            $groups->push($group);
        }

        return $groups;
    }

    /**
     * Active timetable entries grouped by faculty / subject / section / year / term.
     */
    private function groupedQuery()
    {
        return AcademicTimetable::query()
            ->select(
                'faculty_id',
                'subject_id',
                'section_id',
                'academic_year_id',
                'academic_term_id',
                DB::raw('count(*) as periods'),
                DB::raw('min(id) as rep_id'),
            )
            ->where('status', 'active')
            ->with(['faculty', 'subject', 'section', 'academicYear', 'academicTerm'])
            ->groupBy('faculty_id', 'subject_id', 'section_id', 'academic_year_id', 'academic_term_id');
    }

    /**
     * The (faculty, subject, section, year, term) identity of a workload group.
     */
    private function groupKey(Model $entry): string
    {
        return implode(':', [
            (int) $entry->faculty_id,
            (int) $entry->subject_id,
            (int) $entry->section_id,
            (int) $entry->academic_year_id,
            (int) $entry->academic_term_id,
        ]);
    }

    /**
     * Periods (active timetable entries) in one group.
     */
    private function periodsFor(Model $group): int
    {
        return $this->groupEntries($group)->count();
    }

    /**
     * Weekly teaching hours of one group, summed from the stored start/end times.
     */
    private function weeklyHoursFor(Model $group): float
    {
        return (float) $this->groupEntries($group)->sum(function (Model $entry): float {
            $start = Carbon::parse($entry->start_time);
            $end = Carbon::parse($entry->end_time);

            if ($end->lessThan($start)) {
                $end->addDay();
            }

            return $start->diffInMinutes($end) / 60;
        });
    }

    /**
     * Every active timetable entry of the same group as the given entry.
     */
    private function groupEntries(Model $group): Collection
    {
        return AcademicTimetable::query()
            ->where('status', 'active')
            ->where('faculty_id', $group->faculty_id)
            ->where('subject_id', $group->subject_id)
            ->where('section_id', $group->section_id)
            ->where('academic_year_id', $group->academic_year_id)
            ->where('academic_term_id', $group->academic_term_id)
            ->get(['id', 'start_time', 'end_time']);
    }
}
