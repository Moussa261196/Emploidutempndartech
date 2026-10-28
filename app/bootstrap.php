<?php
declare(strict_types=1);
$CFG = require __DIR__ . '/../config.php';
date_default_timezone_set($CFG['timezone']);
const FORMS = [1 => ['Développement web', 'f1'], 2 => ['Bureautique', 'f2'], 3 => ['Infographie', 'f3']];
const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
const SCHEDULE_ANCHOR = '2026-10-26';

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
            CREATE TABLE IF NOT EXISTS snapshots(id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, action TEXT NOT NULL, payload TEXT NOT NULL);
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
function schedule_date_label(DateTimeImmutable $date, string $weekday): string {
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $weekday . ' ' . (int)$date->format('j') . ' ' . $months[(int)$date->format('n')];
}
function schedule_week_label(string $week): string {
    $start = new DateTimeImmutable($week);
    $end = $start->modify('+5 days');
    $startLabel = schedule_date_label($start, 'lundi');
    $endLabel = schedule_date_label($end, 'samedi');
    if ($start->format('Y') !== $end->format('Y')) $startLabel .= ' ' . $start->format('Y');
    return 'Semaine du ' . $startLabel . ' au ' . $endLabel . ' ' . $end->format('Y');
}
function schedule_period_label(string $start, string $end): string {
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $startLabel = schedule_date_label($startDate, 'lundi');
    $endLabel = schedule_date_label($endDate, 'samedi');
    if ($startDate->format('Y') !== $endDate->format('Y')) $startLabel .= ' ' . $startDate->format('Y');
    return 'Du ' . $startLabel . ' au ' . $endLabel . ' ' . $endDate->format('Y');
}
function schedule_week_offset(string $week): int {
    $start = new DateTimeImmutable($week);
    $anchor = new DateTimeImmutable(SCHEDULE_ANCHOR);
    return intdiv((int)$anchor->diff($start)->format('%r%a'), 7);
}
function validate_schedule(array $courses, string $start, int $weekCount): array {
    if ($weekCount < 1 || $weekCount > 52 || monday($start) !== $start) return ['La période de génération est invalide.'];
    $actual = [];
    foreach ($courses as $course) {
        $day = (string)($course['day'] ?? '');
        $formation = filter_var($course['formation'] ?? null, FILTER_VALIDATE_INT);
        $hs = (string)($course['hs'] ?? '');
        $he = (string)($course['he'] ?? '');
        $title = trim((string)($course['title'] ?? ''));
        if ($formation === false || !isset(FORMS[$formation]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
            || !preg_match('/^(08:00|12:00)$/', $hs) || !preg_match('/^(12:00|16:00)$/', $he)
            || !in_array([$hs, $he], [['08:00', '12:00'], ['12:00', '16:00']], true) || $title === '') {
            return ['Un cours contient une formation, une date, un horaire ou un intitulé invalide.'];
        }
        $actual[$day][] = ['formation' => $formation, 'hs' => $hs, 'he' => $he];
    }
    $errors = [];
    for ($i = 0; $i < $weekCount; $i++) {
        $week = addd($start, 7 * $i);
        $offset = schedule_week_offset($week);
        $weekCourses = [];
        for ($day = 0; $day < 4; $day++) {
            $date = addd($week, $day);
            $dayCourses = $actual[$date] ?? [];
            $weekCourses = array_merge($weekCourses, $dayCourses);
            if (count($dayCourses) !== 2) {
                $errors[] = $date . ' : deux cours sont requis, bureautique et une autre formation.';
                continue;
            }
            $officeCourses = array_values(array_filter($dayCourses, static fn(array $course): bool => $course['formation'] === 2));
            $alternatingCourses = array_values(array_filter($dayCourses, static fn(array $course): bool => in_array($course['formation'], [1, 3], true)));
            if (count($officeCourses) !== 1 || count($alternatingCourses) !== 1) {
                $errors[] = $date . ' : une séance de bureautique et une séance de développement web ou d’infographie sont requises.';
                continue;
            }
            $webStarts = $offset % 2 === 0;
            $isWebDay = ($day % 2 === 0) === $webStarts;
            if ($alternatingCourses[0]['formation'] !== ($isWebDay ? 1 : 3)) {
                $errors[] = $date . ' : l’alternance entre le développement web et l’infographie n’est pas respectée.';
            }
            if ($officeCourses[0]['hs'] === $alternatingCourses[0]['hs']) {
                $errors[] = $date . ' : les deux formations doivent occuper des créneaux différents.';
            }
        }
        $officeMonday = array_values(array_filter($actual[$week] ?? [], static fn(array $course): bool => $course['formation'] === 2));
        $expectedOfficeMonday = $offset % 2 === 0 ? '12:00' : '08:00';
        if (count($officeMonday) !== 1 || $officeMonday[0]['hs'] !== $expectedOfficeMonday) {
            $errors[] = $week . ' : le créneau du lundi de la bureautique doit alterner chaque semaine.';
        }
        $officeWeekSlots = array_column(array_filter($weekCourses, static fn(array $course): bool => $course['formation'] === 2), 'hs');
        if (!in_array('08:00', $officeWeekSlots, true) || !in_array('12:00', $officeWeekSlots, true)) {
            $errors[] = $week . ' : la bureautique doit avoir des cours le matin et l’après-midi du lundi au jeudi.';
        }
        foreach ([1, 3] as $formation) {
            $formationSlots = array_column(array_filter($weekCourses, static fn(array $course): bool => $course['formation'] === $formation), 'hs');
            if (!in_array('08:00', $formationSlots, true) || !in_array('12:00', $formationSlots, true)) {
                $errors[] = $week . ' : le développement web et l’infographie doivent chacun avoir un cours le matin et l’après-midi.';
                break;
            }
        }
        $friday = $actual[addd($week, 4)] ?? [];
        if (count($friday) !== 1 || $friday[0]['formation'] !== 2 || $friday[0]['hs'] !== '08:00' || $friday[0]['he'] !== '12:00') {
            $errors[] = addd($week, 4) . ' : le vendredi doit comporter uniquement la bureautique de 08h à 12h.';
        }
        $webSaturdayMorning = $offset % 2 === 0;
        $saturdayDate = addd($week, 5);
        $saturday = $actual[$saturdayDate] ?? [];
        $saturdayByFormation = [];
        foreach ($saturday as $course) $saturdayByFormation[$course['formation']] = $course;
        if (count($saturday) !== 2 || !isset($saturdayByFormation[1], $saturdayByFormation[3])
            || $saturdayByFormation[1]['hs'] === $saturdayByFormation[3]['hs']
            || $saturdayByFormation[1]['hs'] !== ($webSaturdayMorning ? '08:00' : '12:00')) {
            $errors[] = $saturdayDate . ' : le développement web et l’infographie doivent partager les deux créneaux du samedi, en alternant chaque semaine.';
        }
        for ($day = 0; $day < 6; $day++) {
            $date = addd($week, $day);
            $dayCourses = $actual[$date] ?? [];
            for ($j = 0; $j < count($dayCourses); $j++) {
                for ($k = $j + 1; $k < count($dayCourses); $k++) {
                    if ($dayCourses[$j]['hs'] < $dayCourses[$k]['he'] && $dayCourses[$j]['he'] > $dayCourses[$k]['hs']) {
                        $errors[] = $date . ' : deux formations se chevauchent.';
                        break 2;
                    }
                }
            }
            unset($actual[$date]);
        }
    }
    if ($actual) $errors[] = 'Des cours se trouvent en dehors de la période générée.';
    return array_values(array_unique($errors));
}
function validate_manual_schedule(array $courses): array {
    $byDay = [];
    foreach ($courses as $course) {
        $day = (string)($course['day'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        $formation = filter_var($course['formation'] ?? null, FILTER_VALIDATE_INT);
        $start = (string)($course['hs'] ?? '');
        $end = (string)($course['he'] ?? '');
        $title = trim((string)($course['title'] ?? ''));
        if (!$date || $date->format('Y-m-d') !== $day || $formation === false || !isset(FORMS[$formation])
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end || $title === '') {
            return ['Un cours contient une formation, une date, un horaire ou un intitulé invalide.'];
        }
        foreach ($byDay[$day] ?? [] as $existing) {
            if ($start < $existing['he'] && $end > $existing['hs']) {
                $errors[] = $day . ' : deux cours se chevauchent (' . $existing['hs'] . '–' . $existing['he'] . ' et ' . $start . '–' . $end . ').';
            }
        }
        $byDay[$day][] = ['hs' => $start, 'he' => $end];
    }
    return array_values(array_unique($errors ?? []));
}
function generate_schedule(string $start, int $weekCount = 9): array {
    if ($weekCount < 1 || $weekCount > 52 || monday($start) !== $start) {
        throw new InvalidArgumentException('Choisissez un lundi et une période de 1 à 52 semaines.');
    }
    $modules = [
        1 => ['HTML et structure web', 'CSS et mise en page responsive', 'JavaScript et DOM', 'Projet web et intégration'],
        2 => ['Bases de l’ordinateur et traitement de texte', 'Tableur : formules et tableaux', 'Présentation et outils collaboratifs', 'Exercices pratiques et révisions'],
        3 => ['Design et typographie', 'Retouche et composition graphique', 'Illustration et création vectorielle', 'Affiche et projet graphique'],
    ];
    $courses = [];
    for ($i = 0; $i < $weekCount; $i++) {
        $week = addd($start, 7 * $i);
        $offset = schedule_week_offset($week);
        for ($day = 0; $day < 4; $day++) {
            $officeMorning = ($day >= 2) === ($offset % 2 === 0);
            $webStarts = $offset % 2 === 0;
            $courses[] = [
                'formation' => 2, 'day' => addd($week, $day),
                'hs' => $officeMorning ? '08:00' : '12:00', 'he' => $officeMorning ? '12:00' : '16:00',
                'title' => $modules[2][(($offset + $day) % count($modules[2]) + count($modules[2])) % count($modules[2])], 'room' => '',
            ];
            $formation = (($day % 2 === 0) === $webStarts) ? 1 : 3;
            $courses[] = [
                'formation' => $formation, 'day' => addd($week, $day),
                'hs' => $officeMorning ? '12:00' : '08:00', 'he' => $officeMorning ? '16:00' : '12:00',
                'title' => $modules[$formation][(($offset + $day) % count($modules[$formation]) + count($modules[$formation])) % count($modules[$formation])], 'room' => '',
            ];
        }
        $courses[] = [
            'formation' => 2, 'day' => addd($week, 4),
            'hs' => '08:00', 'he' => '12:00',
            'title' => $modules[2][(($offset + 4) % count($modules[2]) + count($modules[2])) % count($modules[2])], 'room' => '',
        ];
        $webSaturdayMorning = $offset % 2 === 0;
        foreach ([1, 3] as $formation) {
            $morning = ($formation === 1) === $webSaturdayMorning;
            $courses[] = [
                'formation' => $formation, 'day' => addd($week, 5),
                'hs' => $morning ? '08:00' : '12:00', 'he' => $morning ? '12:00' : '16:00',
                'title' => $modules[$formation][(($offset + 5) % count($modules[$formation]) + count($modules[$formation])) % count($modules[$formation])], 'room' => '',
            ];
        }
    }
    $errors = validate_schedule($courses, $start, $weekCount);
    if ($errors) throw new LogicException('Le planning généré est invalide : ' . implode(' ', $errors));
    return $courses;
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_ok(): bool { $t = (string)($_SESSION['csrf'] ?? ''); return $t !== '' && hash_equals($t, (string)($_POST['csrf'] ?? '')); }
function cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : substr($s, 0, $n); }
function head(string $title): void {
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — NDARTECH</title><link rel="stylesheet" href="style.css?v=20261027-3"></head><body><div class="wrap"><header>'
        . '<svg width="54" height="54" viewBox="0 0 100 100" aria-hidden="true"><path d="M38 14L62 28M46 34L75 52M46 34L30 62M62 28L46 56" fill="none" stroke="#1A6BB5" stroke-width="2"/><circle cx="36" cy="12" r="10" fill="#2B9CD8"/><circle cx="64" cy="27" r="6" fill="#2B9CD8"/><circle cx="47" cy="54" r="9" fill="#2B9CD8"/><circle cx="46" cy="33" r="7" fill="#1A6BB5"/><circle cx="76" cy="52" r="14" fill="#1A6BB5"/><circle cx="29" cy="68" r="16" fill="#1A6BB5"/></svg>'
        . '<div><h1>' . h($title) . '</h1><div class="sub">NDARTECH · Au cœur du numérique</div></div></header>';
}
function foot(string $js = ''): void { echo '</div>' . ($js ? '<script src="' . $js . '"></script>' : '') . '</body></html>'; }

/** Pré-remplit neuf semaines à partir d’un lundi avec les alternances définies. */
function seed(string $start): void {
    $courses = generate_schedule($start);
    $db = db(); $st = $db->prepare('INSERT INTO courses(formation,day,hs,he,title,room) VALUES(?,?,?,?,?,?)');
    $db->beginTransaction();
    foreach ($courses as $course) $st->execute([$course['formation'], $course['day'], $course['hs'], $course['he'], $course['title'], $course['room']]);
    $db->commit();
}
