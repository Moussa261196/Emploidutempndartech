# NDARTECH — Emploi du temps en ligne

Page publique (inscrits) + espace administrateur protégé par mot de passe. PHP 8.1+ et SQLite : aucune dépendance, aucune base MySQL à créer.

## Fonctionnement
- `index.php` : les inscrits choisissent leur formation et naviguent de semaine en semaine (lecture seule).
- `admin.php` : l’administrateur se connecte, modifie les cours de la semaine (par formation), écrit une annonce, copie la semaine précédente et change son mot de passe.
- Les données sont dans `data/edt.sqlite` (à sauvegarder régulièrement).

## Installation
1. Envoyez le dossier sur votre hébergement (PHP 8.1+ avec l’extension `pdo_sqlite`, activée par défaut presque partout).
2. Ouvrez `config.php` et remplacez `CHANGER-MOI` par une longue phrase secrète (clé d’installation).
3. Racine web : idéalement pointez le domaine sur le dossier `public/`. Sur un hébergement mutualisé (cPanel) sans cette option, placez le contenu de `public/` dans `public_html` et les dossiers `app/`, `data/` et le fichier `config.php` un niveau au-dessus, ou gardez tout ensemble (les `.htaccess` Apache bloquent `app/`, `data/` et `config.php`).
4. Ouvrez `https://votre-domaine/admin.php` : créez le compte administrateur (clé d’installation, email, mot de passe de 10 caractères minimum). Cochez le pré-remplissage pour obtenir 8 semaines de départ, puis corrigez-les.
5. Activez HTTPS (obligatoire : les mots de passe transitent par le formulaire).

Test local (Windows/Laragon/XAMPP ou PHP installé) : `php -S localhost:8000 -t public`, puis http://localhost:8000.

Nginx : servez `public/` avec `fastcgi_pass` vers PHP-FPM et ajoutez `location ~ ^/(app|data)/ { deny all; }` si ces dossiers sont dans la racine web.

## Sécurité intégrée
Mots de passe hachés (`password_hash`), sessions HTTP-only avec expiration à 2 h d’inactivité, jeton CSRF sur chaque formulaire, requêtes préparées, limite de 5 tentatives de connexion par 15 min et par adresse IP, en-têtes CSP, aucun script en ligne. Mot de passe oublié : supprimez la ligne de la table `users` dans `data/edt.sqlite`, puis recréez le compte sur `admin.php`.
