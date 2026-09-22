@extends('layouts.app')

@section('title', 'Search players — palmares.tf')

@section('content')
<div class="hero hero-small">
    <h1>Search players</h1>
    <p class="hero-sub">Find a competitive player by name. Every player who has a record — even without medals — gets a profile.</p>
    <form class="hero-search" action="{{ route('search') }}" method="GET" data-autocomplete>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Player name…" autocomplete="off" data-autocomplete-input>
        <button type="submit">Search</button>
        <div class="autocomplete" data-autocomplete-results hidden></div>
    </form>
</div>

<ul class="search-results" id="search-results">
    @php $q = trim((string) request('q')); @endphp
    @if ($q === '')
        <li class="muted">Type at least a few characters to see suggestions.</li>
    @elseif (! empty($results))
        @foreach ($results as $player)
        <li>
            <a href="{{ route('player.show', ['id' => $player['etf2l_id']]) }}">
                <span class="search-result-name">{{ $player['name'] }}</span>
                @include('partials.flag', ['country' => $player['country']])
            </a>
        </li>
        @endforeach
    @else
        <li class="muted">No player matches “{{ $q }}”.</li>
    @endif
</ul>

<script src="{{ palmares_asset('_js/search.js') }}"></script>
@endsection