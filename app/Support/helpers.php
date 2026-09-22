<?php

declare(strict_types=1);

if (! function_exists('palmares_data_path')) {
    /**
     * Retourne un chemin absolu vers le répertoire de données de palmares.tf
     * (JSON générés par les commandes app:*).
     */
    function palmares_data_path(string $path = ''): string
    {
        $base = storage_path((string) config('palmares.data_dir', 'app/palmares'));

        if ($path === '') {
            return $base;
        }

        return $base.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (! function_exists('ordinal')) {
    /**
     * Suffixe ordinal anglais (1st, 2nd, 3rd, 4th…).
     */
    function ordinal(int $n): string
    {
        $mod100 = $n % 100;

        return match (true) {
            $mod100 >= 11 && $mod100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
    }
}

if (! function_exists('palmares_asset')) {
    /**
     * URL de ressource statique avec cache busting (?v=filemtime), dans l'esprit
     * de hlfr_asset() du site Highlander France.
     */
    function palmares_asset(string $path): string
    {
        $full = public_path($path);

        if (is_file($full)) {
            $version = '?v='.(string) filemtime($full);
        } else {
            $version = '';
        }

        return asset($path).$version;
    }
}
