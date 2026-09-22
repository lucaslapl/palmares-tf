<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="palmares.tf — Competitive TF2 player rankings based on ETF2L season records (gold, silver and bronze team medals).">
    <title>@yield('title', 'palmares.tf')</title>
    <link rel="preconnect" href="https://avatars.steamstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ palmares_asset('_css/app.css') }}">
</head>
<body>
    <header class="site-header">
        <div class="container nav">
            <a class="brand" href="{{ route('home') }}">palmares<span>.tf</span></a>
            <nav class="nav-links">
                <a href="{{ route('leaderboard') }}">Leaderboard</a>
                <a href="{{ route('seasons.index') }}">Seasons</a>
                <a href="{{ route('search') }}">Search</a>
            </nav>
        </div>
    </header>

    <main class="container">
        @yield('content')
    </main>

    <footer class="site-footer container">
        <p>
            palmares.tf — Team Fortress 2 competitive records from
            <a href="https://etf2l.org" rel="noopener" target="_blank">ETF2L</a>.
            Player rankings are based on season team medals (gold 3 / silver 2 / bronze 1 points).
        </p>
    </footer>
</body>
</html>