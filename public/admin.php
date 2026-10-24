<?php
require __DIR__ . '/../app/bootstrap.php';
$db = db();
$hasAdmin = (bool)$db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
if (isset($_SESSION['uid']) && time() - ($_SESSION['seen'] ?? 0) > 7200) { $_SESSION = []; session_regenerate_id(true); }
$uid = $_SESSION['uid'] ?? null;
if ($uid) $_SESSION['seen'] = time();
$err = '';
$flash = (string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
$defaultWeek = (int)date('N') >= 6 ? addd(monday(), 7) : monday();
$weekShortcut = (int)date('N') >= 6 ? 'Semaine prochaine' : 'Aujourd’hui';

function go(string $q = ''): never { header('Location: admin.php' . $q); exit; }
function login(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; $_SESSION['seen'] = time(); }
function row(string $d, array $r = ['hs' => '08:00', 'he' => '12:00', 'title' => '', 'room' => '']): string {
    return '<div class="course-row" data-course-row><div class="course-times"><label>Début<input type="time" name="s[' . $d . '][]" value="' . h($r['hs']) . '" required></label>'
        . '<label>Fin<input type="time" name="e[' . $d . '][]" value="' . h($r['he']) . '" required></label></div>'
        . '<label class="course-title">Matière ou module<input name="t[' . $d . '][]" maxlength="120" placeholder="Ex. Développement web" value="' . h($r['title']) . '" required></label>'
        . '<label class="course-room">Salle ou formateur <span>(facultatif)</span><input name="r[' . $d . '][]" maxlength="80" placeholder="Ex. Salle 2" value="' . h($r['room']) . '"></label>'
        . '<button type="button" class="x" aria-label="Supprimer ce cours" title="Supprimer ce cours">Supprimer</button></div>';
}
$f = (int)($_GET['f'] ?? $_POST['f'] ?? 1); if (!isset(FORMS[$f])) $f = 1;
$w = monday($_GET['w'] ?? $_POST['w'] ?? $defaultWeek);
$back = '?f=' . $f . '&w=' . $w;
$TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Requête invalide. Rechargez la page.'); }
    $a = (string)($_POST['a'] ?? '');
    $pw = (string)($_POST['pw'] ?? '');
    if ($a === 'setup' && !$hasAdmin) {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($CFG['setup_key'] === 'CHANGER-MOI' || !hash_equals((string)$CFG['setup_key'], (string)($_POST['key'] ?? ''))) $err = 'Clé d’installation incorrecte (voir config.php).';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Adresse email invalide.';
        elseif (strlen($pw) < 10 || $pw !== (string)($_POST['pw2'] ?? '')) $err = 'Mot de passe : 10 caractères minimum, identique dans les deux champs.';
        else {
            $db->prepare('INSERT INTO users(email,hash) VALUES(?,?)')->execute([$email, password_hash($pw, PASSWORD_DEFAULT)]);
            $id = (int)$db->lastInsertId();
            if (!empty($_POST['seed'])) seed(monday((string)($_POST['start'] ?? '')));
            login($id); $_SESSION['flash'] = 'Compte administrateur créé.'; go();
        }
    } elseif ($a === 'login' && $hasAdmin && !$uid) {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
        $db->prepare('DELETE FROM attempts WHERE at<?')->execute([time() - 900]);
        $c = $db->prepare('SELECT COUNT(*) FROM attempts WHERE ip=?'); $c->execute([$ip]);
        if ((int)$c->fetchColumn() >= 5) $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
        else {
            $s = $db->prepare('SELECT id,hash FROM users WHERE email=?'); $s->execute([strtolower(trim((string)($_POST['email'] ?? '')))]); $u = $s->fetch();
            $okpw = password_verify($pw, $u['hash'] ?? '$2y$10$' . str_repeat('a', 53));
            if ($u && $okpw) { $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]); login((int)$u['id']); go(); }
            $db->prepare('INSERT INTO attempts(ip,at) VALUES(?,?)')->execute([$ip, time()]);
            $err = 'Email ou mot de passe incorrect.';
        }
    } elseif ($uid) {
        if ($a === 'logout') { $_SESSION = []; session_regenerate_id(true); go(); }
        if ($a === 'save') {
            $bad = 0;
            $db->beginTransaction();
            $db->prepare('DELETE FROM courses WHERE formation=? AND day BETWEEN ? AND ?')->execute([$f, $w, addd($w, 5)]);
            $ins = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
            foreach (DAYS as $i => $_) {
                $d = addd($w, $i);
                foreach ((array)($_POST['s'][$d] ?? []) as $k => $s) {
                    $e = (string)($_POST['e'][$d][$k] ?? ''); $t = trim((string)($_POST['t'][$d][$k] ?? '')); $r = trim((string)($_POST['r'][$d][$k] ?? ''));
                    if ($t === '' && $r === '') continue;
                    if (!preg_match($TIME, (string)$s) || !preg_match($TIME, $e) || $s >= $e || $t === '') { $bad++; continue; }
                    $ins->execute([$f, $d, $s, $e, cut($t, 120), cut($r, 80)]);
                }
            }
            $txt = trim(cut((string)($_POST['note'] ?? ''), 500));
            if ($txt === '') $db->prepare('DELETE FROM notes WHERE week=?')->execute([$w]);
            else $db->prepare('REPLACE INTO notes(week,text) VALUES(?,?)')->execute([$w, $txt]);
            $db->commit();
            $_SESSION['flash'] = $bad ? "Enregistré ; $bad ligne(s) ignorée(s) (heures ou titre invalides)." : 'Emploi du temps enregistré.';
            go($back);
        }
        if ($a === 'copy') {
            $p = addd($w, -7);
            $q = $db->prepare('SELECT day,hs,he,title,room FROM courses WHERE formation=? AND day BETWEEN ? AND ?'); $q->execute([$f, $p, addd($p, 5)]); $rows = $q->fetchAll();
            if (!$rows) $_SESSION['flash'] = '!Aucun cours la semaine précédente pour cette formation.';
            else {
                $db->beginTransaction();
                $db->prepare('DELETE FROM courses WHERE formation=? AND day BETWEEN ? AND ?')->execute([$f, $w, addd($w, 5)]);
                $ins = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
                foreach ($rows as $r) $ins->execute([$f, addd($r['day'], 7), $r['hs'], $r['he'], $r['title'], $r['room']]);
                $db->commit();
                $_SESSION['flash'] = 'Semaine copiée : ajustez puis enregistrez.';
            }
            go($back);
        }
        if ($a === 'passwd') {
            $u = $db->prepare('SELECT hash FROM users WHERE id=?'); $u->execute([$uid]); $new = (string)($_POST['new'] ?? '');
            if (!password_verify((string)($_POST['old'] ?? ''), (string)$u->fetchColumn())) $err = 'Mot de passe actuel incorrect.';
            elseif (strlen($new) < 10 || $new !== (string)($_POST['new2'] ?? '')) $err = 'Nouveau mot de passe : 10 caractères minimum, identique dans les deux champs.';
            else { $db->prepare('UPDATE users SET hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]); session_regenerate_id(true); $_SESSION['flash'] = 'Mot de passe modifié.'; go($back); }
        }
    }
}

$tok = '<input type="hidden" name="csrf" value="' . csrf() . '">';
if (!$uid) {
    head('Administration');
    echo '<div class="box">';
    if ($err) echo '<div class="err">' . h($err) . '</div>';
    if (!$hasAdmin) {
        echo '<h2>Première installation</h2><form method="post" autocomplete="off">' . $tok . '<input type="hidden" name="a" value="setup">'
            . '<label>Clé d’installation (config.php)<input type="password" name="key" required></label><label>Email administrateur<input type="email" name="email" required></label>'
            . '<label>Mot de passe (10 caractères minimum)<input type="password" name="pw" minlength="10" required></label><label>Confirmer le mot de passe<input type="password" name="pw2" minlength="10" required></label>'
            . '<label><input type="checkbox" name="seed" value="1" checked> Pré-remplir 8 semaines à partir du lundi :</label><input type="date" name="start" value="' . h($defaultWeek) . '">'
            . '<p><button class="btn pri">Créer le compte</button></p></form>';
    } else {
        echo '<h2>Connexion administrateur</h2><form method="post">' . $tok . '<input type="hidden" name="a" value="login">'
            . '<label>Email<input type="email" name="email" autocomplete="username" required></label><label>Mot de passe<input type="password" name="pw" autocomplete="current-password" required></label>'
            . '<p><button class="btn pri">Se connecter</button></p></form>';
    }
    echo '<p class="foot"><a href="index.php">← Retour à l’emploi du temps</a></p></div>';
    foot(); exit;
}

$q = $db->prepare('SELECT day,hs,he,title,room FROM courses WHERE formation=? AND day BETWEEN ? AND ? ORDER BY day,hs'); $q->execute([$f, $w, addd($w, 5)]);
$by = []; foreach ($q as $r) $by[$r['day']][] = $r;
$n = $db->prepare('SELECT text FROM notes WHERE week=?'); $n->execute([$w]); $note = (string)$n->fetchColumn();
$ctx = '<input type="hidden" name="f" value="' . $f . '"><input type="hidden" name="w" value="' . h($w) . '">';
$lnk = fn(int $ff, string $ww) => h('?f=' . $ff . '&w=' . $ww);
head('Administration');
echo '<div class="bar"><a href="index.php' . h($back) . '">← Voir la page publique</a><form method="post">' . $tok . '<input type="hidden" name="a" value="logout"><button class="btn s">Déconnexion</button></form></div>';
if ($flash !== '') echo '<div class="' . ($flash[0] === '!' ? 'err' : 'ok') . '">' . h(ltrim($flash, '!')) . '</div>';
if ($err) echo '<div class="err">' . h($err) . '</div>';
echo '<nav class="tabs">';
foreach (FORMS as $id => [$name, $c]) echo '<a class="tab ' . $c . ($id === $f ? ' on' : '') . '" href="' . $lnk($id, $w) . '">' . h($name) . '</a>';
echo '</nav><div class="wk"><a class="ic" href="' . $lnk($f, addd($w, -7)) . '" aria-label="Semaine précédente">‹</a><b>Semaine du ' . fd($w) . ' au ' . fd(addd($w, 5)) . '</b><a class="ic" href="' . $lnk($f, addd($w, 7)) . '" aria-label="Semaine suivante">›</a><a class="ic" href="' . $lnk($f, $defaultWeek) . '">' . $weekShortcut . '</a></div>';
echo '<form method="post" class="ed" data-edit-form>' . $tok . $ctx . '<input type="hidden" name="a" value="save"><label class="announcement-label">Annonce de la semaine <span>(visible par tous les inscrits)</span><textarea name="note" rows="2" maxlength="500" placeholder="Informations utiles pour cette semaine…">' . h($note) . '</textarea></label><div class="editor-intro"><h2>Modifier les cours</h2><p>Ajoutez, corrigez ou supprimez les cours, puis enregistrez les changements.</p></div><div class="grid ' . FORMS[$f][1] . '">';
foreach (DAYS as $i => $name) {
    $d = addd($w, $i);
    echo '<section class="day editor-day"><div class="dh"><span class="day-name">' . $name . '<small>' . fd($d) . '</small></span><span class="course-count" data-count-for="' . $d . '"></span></div><div class="rows" data-d="' . $d . '">';
    foreach ($by[$d] ?? [] as $r) echo row($d, $r);
    echo '</div><button type="button" class="btn add-course" data-add="' . $d . '">+ Ajouter un cours</button></section>';
}
echo '</div><div class="act"><span class="save-status" data-save-status aria-live="polite">Tout est enregistré</span><button class="btn pri save-button">Enregistrer les modifications</button></div></form><template id="tpl">' . row('__D__') . '</template>';
echo '<form method="post" class="copy-week-form" data-copy-form>' . $tok . $ctx . '<input type="hidden" name="a" value="copy"><button class="btn">Copier la semaine précédente</button><span>Cette action remplace les cours de la formation pour cette semaine.</span></form>';
echo '<details><summary>Changer mon mot de passe</summary><form method="post" class="box">' . $tok . $ctx . '<input type="hidden" name="a" value="passwd">'
    . '<label>Mot de passe actuel<input type="password" name="old" autocomplete="current-password" required></label><label>Nouveau mot de passe<input type="password" name="new" minlength="10" autocomplete="new-password" required></label>'
    . '<label>Confirmer<input type="password" name="new2" minlength="10" autocomplete="new-password" required></label><p><button class="btn pri">Modifier</button></p></form></details>';
foot('admin.js');
