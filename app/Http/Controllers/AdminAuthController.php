<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Authentification du panel admin : lien secret + login sécurisé.
 *
 * Le lien secret (/admin/{token}, comparé via hash_equals) pose un flag de
 * session puis renvoie vers le login, qui vérifie les identifiants issus de
 * l'environnement (ADMIN_USERNAME / ADMIN_PASSWORD_HASH, hash bcrypt). Aucun
 * utilisateur en base n'est nécessaire.
 */
final class AdminAuthController extends Controller
{
    /**
     * Vérifie le lien secret et ouvre l'accès au formulaire de login.
     */
    public function enter(Request $request, string $token): RedirectResponse
    {
        $expected = (string) config('admin.access_token', '');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        $request->session()->put('admin_magic_ok', true);

        return redirect()->route('admin.login');
    }

    /**
     * Formulaire de connexion (invisible sans lien secret ni session active).
     */
    public function login(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_authenticated', false)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    /**
     * Vérifie les identifiants et pose le flag « authentifié ».
     */
    public function authenticate(Request $request): RedirectResponse
    {
        if ($request->session()->get('admin_authenticated', false)) {
            return redirect()->route('admin.dashboard');
        }

        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $username = (string) config('admin.username', '');
        $hash = (string) config('admin.password_hash', '');

        if ($credentials['username'] === $username
            && $hash !== ''
            && Hash::check($credentials['password'], $hash)) {
            $request->session()->regenerate();
            $request->session()->put('admin_authenticated', true);

            return redirect()->intended(route('admin.dashboard'));
        }

        return back()->withErrors(['password' => 'Invalid username or password.']);
    }

    /**
     * Déconnecte et retire tous les flags de session du panel.
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['admin_magic_ok', 'admin_authenticated']);

        return redirect()->route('home');
    }
}
