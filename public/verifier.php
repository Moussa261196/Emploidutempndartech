<?php
// Page de diagnostic : à SUPPRIMER après l’installation.
$root = is_dir(__DIR__ . '/app') ? __DIR__ : dirname(__DIR__);
$cfg = is_file($root . '/config.php') ? require $root . '/config.php' : [];
$data = $root . '/data';
if (!is_dir($data)) @mkdir($data, 0750, true);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$t = [
    ['PHP 8.1 ou plus (actuel : ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['Extension SQLite (pdo_sqlite) disponible', extension_loaded('pdo_sqlite')],
    ['Dossier data/ accessible en écriture', is_dir($data) && is_writable($data)],
    ['Clé d’installation modifiée dans config.php', ($cfg['setup_key'] ?? 'CHANGER-MOI') !== 'CHANGER-MOI'],
    ['Connexion HTTPS active', $https],
];
header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex');
echo '<!doctype html><meta charset="utf-8"><title>Vérification</title><body style="font-family:system-ui,Arial;max-width:560px;margin:2rem auto;padding:0 1rem"><h1>Vérification de l’installation</h1><ul>';
foreach ($t as [$l, $ok]) echo '<li>' . ($ok ? '✅' : '❌') . ' ' . htmlspecialchars($l) . '</li>';
echo '</ul><p>Quand tout est ✅, <b>supprimez ce fichier</b>, puis ouvrez <a href="admin.php">admin.php</a>.</p>';
