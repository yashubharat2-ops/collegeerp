@php
    /*
     * The five-step progress indicator.
     *
     * Only step 1 of a fresh draft is a link-worthy target; every other step is
     * reachable too (data is preserved across steps) but a step is never shown
     * as "current" twice. Completed steps reflect STORED data, so going back
     * does not un-tick a step the applicant already filled in.
     */
    $currentLabel = $labels[$step] ?? '';
@endphp

<nav class="erp-steps" aria-label="Registration progress">
    @foreach($labels as $number => $label)
        @php
            $isCurrent = (int) $number === (int) $step;
            $isDone = ! empty($completed[$number]);
        @endphp
        <div class="erp-steps__item {{ $isCurrent ? 'erp-steps__item--current' : '' }} {{ $isDone && ! $isCurrent ? 'erp-steps__item--done' : '' }}">
            <a href="{{ route('students.registration.step', ['step' => $number]) }}"
               @if($isCurrent) aria-current="step" @endif>
                <span class="erp-steps__num" aria-hidden="true">{{ $isDone && ! $isCurrent ? '✓' : $number }}</span>
                <span class="erp-steps__label">
                    {{ $label }}
                    @if($isCurrent)
                        <span class="erp-visually-hidden">(current step)</span>
                    @endif
                </span>
            </a>
        </div>
    @endforeach
</nav>
