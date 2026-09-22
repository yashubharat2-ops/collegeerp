<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryMemberService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\LibraryMember\StoreLibraryMemberRequest;
use App\Http\Requests\LibraryMember\UpdateLibraryMemberRequest;
use App\Models\LibraryMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Library Members (Library Management).
 *
 * Members reference an existing student enrollment. They are not a second
 * student register.
 */
class LibraryMemberController extends Controller
{
    public function __construct(private readonly LibraryMemberService $members)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LibraryMember::class);

        $query = LibraryMember::query()
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program'])
            ->orderBy('member_code')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('member_code', 'like', $needle)
                    ->orWhereHas('studentEnrollment', function ($enrollment) use ($needle): void {
                        $enrollment->where('enrollment_number', 'like', $needle)
                            ->orWhereHas('student', function ($student) use ($needle): void {
                                $student->where('first_name', 'like', $needle)
                                    ->orWhere('last_name', 'like', $needle)
                                    ->orWhere('student_number', 'like', $needle);
                            });
                    });
            });
        }

        if (in_array($request->input('status'), LibraryMember::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('library_members.index', [
            'members' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => LibraryMember::STATUSES,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LibraryMember::class);

        return view('library_members.create', [
            'enrollments' => LibraryFormOptions::enrollments(),
            'statuses' => LibraryMember::STATUSES,
            'selectedEnrollmentId' => $request->input('student_enrollment_id'),
        ]);
    }

    public function store(StoreLibraryMemberRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $member = $this->members->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('library-members.show', $member)
            ->with('success', "Library member {$member->member_code} created.");
    }

    public function show(string $library_member): View
    {
        $member = $this->findScoped($library_member);
        $this->authorize('view', $member);

        $member->load([
            'studentEnrollment.student',
            'studentEnrollment.academicYear',
            'studentEnrollment.program',
            'creator:id,name',
            'updater:id,name',
            'transactions.bookCopy.book',
        ]);

        return view('library_members.show', ['member' => $member]);
    }

    public function edit(string $library_member): View
    {
        $member = $this->findScoped($library_member);
        $this->authorize('update', $member);

        return view('library_members.edit', [
            'member' => $member->load(['studentEnrollment.student', 'studentEnrollment.academicYear']),
            'statuses' => LibraryMember::STATUSES,
        ]);
    }

    public function update(UpdateLibraryMemberRequest $request, string $library_member): RedirectResponse
    {
        $member = $this->findScoped($library_member);
        $member = $this->members->update($member, $request->validated(), $request->user());

        return redirect()
            ->route('library-members.show', $member)
            ->with('success', "Library member {$member->member_code} updated.");
    }

    public function destroy(string $library_member): RedirectResponse
    {
        $member = $this->findScoped($library_member);
        $this->authorize('delete', $member);

        $code = $member->member_code;
        $this->members->delete($member, request()->user());

        return redirect()
            ->route('library-members.index')
            ->with('success', "Library member {$code} deleted.");
    }

    private function findScoped(string $id): LibraryMember
    {
        return LibraryMember::query()->findOrFail($id);
    }
}
