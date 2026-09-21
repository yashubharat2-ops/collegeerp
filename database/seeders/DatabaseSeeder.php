<?php

namespace Database\Seeders;

use App\Models\{College, Permission, Role, User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $college = College::firstOrCreate(['code' => 'DEMO'], ['name' => 'Demo College', 'slug' => 'demo-college', 'status' => 'active']);
        $permissions = collect([
            'dashboard.view',
            'colleges.view', 'colleges.update',
            'campuses.view', 'campuses.create', 'campuses.update', 'campuses.delete',
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
            'academic-years.view', 'academic-years.create', 'academic-years.update',
            'academic_terms.view', 'academic_terms.create', 'academic_terms.update', 'academic_terms.delete',
            'programs.view', 'programs.create', 'programs.update', 'programs.delete',
            'sections.view', 'sections.create', 'sections.update', 'sections.delete',
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete',
            'faculty_subject_assignments.view', 'faculty_subject_assignments.create', 'faculty_subject_assignments.update', 'faculty_subject_assignments.delete',
            'admission_applicants.view', 'admission_applicants.create', 'admission_applicants.update', 'admission_applicants.delete',
            'admission_enquiries.view', 'admission_enquiries.create', 'admission_enquiries.update', 'admission_enquiries.delete',
            'admission_applications.view', 'admission_applications.create', 'admission_applications.update', 'admission_applications.delete',
            'admission_dashboard.view',
            'admission_documents.view', 'admission_documents.create', 'admission_documents.update', 'admission_documents.delete', 'admission_documents.verify',
            'admission_document_types.view', 'admission_document_types.create', 'admission_document_types.update', 'admission_document_types.delete',
            'admission_merit.view', 'admission_merit.create', 'admission_merit.update', 'admission_merit.delete', 'admission_merit.publish',
            'admissions.view', 'admissions.create', 'admissions.update', 'admissions.delete',
            'students.view', 'students.create', 'students.update', 'students.delete',
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update', 'student_enrollments.delete',
            'student_academic_records.view', 'student_academic_records.create', 'student_academic_records.update', 'student_academic_records.delete',
            'student_documents.view', 'student_documents.create', 'student_documents.update', 'student_documents.delete',
            'student_id_cards.view', 'student_id_cards.generate',
            'student_promotions.view', 'student_promotions.create', 'student_promotions.approve',
            'student_transfers.view', 'student_transfers.create', 'student_transfers.update', 'student_transfers.approve',
            'student_history.view',
            'admission_reports.view',
            'settings.view', 'settings.update',
            'academic_subject_enrollments.view','academic_subject_enrollments.create','academic_subject_enrollments.update','academic_subject_enrollments.delete',
            'academic_sections.view','academic_sections.manage',
            'academic_timetables.view','academic_timetables.create','academic_timetables.update','academic_timetables.delete',
            'academic_attendance.view','academic_attendance.create','academic_attendance.update',
            'academic_calendar.view','academic_calendar.create','academic_calendar.update','academic_calendar.delete',
            'academic_workload.view',
            'examinations.view', 'examinations.create', 'examinations.update', 'examinations.delete',
            'exam_schedules.view', 'exam_schedules.create', 'exam_schedules.update', 'exam_schedules.delete',
            'exam_attendance.view', 'exam_attendance.create', 'exam_attendance.update', 'exam_attendance.delete',
            'exam_marks.view', 'exam_marks.create', 'exam_marks.update', 'exam_marks.delete',
            // Examinations Phase 3 — Results, Calculation, Grade / Pass-Fail, Publishing.
            'results.view', 'results.view_unpublished',
            'result_calculation.view', 'result_calculation.calculate', 'result_calculation.recalculate',
            'grade_scales.view', 'grade_scales.create', 'grade_scales.update', 'grade_scales.delete',
            'result_publishing.view', 'result_publishing.publish', 'result_publishing.unpublish',
            // Examinations Phase 4A — Marksheets (derived printable documents; read-only).
            'marksheets.view',
            // Examinations Phase 4 — Grade Cards, Exam Reports, Student Result History (read-only).
            'grade_cards.view',
            'exam_reports.view',
            'student_result_history.view',
            'roles.view', 'roles.update',
            'permissions.view',
        ])->mapWithKeys(fn ($slug) => [$slug => Permission::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug), 'module' => Str::before($slug, '.'), 'action' => Str::after($slug, '.')])]);
        $super = Role::firstOrCreate(['college_id' => null, 'slug' => 'super-admin'], ['name' => 'Super Admin', 'is_system' => true]);
        $admin = Role::firstOrCreate(['college_id' => $college->id, 'slug' => 'college-admin'], ['name' => 'College Admin', 'is_system' => true]);
        $permissionIds = $permissions->values()->map->getKey()->all();
        $super->permissions()->sync($permissionIds);
        $admin->permissions()->sync($permissionIds);
        $user = User::firstOrCreate(['email' => 'test@example.com'], ['name' => 'Test User', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->syncWithoutDetaching([$college->id => ['is_default' => true]]);
        $user->roles()->syncWithoutDetaching([$super->id => ['college_id' => null], $admin->id => ['college_id' => $college->id]]);
    }
}
