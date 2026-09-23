<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Première couche du panel admin : exige le flag de session posé par le lien
 * secret (/admin/{token}) avant de laisser accéder au formulaire de login.
 * Sans lien secret, 404 (le login reste invisible aux scanners).
 */
final class EnsureAdminMagic
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('admin_magic_ok', false)) {
            abort(404);
        }

        return $next($request);
    }
}
