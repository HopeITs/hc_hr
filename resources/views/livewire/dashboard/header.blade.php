{{--
    The top of the dashboard (prompt 7, Dashboard\Header): the greeting, large,
    and the job title. The late strip and the decisions are tiles in the grid
    (late-card, decisions-card).
--}}
<div class="hc-dash-header" data-testid="dashboard-header">
    <section class="hc-hello">
        <h1 class="hc-hello-greeting" data-testid="dashboard-headline">{{ $greeting }}</h1>
        @if ($jobTitle !== '')
            <p class="text-hint text-ink-2" data-testid="dashboard-job-title">{{ $jobTitle }}</p>
        @endif
    </section>
</div>
