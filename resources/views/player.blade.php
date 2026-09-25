@extends('layouts.app')

@section('title', ($player->name ?? 'Player').' — palmares.tf')

@section('content')
<div class="player-header">
    @if ($player->avatar)
        <img class="avatar" src="{{ $player->avatar }}" alt="" loading="lazy">
    @endif
    <div class="player-identity">
        <div class="player-title">
            <h1 class="page-title">{{ $player->name }}</h1>
            @if (! empty($player->ban_until) && $player->ban_until > time())
                <span class="badge badge-ban" title="ETF2L ban until {{ \Illuminate\Support\Carbon::createFromTimestamp($player->ban_until)->toDayDateTimeString() }}">Banned</span>
            @endif
        </div>
        <p class="muted">
            @include('partials.flag', ['country' => $player->country ?? ''])
            @if ($player->etf2l_id)
                <a href="https://etf2l.org/forum/user/{{ $player->etf2l_id }}/" rel="noopener" target="_blank">
                    ETF2L profile ↗
                </a>
            @endif
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
                        @if ($award['season_id'])
                            <a href="{{ route('seasons.show', ['season' => $award['season_id']]) }}">{{ $award['competition_name'] }}</a>
                        @elseif ($award['competition_id'])
                            <a href="https://etf2l.org/etf2l/archives/{{ $award['competition_id'] }}/" rel="noopener" target="_blank">{{ $award['competition_name'] }} ↗</a>
                        @else
                            {{ $award['competition_name'] }}
                        @endif
                        @if ($award['season_time'] > 0)
                            <span class="muted small">({{ \Carbon\Carbon::createFromTimestamp($award['season_time'])->translatedFormat('M Y') }})</span>
                        @endif
                    </td>
                    <td>{{ $award['format'] }}</td>
                    <td>
                        @if ($award['team_etf2l_id'])
                            <a href="https://etf2l.org/teams/{{ $award['team_etf2l_id'] }}/" rel="noopener" target="_blank">{{ $award['team_name'] }}</a>
                        @else
                            {{ $award['team_name'] }}
                        @endif
                    </td>
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