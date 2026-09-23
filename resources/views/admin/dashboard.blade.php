@extends('admin.layout')

@section('title', 'Dashboard — palmares.tf admin')

@section('meta')
    @include('admin.partials.refresh')
@endsection

@section('content')
<h1 class="page-title">Pipeline overview</h1>

<section class="kpi-grid">
    <div class="kpi">
        <div class="kpi-value">{{ number_format($overview['seasons']) }}</div>
        <div class="kpi-label">Seasons</div>
        @if ($overview['pending_seasons'] > 0)
            <div class="kpi-sub warn">{{ $overview['pending_seasons'] }} pending tables</div>
        @endif
    </div>
    <div class="kpi">
        <div class="kpi-value">{{ number_format($overview['teams']) }}</div>
        <div class="kpi-label">Teams</div>
    </div>
    <div class="kpi">
        <div class="kpi-value">{{ number_format($overview['players']) }}</div>
        <div class="kpi-label">Players</div>
        @if ($overview['uncomputed'] > 0)
            <div class="kpi-sub warn">{{ $overview['uncomputed'] }} to compute</div>
        @else
            <div class="kpi-sub ok">all computed</div>
        @endif
    </div>
    <div class="kpi">
        <div class="kpi-value">{{ number_format($overview['palmares']) }}</div>
        <div class="kpi-label">Palmarès entries</div>
        <div class="kpi-sub">{{ number_format($overview['awarded_players']) }} awarded players</div>
    </div>
    <div class="kpi kpi-medals">
        <div class="kpi-value">
            <span class="medal medal-gold">{{ number_format($overview['medals']['gold']) }}</span>
            <span class="medal medal-silver">{{ number_format($overview['medals']['silver']) }}</span>
            <span class="medal medal-bronze">{{ number_format($overview['medals']['bronze']) }}</span>
        </div>
        <div class="kpi-label">Medals gold / silver / bronze</div>
    </div>
    <div class="kpi">
        <div class="kpi-value">{{ number_format($overview['cache_entries']) }}</div>
        <div class="kpi-label">API cache entries</div>
        <div class="kpi-sub">{{ admin_age($overview['last_cache_fetch']) }} last call</div>
    </div>
    <div class="kpi">
        <div class="kpi-value">{{ admin_bytes($overview['db_size']) }}</div>
        <div class="kpi-label">Database size</div>
        <div class="kpi-sub">{{ admin_bytes($overview['data_dir_size']) }} data dir</div>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Scheduler health</h2>
        <a href="{{ route('admin.logs') }}">Logs →</a>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>Command</th>
                <th>Interval</th>
                <th>Last run</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($schedules as $schedule)
            <tr>
                <td class="monospace">{{ $schedule['command'] }}</td>
                <td class="muted">{{ admin_interval($schedule['interval_s']) }}</td>
                <td class="muted">{{ $schedule['last_run_s'] !== null ? admin_age_ago($schedule['last_run_s']) : 'never' }}</td>
                <td>
                    @if ($schedule['running'])
                        <span class="badge badge-running">running</span>
                    @elseif ($schedule['status'] === 'ok')
                        <span class="badge badge-ok">ok</span>
                    @elseif ($schedule['status'] === 'failed')
                        <span class="badge badge-error">failed</span>
                    @elseif ($schedule['status'] === 'overdue')
                        <span class="badge badge-warn">overdue</span>
                    @else
                        <span class="badge badge-muted">never</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Recent runs</h2>
        <span class="muted">latest {{ count($runs) }} executions</span>
    </div>
    @if ($runs === [])
        <p class="muted">No execution recorded yet — run <span class="monospace">app:status</span> or wait for the scheduler.</p>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Command</th>
                    <th>Started</th>
                    <th>Duration</th>
                    <th>Exit</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                <tr>
                    <td class="monospace">{{ $run['command'] }}</td>
                    <td class="muted">{{ admin_age($run['started_at']) }}</td>
                    <td class="muted">{{ $run['finished_at'] !== null ? admin_duration($run['started_at'], $run['finished_at']) : '—' }}</td>
                    <td class="monospace">{{ $run['exit_code'] ?? '—' }}</td>
                    <td>
                        @if ($run['finished_at'] === null)
                            <span class="badge badge-running">running</span>
                        @elseif ($run['exit_code'] === 0)
                            <span class="badge badge-ok">ok</span>
                        @else
                            <span class="badge badge-error" title="{{ $run['error'] }}">failed</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Public JSON freshness</h2>
        <span class="muted">generated by app:generate-json</span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>File</th>
                <th>Size</th>
                <th>Age</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($jsonFiles as $file)
            <tr>
                <td class="monospace">{{ $file['name'] }}</td>
                <td class="muted">{{ $file['exists'] ? admin_bytes($file['size']) : '—' }}</td>
                <td class="muted">{{ $file['age_s'] !== null ? admin_age_ago($file['age_s']) : 'missing' }}</td>
                <td>
                    @if (! $file['stale'])
                        <span class="badge badge-ok">fresh</span>
                    @else
                        <span class="badge badge-warn">{{ $file['exists'] ? 'stale' : 'missing' }}</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Data quality</h2>
        <span class="muted">anomalies detected in database</span>
    </div>
    <ul class="quality-list">
        @foreach ($quality as $issue)
        <li>
            <span class="quality-count {{ $issue['count'] > 0 ? 'warn' : 'ok' }}">{{ number_format($issue['count']) }}</span>
            <span class="quality-label">{{ $issue['label'] }}</span>
        </li>
        @endforeach
    </ul>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>ETF2L API cache</h2>
        <span class="muted monospace">{{ $api['base_url'] }}</span>
    </div>
    <div class="kpi-grid api-grid">
        <div class="kpi">
            <div class="kpi-value">{{ admin_age($api['last_fetch']) }}</div>
            <div class="kpi-label">Last API call</div>
        </div>
        <div class="kpi">
            <div class="kpi-value">{{ admin_age($api['oldest_fetch']) }}</div>
            <div class="kpi-label">Oldest cached response</div>
        </div>
        <div class="kpi">
            <div class="kpi-value">{{ $api['throttle']['exists'] ? admin_age_ago($api['throttle']['age_s'] ?? 0) : 'n/a' }}</div>
            <div class="kpi-label">Throttle lock last touch</div>
        </div>
    </div>
    @if ($api['by_endpoint'] !== [])
        <table class="table">
            <thead>
                <tr>
                    <th>Endpoint</th>
                    <th>Entries</th>
                    <th>Last fetch</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($api['by_endpoint'] as $endpoint => $stats)
                <tr>
                    <td class="monospace">/{{ $endpoint }}/…</td>
                    <td>{{ number_format($stats['count']) }}</td>
                    <td class="muted">{{ admin_age($stats['last_fetch'] ?? 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection