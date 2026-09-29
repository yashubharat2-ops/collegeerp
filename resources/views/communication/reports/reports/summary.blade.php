<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Notices</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['notices']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['notices']['published'] ?? 0) }} published ·
            {{ number_format($summary['notices']['live'] ?? 0) }} live ·
            {{ number_format($summary['notices']['draft'] ?? 0) }} draft ·
            {{ number_format($summary['notices']['archived'] ?? 0) }} archived
        </p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Circulars</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['circulars']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['circulars']['published'] ?? 0) }} published ·
            {{ number_format($summary['circulars']['live'] ?? 0) }} live ·
            {{ number_format($summary['circulars']['draft'] ?? 0) }} draft ·
            {{ number_format($summary['circulars']['archived'] ?? 0) }} archived
        </p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Internal Notifications</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['notifications']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['notifications']['read'] ?? 0) }} read ·
            {{ number_format($summary['notifications']['unread'] ?? 0) }} unread ·
            {{ number_format($summary['notifications']['delivered'] ?? 0) }} delivered ·
            {{ number_format($summary['notifications']['pending'] ?? 0) }} pending
        </p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Communication Templates</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['templates']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['templates']['active'] ?? 0) }} active ·
            {{ number_format($summary['templates']['inactive'] ?? 0) }} inactive ·
            {{ number_format($summary['templates']['sms'] ?? 0) }} SMS ·
            {{ number_format($summary['templates']['email'] ?? 0) }} Email
        </p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Outbound Messages (SMS + Email)</p>
        <p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($summary['total_communications'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-slate-600">
            {{ number_format($summary['delivered_communications'] ?? 0) }} delivered ·
            {{ number_format($summary['failed_communications'] ?? 0) }} failed
        </p>
    </div>

    <div class="rounded-2xl border border-sky-200 bg-sky-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">SMS Channel Logs</p>
        <p class="mt-2 text-3xl font-bold text-sky-900">{{ number_format($summary['sms']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-sky-800">
            {{ number_format($summary['sms']['delivered'] ?? 0) }} delivered ·
            {{ number_format($summary['sms']['sent'] ?? 0) }} sent ·
            {{ number_format($summary['sms']['queued'] ?? 0) }} queued ·
            {{ number_format($summary['sms']['failed'] ?? 0) }} failed
        </p>
    </div>

    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-700">Email Channel Logs</p>
        <p class="mt-2 text-3xl font-bold text-indigo-900">{{ number_format($summary['email']['total'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-indigo-800">
            {{ number_format($summary['email']['delivered'] ?? 0) }} delivered ·
            {{ number_format($summary['email']['sent'] ?? 0) }} sent ·
            {{ number_format($summary['email']['queued'] ?? 0) }} queued ·
            {{ number_format($summary['email']['failed'] ?? 0) }} failed
        </p>
    </div>

    <div class="rounded-2xl border border-teal-200 bg-teal-50/40 p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-700">Delivery &amp; Read Tracking</p>
        <p class="mt-2 text-3xl font-bold text-teal-900">{{ number_format($summary['notifications']['delivered'] ?? 0) }}</p>
        <p class="mt-2 text-xs text-teal-800">
            {{ number_format($summary['notifications']['read'] ?? 0) }} read ·
            {{ number_format($summary['notifications']['sent_only'] ?? 0) }} sent awaiting delivery ·
            {{ number_format($summary['notifications']['undelivered'] ?? 0) }} undelivered
        </p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">Communication Module Breakdown</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Report Area</th>
                    <th class="px-4 py-3">Total Records</th>
                    <th class="px-4 py-3">Primary / Active / Delivered</th>
                    <th class="px-4 py-3">Secondary / Pending / Draft</th>
                    <th class="px-4 py-3">Archived / Inactive / Failed</th>
                    <th class="px-4 py-3">Report Link</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Notice Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['notices']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['notices']['published'] ?? 0) }} Published ({{ number_format($summary['notices']['live'] ?? 0) }} Live)</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['notices']['draft'] ?? 0) }} Draft</td>
                    <td class="px-4 py-3 text-slate-600">{{ number_format($summary['notices']['archived'] ?? 0) }} Archived</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'notices', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Notice Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Circular Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['circulars']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['circulars']['published'] ?? 0) }} Published ({{ number_format($summary['circulars']['live'] ?? 0) }} Live)</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['circulars']['draft'] ?? 0) }} Draft</td>
                    <td class="px-4 py-3 text-slate-600">{{ number_format($summary['circulars']['archived'] ?? 0) }} Archived</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'circulars', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Circular Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Notification Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['notifications']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['notifications']['read'] ?? 0) }} Read</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['notifications']['unread'] ?? 0) }} Unread</td>
                    <td class="px-4 py-3 text-slate-600">{{ number_format($summary['notifications']['pending'] ?? 0) }} Pending</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'notifications', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Notification Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Communication Template Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['templates']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['templates']['active'] ?? 0) }} Active</td>
                    <td class="px-4 py-3 text-sky-700">{{ number_format($summary['templates']['sms'] ?? 0) }} SMS / {{ number_format($summary['templates']['email'] ?? 0) }} Email</td>
                    <td class="px-4 py-3 text-slate-600">{{ number_format($summary['templates']['inactive'] ?? 0) }} Inactive</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'templates', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Template Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">SMS Log Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['sms']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['sms']['delivered'] ?? 0) }} Delivered ({{ number_format($summary['sms']['sent'] ?? 0) }} Sent)</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['sms']['queued'] ?? 0) }} Queued</td>
                    <td class="px-4 py-3 text-rose-700">{{ number_format($summary['sms']['failed'] ?? 0) }} Failed</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'sms_logs', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View SMS Log Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Email Log Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['email']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['email']['delivered'] ?? 0) }} Delivered ({{ number_format($summary['email']['sent'] ?? 0) }} Sent)</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['email']['queued'] ?? 0) }} Queued</td>
                    <td class="px-4 py-3 text-rose-700">{{ number_format($summary['email']['failed'] ?? 0) }} Failed</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'email_logs', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Email Log Report →</a></td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Delivery / Read Tracking Report</td>
                    <td class="px-4 py-3">{{ number_format($summary['notifications']['total'] ?? 0) }}</td>
                    <td class="px-4 py-3 text-emerald-700">{{ number_format($summary['notifications']['delivered'] ?? 0) }} Delivered ({{ number_format($summary['notifications']['read'] ?? 0) }} Read)</td>
                    <td class="px-4 py-3 text-amber-700">{{ number_format($summary['notifications']['pending'] ?? 0) }} Pending ({{ number_format($summary['notifications']['sent_only'] ?? 0) }} Sent)</td>
                    <td class="px-4 py-3 text-slate-600">{{ number_format($summary['notifications']['undelivered'] ?? 0) }} Undelivered</td>
                    <td class="px-4 py-3"><a href="{{ route('communication-reports.index', ['report' => 'tracking', 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}" class="font-semibold text-teal-700 hover:underline">View Tracking Report →</a></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
