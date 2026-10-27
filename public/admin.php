<?php
require __DIR__ . '/../app/bootstrap.php';
$db = db();
$hasAdmin = (bool)$db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
if (isset($_SESSION['uid']) && time() - ($_SESSION['seen'] ?? 0) > 7200) { $_SESSION = []; session_regenerate_id(true); }
$uid = $_SESSION['uid'] ?? null;
if ($uid) $_SESSION['seen'] = time();
$err = '';
$flash = (string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
$submittedCourses = null;
$submittedNote = null;
$defaultWeek = (int)date('N') >= 6 ? addd(monday(), 7) : monday();
$weekShortcut = (int)date('N') >= 6 ? 'Semaine prochaine' : 'Aujourd’hui';
$scheduleWeeks = 9;

function go(string $q = ''): never { header('Location: admin.php' . $q); exit; }
function login(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; $_SESSION['seen'] = time(); }
function row(string $d, array $r = ['hs' => '08:00', 'he' => '12:00', 'title' => '', 'room' => '']): string {
    return '<div class="course-row" data-course-row><div class="course-times"><label>Début<input type="time" name="s[' . $d . '][]" value="' . h($r['hs']) . '" required></label>'
        . '<label>Fin<input type="time" name="e[' . $d . '][]" value="' . h($r['he']) . '" required></label></div>'
        . '<label class="course-title">Matière ou module<input name="t[' . $d . '][]" maxlength="120" placeholder="Ex. Développement web" value="' . h($r['title']) . '" required></label>'
        . '<label class="course-room">Salle ou formateur <span>(facultatif)</span><input name="r[' . $d . '][]" maxlength="80" placeholder="Ex. Salle 2" value="' . h($r['room']) . '"></label>'
        . '<button type="button" class="x" aria-label="Supprimer ce cours" title="Supprimer ce cours">Supprimer</button></div>';
}
function snapshot(PDO $db, string $action): void {
    $payload = [
        'format' => 'ndartech-edt-backup',
        'version' => 1,
        'exported_at' => date(DATE_ATOM),
        'courses' => $db->query('SELECT formation,day,hs,he,title,room FROM courses ORDER BY day,hs,formation')->fetchAll(),
        'notes' => $db->query('SELECT week,text FROM notes ORDER BY week')->fetchAll(),
    ];
    $insert = $db->prepare('INSERT INTO snapshots(created_at,action,payload) VALUES(?,?,?)');
    $insert->execute([date(DATE_ATOM), $action, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $db->exec('DELETE FROM snapshots WHERE id NOT IN (SELECT id FROM snapshots ORDER BY id DESC LIMIT 20)');
}
$f = (int)($_GET['f'] ?? $_POST['f'] ?? 1); if (!isset(FORMS[$f])) $f = 1;
$w = monday($_GET['w'] ?? $_POST['w'] ?? $defaultWeek);
$scheduleEnd = addd($w, ($scheduleWeeks - 1) * 7 + 5);
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
        if ($a === 'backup') {
            $courses = $db->query('SELECT formation,day,hs,he,title,room FROM courses ORDER BY day,hs,formation')->fetchAll();
            $notes = $db->query('SELECT week,text FROM notes ORDER BY week')->fetchAll();
            $backup = ['format' => 'ndartech-edt-backup', 'version' => 1, 'exported_at' => date(DATE_ATOM), 'courses' => $courses, 'notes' => $notes];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="ndartech-edt-backup-' . date('Y-m-d') . '.json"');
            echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        if ($a === 'generate') {
            if (($_POST['confirm_generate'] ?? '') !== 'yes') {
                $err = 'Confirmez la génération avant de continuer.';
            } else {
                $count = $db->prepare('SELECT COUNT(*) FROM courses WHERE day BETWEEN ? AND ?');
                $count->execute([$w, $scheduleEnd]);
                $existingCount = (int)$count->fetchColumn();
                if ($existingCount > 0 && empty($_POST['replace_existing'])) {
                    $err = 'Génération annulée : ' . $existingCount . ' cours existent déjà sur ces neuf semaines. Cochez le remplacement pour les remplacer après sauvegarde.';
                } else {
                    try {
                        $generated = generate_schedule($w, $scheduleWeeks);
                    } catch (InvalidArgumentException | LogicException $exception) {
                        $err = 'Génération impossible : ' . $exception->getMessage();
                    }
                    if ($err === '') {
                        $db->beginTransaction();
                        snapshot($db, 'Avant génération automatique');
                        $db->prepare('DELETE FROM courses WHERE day BETWEEN ? AND ?')->execute([$w, $scheduleEnd]);
                        $insert = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
                        foreach ($generated as $course) {
                            $insert->execute([$course['formation'], $course['day'], $course['hs'], $course['he'], $course['title'], $course['room']]);
                        }
                        $db->commit();
                        $_SESSION['flash'] = 'Planning automatique généré et vérifié pour neuf semaines.';
                        go($back);
                    }
                }
            }
        }
        if ($a === 'restore' || $a === 'restore_snapshot') {
            if ($a === 'restore_snapshot') {
                $snapshotQuery = $db->prepare('SELECT payload FROM snapshots WHERE id=?');
                $snapshotQuery->execute([(int)($_POST['snapshot_id'] ?? 0)]);
                $json = $snapshotQuery->fetchColumn();
                if ($json === false) $err = 'Cette sauvegarde automatique n’existe plus.';
            } else {
                $upload = $_FILES['backup'] ?? null;
                if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
                    $err = 'Sélectionnez un fichier de sauvegarde valide.';
                } elseif ($upload['size'] > 5 * 1024 * 1024) {
                    $err = 'Le fichier de sauvegarde dépasse la taille maximale de 5 Mo.';
                } else {
                    $json = file_get_contents($upload['tmp_name']);
                }
            }
            if ($err === '') {
                try {
                    $backup = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
                } catch (JsonException) {
                    $backup = null;
                }
            }
            if ($err === '' && (!is_array($backup ?? null) || ($backup['format'] ?? null) !== 'ndartech-edt-backup' || ($backup['version'] ?? null) !== 1 || !is_array($backup['courses'] ?? null) || !is_array($backup['notes'] ?? null))) {
                $err = 'Le fichier n’est pas une sauvegarde NDARTECH valide.';
            } elseif ($err === '' && (count($backup['courses']) > 10000 || count($backup['notes']) > 1000)) {
                $err = 'La sauvegarde contient trop d’éléments.';
            } elseif ($err === '') {
                $restoreCourses = [];
                $restoreNotes = [];
                foreach ($backup['courses'] as $course) {
                    if (!is_array($course)) { $err = 'Une entrée de cours est invalide.'; break; }
                    $formation = filter_var($course['formation'] ?? null, FILTER_VALIDATE_INT);
                    $day = (string)($course['day'] ?? '');
                    $hs = (string)($course['hs'] ?? '');
                    $he = (string)($course['he'] ?? '');
                    $title = (string)($course['title'] ?? '');
                    $room = (string)($course['room'] ?? '');
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
                    if ($formation === false || !isset(FORMS[$formation]) || !$date || $date->format('Y-m-d') !== $day || (int)$date->format('N') > 6
                        || !preg_match($TIME, $hs) || !preg_match($TIME, $he) || $hs >= $he
                        || $title === '' || cut($title, 120) !== $title || cut($room, 80) !== $room) {
                        $err = 'La sauvegarde contient un cours avec des données invalides.';
                        break;
                    }
                    $restoreCourses[] = [$formation, $day, $hs, $he, $title, $room];
                }
                if ($err === '') foreach ($backup['notes'] as $item) {
                    if (!is_array($item)) { $err = 'Une annonce de la sauvegarde est invalide.'; break; }
                    $week = (string)($item['week'] ?? '');
                    $text = (string)($item['text'] ?? '');
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $week);
                    if (!$date || $date->format('Y-m-d') !== $week || $date->format('N') !== '1' || cut($text, 500) !== $text) {
                        $err = 'La sauvegarde contient une annonce avec des données invalides.';
                        break;
                    }
                    $restoreNotes[] = [$week, $text];
                }
                if ($err === '') {
                    $db->beginTransaction();
                    snapshot($db, 'Avant restauration');
                    $db->exec('DELETE FROM courses; DELETE FROM notes;');
                    $insertCourse = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
                    foreach ($restoreCourses as $course) $insertCourse->execute($course);
                    $insertNote = $db->prepare('INSERT INTO notes(week,text) VALUES(?,?)');
                    foreach ($restoreNotes as $item) $insertNote->execute($item);
                    $db->commit();
                    $_SESSION['flash'] = 'Sauvegarde restaurée : ' . count($restoreCourses) . ' cours et ' . count($restoreNotes) . ' annonces.';
                    go($back);
                }
            }
        }
        if ($a === 'save') {
            $submittedCourses = [];
            $validatedCourses = [];
            $submittedNote = (string)($_POST['note'] ?? '');
            $validationErrors = [];
            foreach (DAYS as $i => $_) {
                $d = addd($w, $i);
                $starts = (array)($_POST['s'][$d] ?? []);
                $ends = (array)($_POST['e'][$d] ?? []);
                $titles = (array)($_POST['t'][$d] ?? []);
                $rooms = (array)($_POST['r'][$d] ?? []);
                foreach ($starts as $k => $start) {
                    $start = (string)$start; $end = (string)($ends[$k] ?? '');
                    $title = trim((string)($titles[$k] ?? '')); $room = trim((string)($rooms[$k] ?? ''));
                    if ($title === '' && $room === '') continue;
                    $submittedCourses[$d][] = ['hs' => $start, 'he' => $end, 'title' => $title, 'room' => $room];
                    if (!preg_match($TIME, $start) || !preg_match($TIME, $end) || $start >= $end || $title === ''
                        || cut($title, 120) !== $title || cut($room, 80) !== $room) {
                        $validationErrors[] = $d . ' : vérifiez les heures, le titre et la longueur des champs.';
                        continue;
                    }
                    foreach ($validatedCourses[$d] ?? [] as $existing) {
                        if ($start < $existing['he'] && $end > $existing['hs']) {
                            $validationErrors[] = $d . ' : deux cours se chevauchent (' . $existing['hs'] . '–' . $existing['he'] . ' et ' . $start . '–' . $end . ').';
                            break;
                        }
                    }
                    $validatedCourses[$d][] = ['hs' => $start, 'he' => $end, 'title' => $title, 'room' => $room];
                }
            }
            if (cut($submittedNote, 500) !== $submittedNote) $validationErrors[] = 'L’annonce dépasse la limite de 500 caractères.';
            if (!$validationErrors) {
                $candidateQuery = $db->prepare('SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ? AND formation<>?');
                $candidateQuery->execute([$w, addd($w, 5), $f]);
                $candidate = $candidateQuery->fetchAll();
                foreach ($validatedCourses as $day => $courses) {
                    foreach ($courses as $course) $candidate[] = [
                        'formation' => $f, 'day' => $day, 'hs' => $course['hs'], 'he' => $course['he'],
                        'title' => $course['title'], 'room' => $course['room'],
                    ];
                }
                $validationErrors = array_merge($validationErrors, validate_schedule($candidate, $w, 1));
            }
            if ($validationErrors) {
                $err = 'Aucun changement enregistré. ' . implode(' ', array_slice(array_unique($validationErrors), 0, 8));
            } else {
                $db->beginTransaction();
                snapshot($db, 'Avant enregistrement');
                $db->prepare('DELETE FROM courses WHERE formation=? AND day BETWEEN ? AND ?')->execute([$f, $w, addd($w, 5)]);
                $ins = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
                foreach ($validatedCourses as $day => $courses) foreach ($courses as $course)
                    $ins->execute([$f, $day, $course['hs'], $course['he'], $course['title'], $course['room']]);
                $txt = trim($submittedNote);
                if ($txt === '') $db->prepare('DELETE FROM notes WHERE week=?')->execute([$w]);
                else $db->prepare('REPLACE INTO notes(week,text) VALUES(?,?)')->execute([$w, $txt]);
                $db->commit();
                $_SESSION['flash'] = 'Emploi du temps enregistré.';
                go($back);
            }
        }
        if ($a === 'copy') {
            $p = addd($w, -7);
            $q = $db->prepare('SELECT day,hs,he,title,room FROM courses WHERE formation=? AND day BETWEEN ? AND ?'); $q->execute([$f, $p, addd($p, 5)]); $rows = $q->fetchAll();
            if (!$rows) $_SESSION['flash'] = '!Aucun cours la semaine précédente pour cette formation.';
            elseif (($_POST['confirm_copy'] ?? '') !== 'yes') $_SESSION['flash'] = '!Confirmez la copie dans le formulaire pour remplacer la semaine affichée.';
            else {
                $candidateQuery = $db->prepare('SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ? AND formation<>?');
                $candidateQuery->execute([$w, addd($w, 5), $f]);
                $candidate = $candidateQuery->fetchAll();
                foreach ($rows as $row) $candidate[] = [
                    'formation' => $f, 'day' => addd($row['day'], 7), 'hs' => $row['hs'], 'he' => $row['he'],
                    'title' => $row['title'], 'room' => $row['room'],
                ];
                $scheduleErrors = validate_schedule($candidate, $w, 1);
                if ($scheduleErrors) {
                    $_SESSION['flash'] = '!Copie refusée : ' . implode(' ', $scheduleErrors);
                } else {
                    $db->beginTransaction();
                    snapshot($db, 'Avant copie de semaine');
                    $db->prepare('DELETE FROM courses WHERE formation=? AND day BETWEEN ? AND ?')->execute([$f, $w, addd($w, 5)]);
                    $ins = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
                    foreach ($rows as $r) $ins->execute([$f, addd($r['day'], 7), $r['hs'], $r['he'], $r['title'], $r['room']]);
                    $db->commit();
                    $_SESSION['flash'] = 'Semaine copiée : ajustez puis enregistrez.';
                }
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
            . '<label><input type="checkbox" name="seed" value="1" checked> Pré-remplir 9 semaines avec le planning automatique à partir du lundi :</label><input type="date" name="start" value="' . h($defaultWeek) . '">'
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
if ($submittedCourses !== null) $by = $submittedCourses;
if ($submittedNote !== null) $note = $submittedNote;
$ctx = '<input type="hidden" name="f" value="' . $f . '"><input type="hidden" name="w" value="' . h($w) . '">';
$lnk = fn(int $ff, string $ww) => h('?f=' . $ff . '&w=' . $ww);
head('Administration');
echo '<div class="bar"><a href="index.php' . h($back) . '">← Voir la page publique</a><form method="post">' . $tok . '<input type="hidden" name="a" value="logout"><button class="btn s">Déconnexion</button></form></div>';
if ($flash !== '') echo '<div class="' . ($flash[0] === '!' ? 'err' : 'ok') . '">' . h(ltrim($flash, '!')) . '</div>';
if ($err) echo '<div class="err">' . h($err) . '</div>';
$weekValidationQuery = $db->prepare('SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ?');
$weekValidationQuery->execute([$w, addd($w, 5)]);
$weekSchedule = $weekValidationQuery->fetchAll();
if ($weekSchedule && ($weekScheduleErrors = validate_schedule($weekSchedule, $w, 1))) {
    echo '<div class="err" role="alert">Le planning enregistré cette semaine ne respecte pas toutes les règles : ' . h(implode(' ', $weekScheduleErrors)) . ' Utilisez la génération automatique pour le corriger.</div>';
}
echo '<nav class="tabs">';
foreach (FORMS as $id => [$name, $c]) echo '<a class="tab ' . $c . ($id === $f ? ' on' : '') . '" href="' . $lnk($id, $w) . '">' . h($name) . '</a>';
echo '</nav><div class="wk' . ($w === monday() ? ' current-week-navigation' : '') . '"><a class="ic" href="' . $lnk($f, addd($w, -7)) . '" aria-label="Semaine précédente">‹</a><b>' . h(schedule_week_label($w))
    . ($w === monday() ? '<span class="current-week-badge">Semaine en cours</span>' : '') . '</b><a class="ic" href="' . $lnk($f, addd($w, 7)) . '" aria-label="Semaine suivante">›</a><a class="ic" href="' . $lnk($f, $defaultWeek) . '">' . $weekShortcut . '</a></div>';
$periodCountQuery = $db->prepare('SELECT COUNT(*) FROM courses WHERE day BETWEEN ? AND ?');
$periodCountQuery->execute([$w, $scheduleEnd]);
$periodCourseCount = (int)$periodCountQuery->fetchColumn();
$previousWeek = addd($w, -7);
$previousCountQuery = $db->prepare('SELECT COUNT(*) FROM courses WHERE formation=? AND day BETWEEN ? AND ?');
$previousCountQuery->execute([$f, $previousWeek, addd($previousWeek, 5)]);
$previousCourseCount = (int)$previousCountQuery->fetchColumn();
$currentCountQuery = $db->prepare('SELECT COUNT(*) FROM courses WHERE formation=? AND day BETWEEN ? AND ?');
$currentCountQuery->execute([$f, $w, addd($w, 5)]);
$currentCourseCount = (int)$currentCountQuery->fetchColumn();
$snapshots = $db->query('SELECT id,created_at,action FROM snapshots ORDER BY id DESC LIMIT 10')->fetchAll();
echo '<form method="post" class="ed" data-edit-form>' . $tok . $ctx . '<input type="hidden" name="a" value="save"><label class="announcement-label">Annonce de la semaine <span>(visible par tous les inscrits)</span><textarea name="note" rows="2" maxlength="500" placeholder="Informations utiles pour cette semaine…">' . h($note) . '</textarea></label><div class="editor-intro"><h2>Modifier les cours</h2><p>Ajoutez, corrigez ou supprimez les cours, puis enregistrez les changements.</p></div><div class="grid ' . FORMS[$f][1] . '">';
foreach (DAYS as $i => $name) {
    $d = addd($w, $i);
    echo '<section class="day editor-day"><div class="dh"><span class="day-name">' . $name . '<small>' . fd($d) . '</small></span><span class="course-count" data-count-for="' . $d . '"></span></div><div class="rows" data-d="' . $d . '">';
    foreach ($by[$d] ?? [] as $r) echo row($d, $r);
    echo '</div><p class="overlap-warning" data-overlap-for="' . $d . '" aria-live="polite" hidden></p><div class="add-course-actions"><button type="button" class="btn add-course" data-add="' . $d . '">+ Ajouter un cours</button><button type="button" class="btn quick-course" data-add-slot="08:00|12:00" data-day="' . $d . '">+ Matin</button><button type="button" class="btn quick-course" data-add-slot="12:00|16:00" data-day="' . $d . '">+ Après-midi</button></div></section>';
}
echo '</div><div class="act"><span class="save-status" data-save-status aria-live="polite">Tout est enregistré</span><button class="btn pri save-button">Enregistrer les modifications</button></div></form><template id="tpl">' . row('__D__') . '</template>';
echo '<form method="post" class="generate-schedule-form" data-generate-form data-existing-count="' . $periodCourseCount . '">' . $tok . $ctx
    . '<input type="hidden" name="a" value="generate"><input type="hidden" name="confirm_generate" value="yes">'
    . '<div><strong>Générer automatiquement neuf semaines</strong><p>Le développement web et l’infographie ont chacun un cours à 08h et un cours à 12h du lundi au jeudi. Leur attribution du matin et de l’après-midi s’inverse chaque semaine. La bureautique occupe l’après-midi lundi et mardi, puis le matin mercredi et jeudi.</p><p>La semaine 1 commence le lundi ' . fd(SCHEDULE_ANCHOR) . ' 2026.</p></div>'
    . '<label><input type="checkbox" name="replace_existing" value="yes"> Remplacer les ' . $periodCourseCount . ' cours existants de cette période (une sauvegarde sera créée)</label>'
    . '<button class="btn pri">Générer le planning</button></form>';
echo '<form method="post" class="copy-week-form" data-copy-form data-previous-count="' . $previousCourseCount . '" data-current-count="' . $currentCourseCount . '">' . $tok . $ctx . '<input type="hidden" name="a" value="copy"><input type="hidden" name="confirm_copy" value="yes"><button class="btn">Copier la semaine précédente</button><span>À copier : ' . $previousCourseCount . ' cours. Cette action remplacera les ' . $currentCourseCount . ' cours de la semaine affichée.</span></form>';
echo '<section class="backup-panel"><h2>Sauvegarde et restauration</h2><p>Téléchargez une copie de tous les cours et annonces. Restaurer une sauvegarde remplace les données actuelles.</p><form method="post">' . $tok . $ctx . '<input type="hidden" name="a" value="backup"><button class="btn">Télécharger une sauvegarde</button></form><form method="post" enctype="multipart/form-data" data-restore-form>' . $tok . $ctx . '<input type="hidden" name="a" value="restore"><label>Fichier de sauvegarde JSON<input type="file" name="backup" accept="application/json,.json" required></label><button class="btn restore-button">Restaurer cette sauvegarde</button></form></section>';
echo '<details class="snapshot-history"><summary>Restaurations rapides (' . count($snapshots) . ' sauvegardes automatiques récentes)</summary><p>Une sauvegarde est créée avant chaque enregistrement, copie ou restauration. Les 20 plus récentes sont conservées.</p>';
if (!$snapshots) echo '<p>Aucune sauvegarde automatique pour le moment.</p>';
foreach ($snapshots as $item) echo '<form method="post" class="snapshot-item" data-snapshot-restore>' . $tok . $ctx . '<input type="hidden" name="a" value="restore_snapshot"><input type="hidden" name="snapshot_id" value="' . (int)$item['id'] . '"><span><strong>' . h($item['action']) . '</strong><small>' . h($item['created_at']) . '</small></span><button class="btn s">Restaurer</button></form>';
echo '</details>';
echo '<details><summary>Changer mon mot de passe</summary><form method="post" class="box">' . $tok . $ctx . '<input type="hidden" name="a" value="passwd">'
    . '<label>Mot de passe actuel<input type="password" name="old" autocomplete="current-password" required></label><label>Nouveau mot de passe<input type="password" name="new" minlength="10" autocomplete="new-password" required></label>'
    . '<label>Confirmer<input type="password" name="new2" minlength="10" autocomplete="new-password" required></label><p><button class="btn pri">Modifier</button></p></form></details>';
foot('admin.js');
