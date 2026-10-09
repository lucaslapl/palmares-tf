@extends('layouts.app')

@section('title', 'Community Stats — palmares.tf')

@section('content')
<h1 class="page-title">Community stats</h1>
<p class="muted stats-intro">
    ETF2L competitive activity since the beginning: league seasons, teams and players
    over time. Player counts include everyone with a recorded podium or playoff run;
    team counts reflect every team listed in the final division tables.
</p>

@php
    $seasonCount = count($stats['seasons']['6s'] ?? []) + count($stats['seasons']['9v9'] ?? []);
@endphp
@if ($seasonCount === 0 && empty($stats['years']))
<div class="panel">
    <p class="empty">No statistics available yet.</p>
</div>
@else
<div class="stats-totals">
    <div class="stats-total">
        <span class="stats-total-value">{{ number_format((int) ($stats['totals']['league_seasons'] ?? 0)) }}</span>
        <span class="stats-total-label">League seasons</span>
    </div>
    <div class="stats-total">
        <span class="stats-total-value">{{ number_format((int) ($stats['totals']['other_competitions'] ?? 0)) }}</span>
        <span class="stats-total-label">Cups &amp; tournaments</span>
    </div>
    <div class="stats-total">
        <span class="stats-total-value">{{ number_format((int) ($stats['totals']['players'] ?? 0)) }}</span>
        <span class="stats-total-label">Ranked players</span>
    </div>
    <div class="stats-total">
        <span class="stats-total-value">{{ number_format((int) ($stats['totals']['team_slots'] ?? 0)) }}</span>
        <span class="stats-total-label">Team season slots</span>
    </div>
</div>

<div class="stats-controls">
    <span class="muted">Format:</span>
    <button type="button" class="stats-filter" data-format="all">All</button>
    <button type="button" class="stats-filter" data-format="6s">6v6</button>
    <button type="button" class="stats-filter" data-format="9v9">9v9</button>
</div>

<section class="panel">
    <div class="panel-head">
        <h2>Competitions per year</h2>
        <span class="muted">League seasons, cups and tournaments held each year</span>
    </div>
    <div class="stats-chart-wrap"><canvas id="chart-years" height="260"></canvas></div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Teams per season</h2>
        <span class="muted">Teams listed in the final division tables</span>
    </div>
    <div class="stats-chart-wrap"><canvas id="chart-teams" height="260"></canvas></div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Players per season</h2>
        <span class="muted">Players with a recorded podium or playoff run</span>
    </div>
    <div class="stats-chart-wrap"><canvas id="chart-players" height="260"></canvas></div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>New vs returning players</h2>
        <span class="muted">First appearance in the format vs players who already played it</span>
    </div>
    <div class="stats-chart-wrap"><canvas id="chart-retention" height="300"></canvas></div>
</section>

<script id="stats-data" type="application/json">@json($stats)</script>
@endif

<script src="{{ palmares_asset('_js/vendor/chart.umd.js') }}"></script>
<script src="{{ palmares_asset('_js/stats.js') }}"></script>
@endsection
