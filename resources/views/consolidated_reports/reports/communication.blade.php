@php
    $notices = $summary['notices'];
    $circulars = $summary['circulars'];
    $notifications = $summary['notifications'];
    $templates = $summary['templates'];
    $sms = $summary['sms'];
    $email = $summary['email'];
@endphp

<p class="panel-subtitle">
    The communication position of the active college, taken from the existing Communication Summary: notices, circulars,
    internal notifications, templates and the recorded SMS / e-mail logs, within the selected date window. Delivery and
    read states are the stored ones.
</p>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><p class="stat-label">Notices</p><p class="stat-value">{{ number_format($notices['total']) }}</p><p class="stat-hint">{{ number_format($notices['live']) }} live</p></div>
    <div class="stat-card"><p class="stat-label">Circulars</p><p class="stat-value">{{ number_format($circulars['total']) }}</p><p class="stat-hint">{{ number_format($circulars['live']) }} live</p></div>
    <div class="stat-card"><p class="stat-label">Notifications</p><p class="stat-value">{{ number_format($notifications['total']) }}</p><p class="stat-hint">{{ number_format($notifications['unread']) }} unread</p></div>
    <div class="stat-card"><p class="stat-label">Templates</p><p class="stat-value">{{ number_format($templates['total']) }}</p><p class="stat-hint">{{ number_format($templates['active']) }} active</p></div>
    <div class="stat-card"><p class="stat-label">SMS logs</p><p class="stat-value">{{ number_format($sms['total']) }}</p><p class="stat-hint">{{ number_format($sms['delivered'] ?? 0) }} delivered · {{ number_format($sms['failed'] ?? 0) }} failed</p></div>
    <div class="stat-card"><p class="stat-label">E-mail logs</p><p class="stat-value">{{ number_format($email['total']) }}</p><p class="stat-hint">{{ number_format($email['delivered'] ?? 0) }} delivered · {{ number_format($email['failed'] ?? 0) }} failed</p></div>
    <div class="stat-card"><p class="stat-label">Total communications</p><p class="stat-value">{{ number_format($summary['total_communications']) }}</p><p class="stat-hint">SMS + e-mail log rows</p></div>
    <div class="stat-card"><p class="stat-label">Failed communications</p><p class="stat-value">{{ number_format($summary['failed_communications']) }}</p><p class="stat-hint">{{ number_format($summary['delivered_communications']) }} delivered</p></div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">Notices and circulars by publication status</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Notices</th><th class="px-3 py-3 text-right">Circulars</th></tr>
            </thead>
            <tbody class="divide-y">
                @foreach(['draft', 'published', 'archived'] as $status)
                    <tr>
                        <td class="px-3 py-3">{{ ucfirst($status) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($notices[$status] ?? 0) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($circulars[$status] ?? 0) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="px-3 py-3 font-medium">Live (published and within its window)</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format($notices['live'] ?? 0) }}</td>
                    <td class="px-3 py-3 text-right font-mono">{{ number_format($circulars['live'] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <h4 class="text-sm font-semibold text-slate-800">SMS / e-mail logs by status</h4>
        <table class="mt-3 w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">SMS</th><th class="px-3 py-3 text-right">E-mail</th></tr>
            </thead>
            <tbody class="divide-y">
                @foreach(['queued', 'sent', 'delivered', 'failed'] as $status)
                    <tr>
                        <td class="px-3 py-3">{{ ucfirst($status) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($sms[$status] ?? 0) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ number_format($email[$status] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<h4 class="mt-8 text-sm font-semibold text-slate-800">Internal notifications by delivery state</h4>
<div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <div class="stat-card"><p class="stat-label">Pending</p><p class="stat-value">{{ number_format($notifications['pending'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Sent</p><p class="stat-value">{{ number_format($notifications['sent'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Delivered</p><p class="stat-value">{{ number_format($notifications['delivered'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Read</p><p class="stat-value">{{ number_format($notifications['read'] ?? 0) }}</p></div>
    <div class="stat-card"><p class="stat-label">Unread</p><p class="stat-value">{{ number_format($notifications['unread'] ?? 0) }}</p></div>
</div>
