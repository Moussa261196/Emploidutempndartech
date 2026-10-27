# NDARTECH — Emploi du temps en ligne

Page publique (inscrits) + espace administrateur protégé par mot de passe. PHP 8.1+ et SQLite : aucune dépendance, aucune base MySQL à créer.

## Fonctionnement
- `index.php` : les inscrits choisissent leur formation et consultent neuf semaines de cours à la fois, avec navigation par période (lecture seule).
- `admin.php` : l’administrateur peut générer un planning validé pour neuf semaines, modifier les cours, écrire une annonce, copier une semaine, gérer les sauvegardes et changer son mot de passe.
- La génération alterne le développement web et l’infographie du lundi au jeudi : chaque formation a un cours le matin et un l’après-midi, et l’attribution de ces créneaux s’inverse chaque semaine. La bureautique occupe l’après-midi lundi et mardi, puis le matin mercredi et jeudi, ainsi que l’unique créneau du vendredi. Les cours du samedi partagent les deux créneaux, dont l’ordre s’inverse chaque semaine. La semaine 1 est celle du 26/10/2026. Les cours existants sont conservés sauf remplacement explicitement demandé, précédé d’une sauvegarde automatique.
- Les données sont dans `data/edt.sqlite` (à sauvegarder régulièrement).
- Dans l’administration, téléchargez régulièrement une sauvegarde JSON. Sa restauration remplace tous les cours et annonces actuels ; elle ne contient pas les comptes administrateur. Une sauvegarde automatique est également créée avant chaque enregistrement, copie ou restauration ; les 20 dernières sont accessibles pour restauration rapide.
- Les cours et annonces ne sont enregistrés que si tous les horaires, titres et chevauchements sont valides. Une erreur conserve les données enregistrées et réaffiche les valeurs saisies pour correction.

## Installation
1. Envoyez le dossier sur votre hébergement (PHP 8.1+ avec l’extension `pdo_sqlite`, activée par défaut presque partout).
2. Ouvrez `config.php` et remplacez `CHANGER-MOI` par une longue phrase secrète (clé d’installation).
3. Racine web : idéalement pointez le domaine sur le dossier `public/`. Sur un hébergement mutualisé (cPanel) sans cette option, placez le contenu de `public/` dans `public_html` et les dossiers `app/`, `data/` et le fichier `config.php` un niveau au-dessus, ou gardez tout ensemble (les `.htaccess` Apache bloquent `app/`, `data/` et `config.php`).
4. Ouvrez `https://votre-domaine/admin.php` : créez le compte administrateur (clé d’installation, email, mot de passe de 10 caractères minimum). Le pré-remplissage génère neuf semaines de planning.
5. Activez HTTPS (obligatoire : les mots de passe transitent par le formulaire).

Test local (Windows/Laragon/XAMPP ou PHP installé) : `php -S localhost:8000 -t public`, puis http://localhost:8000.

Tests de génération du planning : `php tests/schedule_generation_test.php`.

Nginx : servez `public/` avec `fastcgi_pass` vers PHP-FPM et ajoutez `location ~ ^/(app|data)/ { deny all; }` si ces dossiers sont dans la racine web.

## Sécurité intégrée
Mots de passe hachés (`password_hash`), sessions HTTP-only avec expiration à 2 h d’inactivité, jeton CSRF sur chaque formulaire, requêtes préparées, limite de 5 tentatives de connexion par 15 min et par adresse IP, en-têtes CSP, aucun script en ligne. Mot de passe oublié : supprimez la ligne de la table `users` dans `data/edt.sqlite`, puis recréez le compte sur `admin.php`.
