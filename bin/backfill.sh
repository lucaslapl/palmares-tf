#!/usr/bin/env bash
#
# Backfill continu du palmarès (phase initiale de production).
# Enchaîne app:compute-palmares en tranches de --runtime jusqu'à épuisement
# des joueurs en attente (l'option --exit-on-empty fait alors sortir la
# boucle avec le code 4 → fin propre du service systemd).
set -u

cd "$(dirname "$0")/.." || exit 1

if [ ! -f "artisan" ]; then
    echo "artisan introuvable — mauvais répertoire ?" >&2
    exit 1
fi

errors=0
while true; do
    php -d memory_limit=-1 artisan app:compute-palmares --exit-on-empty --runtime=3600
    code=$?

    case "$code" in
        0)
            # Tranche traitée : on continue aussitôt.
            errors=0
            sleep 2
            ;;
        4)
            # Plus aucun joueur en attente : backfill terminé.
            echo "Backfill terminé : plus aucun joueur en attente."
            exit 0
            ;;
        *)
            # Échec (certains joueurs en erreur ou incident) : on accorde un
            # délai et on relance ; si l'échec se répète, on abandonne pour que
            # systemd puisse re-piloter (Restart=always).
            errors=$((errors + 1))
            echo "Exit inattendu ($code) — tentative d'erreur consécutive n°$errors." >&2
            if [ "$errors" -ge 5 ]; then
                echo "Trop d'échecs consécutifs, abandon." >&2
                exit "$code"
            fi
            sleep 10
            ;;
    esac
done