@extends('admin.layout')

@section('title', 'Logs — palmares.tf admin')

@section('meta')
    @include('admin.partials.refresh')
@endsection

@section('content')
<h1 class="page-title">Pipeline logs</h1>

<section class="panel">
    <div class="panel-head">
        <h2>Scheduler output</h2>
        <span class="muted monospace">storage/logs/schedule.log</span>
    </div>
    @if (! $scheduleLogExists)
        <p class="muted">No scheduler log yet — start the scheduler (<span class="monospace">docker compose up -d scheduler</span> or cron <span class="monospace">schedule:run</span>) to collect output.</p>
    @elseif ($scheduleLog === [])
        <p class="muted">The scheduler log is empty.</p>
    @else
        <div class="log-block">
            @foreach ($scheduleLog as $line)
                <div class="log-line">{{ $line }}</div>
            @endforeach
        </div>
    @endif
</section>

<section class="panel">
    <div class="panel-head">
        <h2>app:* entries (laravel.log)</h2>
        <span class="muted">recent pipeline activity with severity</span>
    </div>
    @if ($appLog === [])
        <p class="muted">No app:* entries found yet.</p>
    @else
        <div class="log-block">
            @foreach ($appLog as $entry)
                <div class="log-line log-{{ strtolower($entry['severity']) }}">
                    <span class="log-severity">{{ $entry['severity'] }}</span>
                    <span class="log-message">{{ $entry['message'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection