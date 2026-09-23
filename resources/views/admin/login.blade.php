<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin sign in — palmares.tf</title>
    <link rel="stylesheet" href="{{ palmares_asset('_css/app.css') }}">
    <link rel="stylesheet" href="{{ palmares_asset('_css/admin.css') }}">
</head>
<body class="admin">
    <main class="container admin-login-wrap">
        <div class="admin-login panel">
            <h1 class="page-title">Admin sign in</h1>
            @if ($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('admin.login.attempt') }}" class="admin-login-form">
                @csrf
                <label>
                    Username
                    <input type="text" name="username" autocomplete="username" required autofocus>
                </label>
                <label>
                    Password
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit" class="btn">Sign in</button>
            </form>
        </div>
    </main>
</body>
</html>