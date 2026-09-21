{{--
    Shared receipt document (screen + print).

    One financial fact only: the amount printed here is the payment's own amount —
    there is no second receipt amount anywhere. The applied ledger figures are
    computed server-side from the assignment.

    XSS safety: every value is echoed through Blade's {{ }} escaping.
--}}
<div class="print-area mx-auto max-w-3xl">
    <div class="overflow-hidden rounded-2xl border-2 border-slate-300 bg-white shadow-sm">
        {{-- Institution header --}}
        <div class="border-b-2 border-slate-800 px-6 py-5 text-center sm:px-8">
            <p class="text-xl font-bold uppercase tracking-wide text-slate-900">{{ $college?->name ?? config('app.name', 'College ERP') }}</p>
            @if($college?->address)
                <p class="mt-1 text-xs text-slate-500">{{ $college->address }}</p>
            @endif
            @if($college?->email || $college?->phone)
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $college?->email }}{{ $college?->email && $college?->phone ? ' · ' : '' }}{{ $college?->phone }}
                </p>
            @endif
            <p class="mt-3 text-sm font-semibold uppercase tracking-widest text-slate-700">Fee Receipt</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $payment->payment_number }}</p>
        </div>

        {{-- Student / payment information --}}
        <div class="grid gap-x-8 gap-y-2 px-6 py-5 text-sm sm:grid-cols-2 sm:px-8">
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Student Name</span>
                <span class="text-right font-semibold">{{ $payment->studentEnrollment?->student?->fullName() ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Student Number</span>
                <span class="text-right font-medium">{{ $payment->studentEnrollment?->student?->student_number ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Enrollment Number</span>
                <span class="text-right font-medium">{{ $payment->studentEnrollment?->enrollment_number ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Program</span>
                <span class="text-right font-medium">{{ $payment->studentEnrollment?->program?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Academic Year</span>
                <span class="text-right font-medium">{{ $payment->studentEnrollment?->academicYear?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Fee Structure</span>
                <span class="text-right font-medium">{{ $payment->feeStructure?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Payment Date</span>
                <span class="text-right font-medium">{{ $payment->payment_date?->format('M d, Y') ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Payment Mode</span>
                <span class="text-right font-medium">{{ ucfirst(str_replace('_', ' ', $payment->payment_mode)) }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Reference Number</span>
                <span class="text-right font-medium">{{ $payment->reference_number ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4 border-b border-dotted border-slate-200 py-1.5">
                <span class="text-slate-500">Received By</span>
                <span class="text-right font-medium">{{ $payment->collector?->name ?? '—' }}</span>
            </div>
        </div>

        {{-- Amount --}}
        <div class="border-y-2 border-slate-800 bg-slate-50 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm font-semibold uppercase tracking-widest text-slate-600">Amount Received</span>
                <span class="text-2xl font-bold text-slate-900">{{ number_format((float) $payment->amount, 2) }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Received with thanks towards the fee assignment shown above.</p>
        </div>

        {{-- Balance position (server-computed ledger) --}}
        @if($ledger)
            <div class="grid gap-x-8 gap-y-2 px-6 py-5 text-sm sm:grid-cols-3 sm:px-8">
                <div class="flex justify-between gap-4 py-1.5">
                    <span class="text-slate-500">Assigned</span>
                    <span class="font-medium">{{ number_format((float) $ledger['assigned'], 2) }}</span>
                </div>
                <div class="flex justify-between gap-4 py-1.5">
                    <span class="text-slate-500">Concession</span>
                    <span class="font-medium">{{ number_format((float) $ledger['concession'], 2) }}</span>
                </div>
                <div class="flex justify-between gap-4 py-1.5">
                    <span class="text-slate-500">Total collected</span>
                    <span class="font-medium">{{ number_format((float) $ledger['net_collected'], 2) }}</span>
                </div>
                <div class="flex justify-between gap-4 py-1.5 sm:col-span-3">
                    <span class="text-slate-600 font-semibold">Outstanding balance</span>
                    <span class="font-bold">{{ number_format((float) $ledger['outstanding'], 2) }}</span>
                </div>
            </div>
        @endif

        <div class="grid gap-6 px-6 py-6 text-xs text-slate-500 sm:grid-cols-2 sm:px-8">
            <p>This is a computer-generated receipt. The receipt number is the recorded payment number and is unique within the college.</p>
            <p class="sm:text-right">Authorised signature: ______________________</p>
        </div>
    </div>
</div>
