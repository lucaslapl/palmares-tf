<?php

declare(strict_types=1);

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminLogsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeasonController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');

Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/api/search', [SearchController::class, 'autocomplete'])->name('search.api');

Route::get('/players/{id}', [PlayerController::class, 'show'])->name('player.show')->where('id', '[0-9]+');

Route::get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
Route::get('/seasons/{season}', [SeasonController::class, 'show'])->name('seasons.show')->where('season', '[0-9]+');

/*
|--------------------------------------------------------------------------
| Panel admin (monitoring)
|--------------------------------------------------------------------------
|
| Protégé par deux couches : un lien secret /admin/{token} (ADMIN_ACCESS_TOKEN
| dans .env) pose le flag « magic », puis le login classique (ADMIN_USERNAME /
| ADMIN_PASSWORD_HASH) autorise le dashboard. Sans lien secret tout renvoie
| 404, le panel reste invisible aux scanners.
|
*/

Route::prefix('admin')->name('admin.')->group(function (): void {
    // Formulaire + soumission du login (nécessite le lien secret posé en
    // session). Le POST est borné par le rate limiter « admin-login ».
    Route::middleware('admin.magic')->group(function (): void {
        Route::get('/login', [AdminAuthController::class, 'login'])->name('login');
        Route::post('/login', [AdminAuthController::class, 'authenticate'])
            ->middleware('throttle:admin-login')
            ->name('login.attempt');
    });

    // Dashboard et journal (nécessite lien secret + session authentifiée).
    Route::middleware('admin.access')->group(function (): void {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/logs', [AdminLogsController::class, 'index'])->name('logs');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
    });

    // Lien secret — déclaré en dernier pour ne pas entrer en collision avec
    // les routes statiques ci-dessus (login, logs…).
    Route::get('/{token}', [AdminAuthController::class, 'enter'])
        ->where('token', '[A-Za-z0-9_-]+')
        ->name('access');
});
