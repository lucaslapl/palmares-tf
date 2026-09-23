<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deuxième couche du panel admin : exige le flag de session « authentifié » en
 * plus du lien secret. Sans lui, la page renvoie 404 pour rester invisible aux
 * scanners (pas de révélation de l'existence du panel).
 */
final class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('admin_magic_ok', false)
            || ! $request->session()->get('admin_authenticated', false)) {
            abort(404);
        }

        return $next($request);
    }
}
