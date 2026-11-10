{{--
    The top of the dashboard (prompt 7, Dashboard\Header): the greeting as a
    small line and a headline that says what waits. The late strip and the
    decisions are tiles in the grid now (late-card, decisions-card).
--}}
<div class="hc-dash-header" data-testid="dashboard-header">
    <section class="hc-hello">
        <h1 class="text-h1 text-ink" data-testid="dashboard-headline">
            <small class="hc-hello-greeting">{{ $greeting }}</small>
            {{ $headline ?? $greeting }}
        </h1>
        @if ($jobTitle !== '')
            <p class="text-hint text-ink-2" data-testid="dashboard-job-title">{{ $jobTitle }}</p>
        @endif
    </section>
</div>
