# Image locale fournissant PHP 8.5 + Composer 2 + Node 22+ et toutes les
# extensions nécessaires (pdo_sqlite, gd, zip, curl, mbstring, bcmath, xml…).
# Aucun pull réseau au build (le registry Docker est inaccessible dans cet env).
FROM sail-8.5/app:latest

USER root

WORKDIR /var/www/html

# Le volume monté depuis l'hôte Windows n'appartient pas à root → autoriser git.
RUN git config --global --add safe.directory /var/www/html

# L'entrypoint de l'image sail attend un nom d'utilisateur en premier argument ;
# on le remplace par un pass-through qui évalue la commande du service compose
# (string shlex ou argument unique) puis l'exécute.
ENTRYPOINT ["/bin/bash", "-c", "eval \"$@\"", "bash"]