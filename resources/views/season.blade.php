@extends('layouts.app')

@section('title', $season->name.' — palmares.tf')

@section('content')
<div class="season-header">
    <p class="muted">{{ $format }} season</p>
    <h1 class="page-title">{{ $season->name }}</h1>
    @if ($season->archived)
        <p>
            <a href="https://etf2l.org/etf2l/archives/{{ $season->etf2l_competition_id }}/" rel="noopener" target="_blank">
                View season on ETF2L archives ↗
            </a>
        </p>
    @endif
</div>

@foreach ($divisions as $division => $teams)
<section class="panel">
    <div class="panel-head">
        <h2>{{ $division }}</h2>
        <span class="muted">{{ $crowds[$division] }} teams</span>
    </div>
    <table class="table table-compact">
        <thead>
            <tr>
                <th class="num">Place</th>
                <th>Team</th>
                <th>Country</th>
                <th>Medal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($teams as $team)
            <tr>
                <td class="num">
                    @if ($team->ach)
                        {{ $team->ach }}<sup>{{ ordinal($team->ach) }}</sup>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    @if ($team->etf2l_team_id)
                        <a href="https://etf2l.org/teams/{{ $team->etf2l_team_id }}/" rel="noopener" target="_blank">{{ $team->name }}</a>
                    @else
                        {{ $team->name }}
                    @endif
                </td>
                <td class="muted">@include('partials.flag', ['country' => $team->country ?? ''])</td>
                <td>
                    @if ($team->medal)
                        <span class="medal-badge medal-{{ $team->medal }}">{{ ucfirst($team->medal) }}</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</section>
@endforeach
@endsection