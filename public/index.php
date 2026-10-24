<?php
require __DIR__ . '/../app/bootstrap.php';
$f = (int)($_GET['f'] ?? 1); if ($f !== 0 && !isset(FORMS[$f])) $f = 1;
$defaultWeek = (int)date('N') >= 6 ? addd(monday(), 7) : monday();
$w = monday($_GET['w'] ?? $defaultWeek); $end = addd($w, 5);
$sql = 'SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ?';
if ($f !== 0) $sql .= ' AND formation=?';
$sql .= ' ORDER BY day,hs,formation';
$q = db()->prepare($sql);
$q->execute($f === 0 ? [$w, $end] : [$w, $end, $f]);
$by = []; $slots = [];
foreach ($q as $r) {
    $slot = $r['hs'] . '-' . $r['he'];
    $by[$slot][$r['day']][] = $r;
    $slots[$slot] = ['hs' => $r['hs'], 'he' => $r['he']];
}
uasort($slots, static fn(array $a, array $b): int => [$a['hs'], $a['he']] <=> [$b['hs'], $b['he']]);
$n = db()->prepare('SELECT text FROM notes WHERE week=?'); $n->execute([$w]); $note = (string)$n->fetchColumn();
$url = fn(int $ff, string $ww) => h('?f=' . $ff . '&w=' . $ww);
$today = date('Y-m-d');
$weekShortcut = (int)date('N') >= 6 ? 'Semaine prochaine' : 'Aujourd’hui';
head('Emploi du temps');
echo '<nav class="tabs">';
echo '<a class="tab' . ($f === 0 ? ' on' : '') . '" href="' . $url(0, $w) . '">Toutes les formations</a>';
foreach (FORMS as $id => [$name, $c]) echo '<a class="tab ' . $c . ($id === $f ? ' on' : '') . '" href="' . $url($id, $w) . '">' . h($name) . '</a>';
echo '</nav>';
echo '<div class="schedule-shell' . ($f === 0 ? ' all-forms' : ' ' . h(FORMS[$f][1])) . '">';
echo '<div class="schedule-header"><div class="week-meta"><span class="meta-label">' . ($f === 0 ? 'Vue générale' : 'Formation') . '</span><h2>' . h($f === 0 ? 'Toutes les formations' : FORMS[$f][0]) . '</h2></div><div class="week-range"><a class="ic" href="' . $url($f, addd($w, -7)) . '" aria-label="Semaine précédente">‹</a><strong>Semaine du ' . fd($w) . ' au ' . fd($end) . '</strong><a class="ic" href="' . $url($f, addd($w, 7)) . '" aria-label="Semaine suivante">›</a><a class="ic" href="' . $url($f, $defaultWeek) . '">' . $weekShortcut . '</a></div></div>';
if ($f === 0) {
    echo '<div class="formation-legend">';
    foreach (FORMS as $id => [$name]) echo '<span><i class="legend-mark legend-' . ($id - 1) . '" aria-hidden="true"></i>' . h($name) . '</span>';
    echo '</div>';
}
if ($note !== '') echo '<div class="note">' . nl2br(h($note)) . '</div>';
echo '<div class="schedule-table-wrap"><table class="schedule-table"><thead><tr><th scope="col">Horaire</th>';
foreach (DAYS as $i => $name) {
    $d = addd($w, $i);
    echo '<th scope="col"' . ($d === $today ? ' class="today"' : '') . '><span>' . $name . '</span><small>' . fd($d) . '</small></th>';
}
echo '</tr></thead><tbody>';
$formatTime = static fn(string $time): string => substr($time, 0, 2) . 'H' . (substr($time, 3, 2) === '00' ? '' : substr($time, 3, 2));
foreach ($slots as $key => $slot) {
    echo '<tr><th scope="row" class="time-cell">' . h($formatTime($slot['hs']) . ' – ' . $formatTime($slot['he'])) . '</th>';
    foreach (DAYS as $i => $_) {
        $d = addd($w, $i);
        echo '<td>';
        if (empty($by[$key][$d])) echo '<span class="no-course" aria-label="Aucun cours">—</span>';
        else foreach ($by[$key][$d] as $course) {
            $formationName = FORMS[(int)$course['formation']][0];
            echo '<article class="session session-' . ((int)$course['formation'] - 1) . '" aria-label="Cours programmé : ' . h($formationName) . '"><span class="session-title">' . h($formationName) . '</span></article>';
        }
        echo '</td>';
    }
    echo '</tr>';
}
if (!$slots) echo '<tr><td colspan="' . (count(DAYS) + 1) . '" class="empty-week">Aucun cours n’est prévu cette semaine.</td></tr>';
echo '</tbody></table></div></div>';
echo '<p class="foot">L’emploi du temps est mis à jour chaque semaine par l’administration. Consultez cette page avant chaque cours. · <a href="admin.php">Espace administrateur</a></p>';
foot();
