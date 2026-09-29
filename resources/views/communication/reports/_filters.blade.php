@if(!empty($activeFilters))
<form method="GET" action="{{ route('communication-reports.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <input type="hidden" name="report" value="{{ $report }}">

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if(in_array('search', $activeFilters, true))
        <div>
            <label for="filter-search" class="block text-xs font-semibold text-slate-700">Search</label>
            <input id="filter-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Title, code, subject, recipient…" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('notice_type', $activeFilters, true))
        <div>
            <label for="filter-notice-type" class="block text-xs font-semibold text-slate-700">Notice Type</label>
            <select id="filter-notice-type" name="notice_type" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Types</option>
                @foreach($noticeTypes as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['notice_type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('notification_type', $activeFilters, true))
        <div>
            <label for="filter-notification-type" class="block text-xs font-semibold text-slate-700">Notification Type</label>
            <select id="filter-notification-type" name="notification_type" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Types</option>
                @foreach($notificationTypes as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['notification_type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('priority', $activeFilters, true))
        <div>
            <label for="filter-priority" class="block text-xs font-semibold text-slate-700">Priority</label>
            <select id="filter-priority" name="priority" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Priorities</option>
                @foreach($priorities as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['priority'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('target_type', $activeFilters, true))
        <div>
            <label for="filter-target-type" class="block text-xs font-semibold text-slate-700">Target Audience</label>
            <select id="filter-target-type" name="target_type" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Audiences</option>
                @foreach($targetTypes as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['target_type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('department_id', $activeFilters, true))
        <div>
            <label for="filter-department" class="block text-xs font-semibold text-slate-700">Department</label>
            <select id="filter-department" name="department_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? null) === $department->id)>
                        {{ $department->name }}{{ $department->code ? ' (' . $department->code . ')' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('program_id', $activeFilters, true))
        <div>
            <label for="filter-program" class="block text-xs font-semibold text-slate-700">Program</label>
            <select id="filter-program" name="program_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected(($filters['program_id'] ?? null) === $program->id)>
                        {{ $program->name }}{{ $program->code ? ' (' . $program->code . ')' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('section_id', $activeFilters, true))
        <div>
            <label for="filter-section" class="block text-xs font-semibold text-slate-700">Section</label>
            <select id="filter-section" name="section_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Sections</option>
                @foreach($sections as $section)
                    <option value="{{ $section->id }}" @selected(($filters['section_id'] ?? null) === $section->id)>{{ $section->name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('channel', $activeFilters, true))
        <div>
            <label for="filter-channel" class="block text-xs font-semibold text-slate-700">Channel</label>
            <select id="filter-channel" name="channel" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Channels</option>
                @foreach($channels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['channel'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('template_id', $activeFilters, true))
        <div>
            <label for="filter-template" class="block text-xs font-semibold text-slate-700">Template</label>
            <select id="filter-template" name="template_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Templates</option>
                @foreach($templateOptions as $templateOption)
                    <option value="{{ $templateOption->id }}" @selected(($filters['template_id'] ?? null) === $templateOption->id)>
                        {{ $templateOption->name }} ({{ $templateOption->code }})
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('recipient_type', $activeFilters, true))
        <div>
            <label for="filter-recipient-type" class="block text-xs font-semibold text-slate-700">Recipient Type</label>
            <select id="filter-recipient-type" name="recipient_type" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Recipient Types</option>
                @foreach($recipientTypes as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['recipient_type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('student_id', $activeFilters, true))
        <div>
            <label for="filter-student" class="block text-xs font-semibold text-slate-700">Student</label>
            <select id="filter-student" name="student_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Students</option>
                @foreach($students as $student)
                    <option value="{{ $student->id }}" @selected(($filters['student_id'] ?? null) === $student->id)>
                        {{ trim($student->first_name . ' ' . $student->last_name) }} ({{ $student->admission_no }})
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('faculty_id', $activeFilters, true))
        <div>
            <label for="filter-faculty" class="block text-xs font-semibold text-slate-700">Faculty / Staff</label>
            <select id="filter-faculty" name="faculty_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Faculty / Staff</option>
                @foreach($facultyList as $member)
                    <option value="{{ $member->id }}" @selected(($filters['faculty_id'] ?? null) === $member->id)>
                        {{ trim($member->first_name . ' ' . $member->last_name) }} ({{ $member->employee_code }})
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('user_id', $activeFilters, true))
        <div>
            <label for="filter-user" class="block text-xs font-semibold text-slate-700">User</label>
            <select id="filter-user" name="user_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Users</option>
                @foreach($users as $userOption)
                    <option value="{{ $userOption->id }}" @selected(($filters['user_id'] ?? null) === $userOption->id)>
                        {{ $userOption->name }} &lt;{{ $userOption->email }}&gt;
                    </option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('status', $activeFilters, true) && !empty($statusOptions))
        <div>
            <label for="filter-status" class="block text-xs font-semibold text-slate-700">Status</label>
            <select id="filter-status" name="status" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <option value="">All Statuses</option>
                @foreach($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array('from', $activeFilters, true))
        <div>
            <label for="filter-from" class="block text-xs font-semibold text-slate-700">From Date</label>
            <input id="filter-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif

        @if(in_array('to', $activeFilters, true))
        <div>
            <label for="filter-to" class="block text-xs font-semibold text-slate-700">To Date</label>
            <input id="filter-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
        </div>
        @endif
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
        <button type="submit" class="rounded-xl bg-teal-600 px-4 py-2 text-xs font-semibold text-white hover:bg-teal-700">Apply Filters</button>
        <a href="{{ route('communication-reports.index', ['report' => $report]) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Reset</a>
    </div>
</form>
@endif
