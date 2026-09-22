@extends('layouts.app')

@section('title', 'palmares.tf — Competitive TF2 rankings')

@section('content')
<div class="hero">
    <h1>Every TF2 champion. One ranking.</h1>
    <p class="hero-sub">
        palmares.tf ranks competitive Team Fortress 2 players by their ETF2L season records —
        the team medals (gold, silver, bronze) won in 6v6 and Highlander across every division.
    </p>
    <form class="hero-search" action="{{ route('search') }}" method="GET">
        <input type="search" name="q" placeholder="Search a player by name…" autocomplete="off">
        <button type="submit">Search</button>
    </form>
    <p class="hero-meta">
        {{ number_format($playerTotal) }} ranked players across {{ number_format($seasonsCount) }} seasons.
    </p>
</div>

@if ($topPlayers !== [])
<section class="panel">
    <div class="panel-head">
        <h2>Top 10 players</h2>
        <a href="{{ route('leaderboard') }}">Full leaderboard →</a>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Player</th>
                <th>Country</th>
                <th class="num">Points</th>
                <th class="num medal medal-gold">Gold</th>
                <th class="num medal medal-silver">Silver</th>
                <th class="num medal medal-bronze">Bronze</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topPlayers as $i => $player)
            <tr>
                <td class="rank">{{ $i + 1 }}</td>
                <td>
                    @if ($player['etf2l_id'] > 0)
                        <a href="{{ route('player.show', ['id' => $player['etf2l_id']]) }}">{{ $player['name'] }}</a>
                    @else
                        {{ $player['name'] }}
                    @endif
                </td>
                <td class="muted">@include('partials.flag', ['country' => $player['country']])</td>
                <td class="num strong">{{ number_format($player['points']) }}</td>
                <td class="num">{{ $player['golds'] }}</td>
                <td class="num">{{ $player['silvers'] }}</td>
                <td class="num">{{ $player['bronzes'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>
@endif
@endsection