<?php
declare(strict_types=1);
$CFG = require __DIR__ . '/../config.php';
date_default_timezone_set($CFG['timezone']);
const FORMS = [1 => ['Développement web', 'f1'], 2 => ['Informatique bureautique', 'f2'], 3 => ['Infographie', 'f3']];
const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
if ($https) header('Strict-Transport-Security: max-age=31536000');
session_name('edt');
session_set_cookie_params(['path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

function db(): PDO {
    static $p = null;
    if (!$p) {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $p = new PDO('sqlite:' . $dir . '/edt.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $p->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, hash TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS courses(id INTEGER PRIMARY KEY, formation INTEGER NOT NULL, day TEXT NOT NULL, hs TEXT NOT NULL, he TEXT NOT NULL, title TEXT NOT NULL, room TEXT NOT NULL DEFAULT '');
            CREATE INDEX IF NOT EXISTS i_courses ON courses(formation, day);
            CREATE TABLE IF NOT EXISTS notes(week TEXT PRIMARY KEY, text TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS attempts(ip TEXT NOT NULL, at INTEGER NOT NULL);");
    }
    return $p;
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function monday(?string $d = null): string {
    $t = ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? strtotime($d) : false;
    return date('Y-m-d', strtotime('monday this week', $t === false ? time() : $t));
}
function addd(string $d, int $n): string { return date('Y-m-d', strtotime("$d $n days")); }
function fd(string $d): string { return date('d/m', strtotime($d)); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_ok(): bool { $t = (string)($_SESSION['csrf'] ?? ''); return $t !== '' && hash_equals($t, (string)($_POST['csrf'] ?? '')); }
function cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : substr($s, 0, $n); }
function head(string $title): void {
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — NDARTECH</title><link rel="stylesheet" href="style.css"></head><body><div class="wrap"><header>'
        . '<svg width="54" height="54" viewBox="0 0 100 100" aria-hidden="true"><path d="M38 14L62 28M46 34L75 52M46 34L30 62M62 28L46 56" fill="none" stroke="#1A6BB5" stroke-width="2"/><circle cx="36" cy="12" r="10" fill="#2B9CD8"/><circle cx="64" cy="27" r="6" fill="#2B9CD8"/><circle cx="47" cy="54" r="9" fill="#2B9CD8"/><circle cx="46" cy="33" r="7" fill="#1A6BB5"/><circle cx="76" cy="52" r="14" fill="#1A6BB5"/><circle cx="29" cy="68" r="16" fill="#1A6BB5"/></svg>'
        . '<div><h1>' . h($title) . '</h1><div class="sub">NDARTECH · Au cœur du numérique</div></div></header>';
}
function foot(string $js = ''): void { echo '</div>' . ($js ? '<script src="' . $js . '"></script>' : '') . '</body></html>'; }

/** Pré-remplit 8 semaines à partir d’un lundi (modèle de la grille initiale). */
function seed(string $start): void {
    $M = ['08:00', '12:00']; $A = ['12:30', '16:00'];
    $plan = [1 => [0 => $M, 2 => $M, 3 => $M, 5 => $M], 2 => [0 => $M, 1 => $A, 2 => $M, 3 => $A, 4 => $M], 3 => [0 => $A, 1 => $M, 2 => $A, 3 => $M, 5 => $M]];
    $top = [
        1 => ['Introduction au web, HTML5', 'CSS3, Flexbox, Grid, responsive', 'JavaScript (bases, DOM)', 'Mini-projet : site web responsive', 'Présentation du projet et évaluation'],
        2 => ['Bases de l’ordinateur, Word', 'Excel : formules, tableaux, graphiques', 'PowerPoint, Internet, outils collaboratifs', 'Révisions et cas pratiques', 'Évaluation finale'],
        3 => ['Design, couleurs, typographie, Canva', 'Photoshop : retouche, calques, montage', 'Illustrator : logos, vectoriel', 'Affiches, flyers, cartes de visite', 'Projet final et évaluation'],
    ];
    $db = db(); $st = $db->prepare('INSERT INTO courses(formation,day,hs,he,title) VALUES(?,?,?,?,?)');
    $db->beginTransaction();
    foreach ([0, 0, 1, 1, 2, 2, 3, 4] as $i => $k)
        foreach ($plan as $f => $days) foreach ($days as $d => [$a, $b]) $st->execute([$f, addd($start, 7 * $i + $d), $a, $b, $top[$f][$k]]);
    $db->commit();
}
