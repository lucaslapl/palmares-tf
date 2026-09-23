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

if (! function_exists('country_flag_url')) {
    /**
     * URL du drapeau d'un pays tel qu'hébergé par ETF2L, ou null si la
     * valeur fournie (libellé pays de l'API) est invalide. Seuls sont
     * acceptés les libellés alphabétiques (lettres, espaces) : on garde une
     * whitelist stricte sur cette entrée externe avant de construire l'URL.
     */
    function country_flag_url(string $country): ?string
    {
        $country = trim($country);

        if ($country === '' || preg_match('/^[\p{L} ]+$/u', $country) !== 1) {
            return null;
        }

        return 'https://etf2l.org/images/flags/'.rawurlencode($country).'.gif';
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

if (! function_exists('admin_age')) {
    /**
     * Délai textuel depuis un timestamp Unix, pour le panel admin (UI en
     * anglais) : « 3 h ago », « never » si nul ou nul.
     */
    function admin_age(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return 'never';
        }

        return admin_age_ago(max(0, time() - $timestamp));
    }
}

if (! function_exists('admin_age_ago')) {
    /**
     * Durée textuelle écoulée (secondes absolues), ex. « 12 min ago ».
     */
    function admin_age_ago(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.' s ago',
            $seconds < 3600 => intdiv($seconds, 60).' min ago',
            $seconds < 86400 => intdiv($seconds, 3600).' h ago',
            default => intdiv($seconds, 86400).' d ago',
        };
    }
}

if (! function_exists('admin_duration')) {
    /**
     * Durée entre deux timestamps (secondes), ex. « 42 s ».
     */
    function admin_duration(int $from, int $to): string
    {
        $seconds = max(0, $to - $from);

        return match (true) {
            $seconds < 60 => $seconds.' s',
            $seconds < 3600 => intdiv($seconds, 60).' min',
            default => intdiv($seconds, 3600).' h',
        };
    }
}

if (! function_exists('admin_interval')) {
    /**
     * Intervalle de planification lisible, ex. « every 6 h ».
     */
    function admin_interval(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => 'every '.max(1, intdiv($seconds, 60)).' min',
            default => 'every '.intdiv($seconds, 3600).' h',
        };
    }
}

if (! function_exists('admin_bytes')) {
    /**
     * Taille lisible (octets), ex. « 1.5 MB ».
     */
    function admin_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        for ($value = (float) $bytes; $value >= 1024 && $i < count($units) - 1; $i++) {
            $value /= 1024;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$i];
    }
}
