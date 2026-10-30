# NDARTECH — Emploi du temps en ligne

Page publique (inscrits) + espace administrateur protégé par mot de passe. PHP 8.1+ et SQLite : aucune dépendance, aucune base MySQL à créer.

## Fonctionnement
- `index.php` : les inscrits choisissent leur formation et consultent neuf semaines de cours à la fois, avec navigation par période (lecture seule).
- `admin.php` : l’administrateur peut générer un planning validé pour neuf semaines, modifier les cours, écrire une annonce, copier une semaine, gérer les sauvegardes et changer son mot de passe.
- La génération alterne le développement web et l’infographie du lundi au jeudi : chaque formation a un cours le matin et un l’après-midi, et l’attribution de ces créneaux s’inverse chaque semaine. La bureautique a également un cours le matin et l’après-midi du lundi au jeudi, avec un créneau du lundi qui alterne chaque semaine ; son unique cours du vendredi reste toujours de 08h00 à 12h00. Les cours du samedi partagent les deux créneaux, dont l’ordre s’inverse chaque semaine. La semaine 1 est celle du 26/10/2026. L’administrateur peut modifier les cours, sous réserve de respecter ces règles ; une sauvegarde automatique précède chaque modification ou régénération.
- Les données sont dans `data/edt.sqlite` (à sauvegarder régulièrement).
- Dans l’administration, téléchargez régulièrement une sauvegarde JSON. Sa restauration remplace tous les cours et annonces actuels ; elle ne contient pas les comptes administrateur. Une sauvegarde automatique est également créée avant chaque enregistrement, copie ou restauration ; les 20 dernières sont accessibles pour restauration rapide.
- L’administrateur peut personnaliser librement les formations, les créneaux et l’organisation d’une semaine. Les horaires, dates et titres doivent rester valides, et deux cours ne peuvent pas se chevaucher. Une sauvegarde automatique est créée avant l’enregistrement.

## Installation
1. Envoyez le dossier sur votre hébergement (PHP 8.1+ avec l’extension `pdo_sqlite`, activée par défaut presque partout).
2. Ouvrez `config.php` et remplacez `CHANGER-MOI` par une longue phrase secrète (clé d’installation).
3. Racine web : idéalement pointez le domaine sur le dossier `public/`. Sur un hébergement mutualisé (cPanel) sans cette option, placez le contenu de `public/` dans `public_html` et les dossiers `app/`, `data/` et le fichier `config.php` un niveau au-dessus, ou gardez tout ensemble (les `.htaccess` Apache bloquent `app/`, `data/` et `config.php`).
4. Ouvrez `https://votre-domaine/admin.php` : créez le compte administrateur (clé d’installation, email, mot de passe de 10 caractères minimum). Le pré-remplissage génère neuf semaines de planning.
5. Activez HTTPS (obligatoire : les mots de passe transitent par le formulaire).

Test local (Windows/Laragon/XAMPP ou PHP installé) : `php -S localhost:8000 -t public`, puis http://localhost:8000.

Tests de génération du planning : `php tests/schedule_generation_test.php`.

Nginx : servez `public/` avec `fastcgi_pass` vers PHP-FPM et ajoutez `location ~ ^/(app|data)/ { deny all; }` si ces dossiers sont dans la racine web.

## Hébergement InfinityFree avec MySQL

1. Téléversez le contenu de `final-deploy/` directement dans `htdocs/`. `index.php` doit se trouver à la racine de `htdocs/`.
2. Modifiez `config.php` sur le serveur avec le serveur, l’utilisateur, le mot de passe et la base MySQL InfinityFree.
3. Modifiez `setup_key` en utilisant une longue phrase secrète différente pour la première création du compte administrateur.
4. Importez les tables MySQL depuis phpMyAdmin si elles ne sont pas encore créées.
5. Ouvrez `https://votre-domaine/admin.php`, créez ou vérifiez le compte administrateur, puis testez la page publique à la racine.
6. Ne téléversez jamais le dossier `data/`, les sauvegardes SQLite, les outils locaux ou les mots de passe MySQL dans un dépôt public.

## Sécurité intégrée
Mots de passe hachés (`password_hash`), sessions HTTP-only avec expiration à 2 h d’inactivité, jeton CSRF sur chaque formulaire, requêtes préparées, limite de 5 tentatives de connexion par 15 min et par adresse IP, en-têtes CSP, aucun script en ligne. Mot de passe oublié : supprimez la ligne de la table `users` dans `data/edt.sqlite`, puis recréez le compte sur `admin.php`.
