<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin — palmares.tf')</title>
    <link rel="stylesheet" href="{{ palmares_asset('_css/app.css') }}">
    <link rel="stylesheet" href="{{ palmares_asset('_css/admin.css') }}">
    @yield('meta')
</head>
<body class="admin">
    <header class="site-header">
        <div class="container nav">
            <a class="brand" href="{{ route('admin.dashboard') }}">palmares<span>.tf</span> <em class="admin-tag">admin</em></a>
            <nav class="nav-links">
                <a href="{{ route('admin.dashboard') }}">Dashboard</a>
                <a href="{{ route('admin.logs') }}">Logs</a>
                <form method="POST" action="{{ route('admin.logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="link-button">Logout</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="container admin-main">
        @yield('content')
    </main>
</body>
</html>