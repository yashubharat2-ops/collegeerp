<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Department;
use App\Models\GradeScale;
use App\Models\InstitutionalSetting;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** A policy-filtered hub, not a second set of academic masters or write rules. */
class AcademicConfigurationController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAcademicConfiguration', InstitutionalSetting::class);
        $masters = [
            [AcademicYear::class, 'Academic Years', 'academic-years.index', null, 'Session dates and the active academic year.'],
            [AcademicTerm::class, 'Academic Terms / Semesters', 'academic-terms.index', 'academic-terms.create', 'Terms and semester sequences belonging to an academic year.'],
            [Department::class, 'Departments', 'departments.index', 'departments.create', 'The existing shared academic / staff department master.'],
            [Program::class, 'Programs / Courses', 'programs.index', 'programs.create', 'Programs and their department relationships.'],
            [Section::class, 'Sections / Batches', 'sections.index', 'sections.create', 'Batches, capacity, program and academic year.'],
            [Subject::class, 'Subjects', 'subjects.index', 'subjects.create', 'Subjects, credits and assessment definitions.'],
            [Campus::class, 'Campuses', 'campuses.index', null, 'Existing campuses within the active institution.'],
            [GradeScale::class, 'Grade / Pass-Fail Scales', 'grade-scales.index', 'grade-scales.create', 'Existing assessment scales; examination policies remain in force.'],
        ];
        $configuration = [];
        foreach ($masters as [$model, $label, $route, $createRoute, $description]) {
            if (! Gate::allows('viewAny', $model)) {
                continue;
            }
            $configuration[] = [
                'label' => $label, 'route' => $route, 'description' => $description,
                'count' => $model::query()->count(),
                'samples' => $model::query()->orderBy('name')->limit(5)->pluck('name'),
                'createRoute' => $createRoute && Gate::allows('create', $model) ? $createRoute : null,
            ];
        }

        return view('administration.academic-config.index', [
            'configuration' => $configuration,
            'activeYear' => Gate::allows('viewAny', AcademicYear::class) ? AcademicYear::query()->where('status', 'active')->first(['id', 'name', 'starts_on', 'ends_on']) : null,
        ]);
    }
}
