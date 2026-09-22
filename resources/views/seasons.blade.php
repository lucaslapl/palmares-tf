@extends('layouts.app')

@section('title', 'Seasons — palmares.tf')

@section('content')
<h1 class="page-title">Season records</h1>

<div class="season-columns">
    @foreach ($byFormat as $format => $seasons)
    <section class="panel">
        <div class="panel-head">
            <h2>{{ $formats[$format]['label'] ?? $format }}</h2>
            <span class="muted">{{ count($seasons) }} seasons</span>
        </div>
        <ul class="season-list">
            @foreach ($seasons as $season)
            <li>
                <a href="{{ route('seasons.show', ['season' => $season->id]) }}">{{ $season->name }}</a>
                @if (! $season->archived)
                    <span class="badge">live</span>
                @endif
            </li>
            @endforeach
        </ul>
    </section>
    @endforeach
</div>
@endsection