@extends('layouts.app')

@section('title', ($player->name ?? 'Player').' — palmares.tf')

@section('content')
<div class="player-header">
    @if ($player->avatar)
        <img class="avatar" src="{{ $player->avatar }}" alt="" loading="lazy">
    @endif
    <div class="player-identity">
        <h1 class="page-title">{{ $player->name }}</h1>
        <p class="muted">
            {{ $player->country ?? 'Unknown country' }}
            @if ($player->steam_id64)
                ·
                <a href="https://steamcommunity.com/profiles/{{ $player->steam_id64 }}" rel="noopener" target="_blank">
                    Steam profile ↗
                </a>
            @endif
        </p>
    </div>

    <div class="stats">
        <div class="stat">
            <span class="stat-value">{{ number_format($totals['points']) }}</span>
            <span class="stat-label">Points</span>
        </div>
        <div class="stat stat-gold">
            <span class="stat-value">{{ $totals['gold'] }}</span>
            <span class="stat-label">Gold</span>
        </div>
        <div class="stat stat-silver">
            <span class="stat-value">{{ $totals['silver'] }}</span>
            <span class="stat-label">Silver</span>
        </div>
        <div class="stat stat-bronze">
            <span class="stat-value">{{ $totals['bronze'] }}</span>
            <span class="stat-label">Bronze</span>
        </div>
        <div class="stat">
            <span class="stat-value">{{ $totals['awards'] }}</span>
            <span class="stat-label">Awards</span>
        </div>
    </div>
</div>

@if ($freshness > 0)
    <p class="muted small">Record last refreshed {{ \Carbon\Carbon::createFromTimestamp($freshness)->diffForHumans() }}.</p>
@endif

<section class="panel">
    <div class="panel-head">
        <h2>Season records</h2>
    </div>

    @if ($awards === [])
        <p class="empty">
            @if ($player->computed_at)
                No season record found for this player yet.
            @else
                This player has not been processed yet — check back in a few minutes.
            @endif
        </p>
    @else
        <table class="table table-compact">
            <thead>
                <tr>
                    <th>Season</th>
                    <th>Format</th>
                    <th>Team</th>
                    <th>Division</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($awards as $award)
                <tr>
                    <td>
                        {{ $award['competition_name'] }}
                        @if ($award['season_time'] > 0)
                            <span class="muted small">({{ \Carbon\Carbon::createFromTimestamp($award['season_time'])->translatedFormat('M Y') }})</span>
                        @endif
                    </td>
                    <td>{{ $award['format'] }}</td>
                    <td>{{ $award['team_name'] }}</td>
                    <td class="muted">{{ $award['division_name'] ?: '—' }}</td>
                    <td>
                        @if ($award['medal'])
                            <span class="medal-badge medal-{{ $award['medal'] }}">{{ $award['medal_label'] }}</span>
                        @elseif ($award['playoff_round'])
                            <span class="badge">{{ $award['playoff_round'] }}</span>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection