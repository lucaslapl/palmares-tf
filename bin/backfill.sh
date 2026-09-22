#!/usr/bin/env bash
#
# Backfill continu du palmarès (phase initiale de production, ou relance).
#
# Enchaîne app:backfill par tranches de --runtime jusqu'à épuisement complet
# du pipeline (saisons → tables → joueurs → palmarès → JSON). La commande
# retourne :
#   4 → plus rien à faire : backfill terminé, on sort proprement (code 0 ici)
#   0 → tranche traitée, il reste du travail : on relance aussitôt
#   autre → échec : on accorde un délai, puis on abandonne après 5 échecs
#          consécutifs (le déclencheur, cron Plesk, relancera plus tard).
#
# Le verrou est géré PAR LA COMMANDE app:backfill (backfill.lock) : ce script
# peut donc être appelé en parallèle du cron Plesk sans risque de doublon.
set -u

cd "$(dirname "$0")/.." || exit 1

if [ ! -f "artisan" ]; then
    echo "artisan introuvable — mauvais répertoire ?" >&2
    exit 1
fi

RUNTIME="${RUNTIME:-1800}"
errors=0
while true; do
    php -d memory_limit=-1 artisan app:backfill --runtime="$RUNTIME"
    code=$?

    case "$code" in
        0)
            # Tranche traitée : on continue aussitôt.
            errors=0
            sleep 2
            ;;
        4)
            # Plus rien à faire : backfill terminé.
            echo "Backfill terminé : rien à faire."
            exit 0
            ;;
        *)
            # Échec (étape en erreur ou incident) : on accorde un délai et on
            # relance ; si l'échec se répète, on abandonne pour que le cron
            # Plesk puisse repiloter.
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