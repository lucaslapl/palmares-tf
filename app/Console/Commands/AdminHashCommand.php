<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Génère un hash bcrypt à placer dans ADMIN_PASSWORD_HASH (.env) pour le
 * panel admin. Le mot de passe n'est jamais stocké en clair.
 */
final class AdminHashCommand extends Command
{
    protected $signature = 'app:admin-hash';

    protected $description = 'Génère un hash bcrypt pour ADMIN_PASSWORD_HASH du panel admin';

    public function handle(): int
    {
        $password = $this->secret('Mot de passe du panel admin ?');

        if ($password === null || $password === '') {
            $this->error('Mot de passe vide : aucun hash généré.');

            return self::FAILURE;
        }

        $this->comment('Copiez cette valeur dans ADMIN_PASSWORD_HASH (.env) :');
        $this->line((string) password_hash($password, PASSWORD_BCRYPT));

        return self::SUCCESS;
    }
}
