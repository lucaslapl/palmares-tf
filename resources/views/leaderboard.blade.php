@extends('layouts.app')

@section('title', 'Leaderboard — palmares.tf')

@section('content')
<h1 class="page-title">Leaderboard</h1>

<div class="tabs">
    @foreach (['all' => 'All', '6v6' => '6v6', '9v9' => '9v9'] as $key => $label)
    <a class="tab {{ $format === $key ? 'is-active' : '' }}"
       href="{{ route('leaderboard', $key === 'all' ? [] : ['format' => $key]) }}">{{ $label }}</a>
    @endforeach
</div>

<p class="muted">{{ number_format($total) }} ranked players</p>

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
            <th class="num">Awards</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($players as $i => $player)
        <tr>
            <td class="rank">{{ ($page - 1) * $perPage + $i + 1 }}</td>
            <td>
                @if ($player['etf2l_id'] > 0)
                    <a href="{{ route('player.show', ['id' => $player['etf2l_id']]) }}">{{ $player['name'] }}</a>
                @else
                    {{ $player['name'] }}
                @endif
                @if (! empty($player['banned']))
                    <span class="badge badge-ban" title="ETF2L ban until {{ \Illuminate\Support\Carbon::createFromTimestamp($player['ban_until'])->toDayDateTimeString() }}">Banned</span>
                @endif
            </td>
            <td class="muted">@include('partials.flag', ['country' => $player['country']])</td>
            <td class="num strong">{{ number_format($player['points']) }}</td>
            <td class="num">{{ $player['golds'] }}</td>
            <td class="num">{{ $player['silvers'] }}</td>
            <td class="num">{{ $player['bronzes'] }}</td>
            <td class="num muted">{{ $player['awards'] }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="8" class="empty">
                No rankings available yet — rankings are computed every day.
            </td>
        </tr>
        @endforelse
    </tbody>
</table>

@if ($pages > 1)
<div class="pager">
    @if ($page > 1)
        <a href="{{ route('leaderboard', ['format' => $format, 'page' => $page - 1]) }}">← Previous</a>
    @else
        <span class="disabled">← Previous</span>
    @endif
    <span class="muted">Page {{ $page }} / {{ $pages }}</span>
    @if ($page < $pages)
        <a href="{{ route('leaderboard', ['format' => $format, 'page' => $page + 1]) }}">Next →</a>
    @else
        <span class="disabled">Next →</span>
    @endif
</div>
@endif
@endsection