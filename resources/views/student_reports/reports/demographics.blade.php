<p class="panel-subtitle">One student is counted once, even when they have several matching enrollments. The date range refers to the student's admission date.</p>
<div class="mt-5 rounded-xl bg-indigo-50 p-4"><p class="text-sm text-indigo-700">Matching students</p><p class="text-2xl font-bold text-indigo-900">{{ number_format($studentsCount) }}</p></div>
<div class="mt-6 grid gap-5 lg:grid-cols-2">
    <div>
        <h4 class="font-semibold">Gender</h4>
        <table class="mt-2 w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2">Gender</th><th class="text-right">Students</th></tr></thead><tbody>
            @forelse($rows as $group)
                <tr class="border-b"><td class="py-2">{{ $group->label === 'Not recorded' ? $group->label : ucfirst(str_replace('_', ' ', $group->label)) }}</td><td class="text-right font-medium">{{ number_format($group->total) }}</td></tr>
            @empty
                <tr><td colspan="2" class="py-4 text-slate-500">No students match these filters.</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
        <h4 class="font-semibold">Category &amp; caste</h4>
        <p class="mt-2">Category and caste are not recorded in the existing Student or Admission data. No breakdown is available; the report does not infer these attributes from another field or invent counts.</p>
    </div>
</div>
