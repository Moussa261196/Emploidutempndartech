<?php
require __DIR__ . '/../app/bootstrap.php';
$f = (int)($_GET['f'] ?? 1); if ($f !== 0 && !isset(FORMS[$f])) $f = 1;
$defaultWeek = (int)date('N') >= 6 ? addd(monday(), 7) : monday();
$w = monday($_GET['w'] ?? $defaultWeek);
$weekCount = 9;
$lastWeek = addd($w, ($weekCount - 1) * 7);
$end = addd($lastWeek, 5);
$sql = 'SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ?';
if ($f !== 0) $sql .= ' AND formation=?';
$sql .= ' ORDER BY day,hs,formation';
$q = db()->prepare($sql);
$q->execute($f === 0 ? [$w, $end] : [$w, $end, $f]);
$validationQuery = db()->prepare('SELECT formation,day,hs,he,title,room FROM courses WHERE day BETWEEN ? AND ?');
$validationQuery->execute([$w, $end]);
$coursesByWeek = [];
foreach ($validationQuery as $course) $coursesByWeek[monday($course['day'])][] = $course;
$weeks = [];
$scheduleWarnings = [];
for ($i = 0; $i < $weekCount; $i++) {
    $week = addd($w, $i * 7);
    $weeks[$week] = ['by' => [], 'slots' => [], 'note' => ''];
    if (!empty($coursesByWeek[$week])) {
        $scheduleWarnings = array_merge($scheduleWarnings, validate_manual_schedule($coursesByWeek[$week]));
    }
}
foreach ($q as $r) {
    $week = monday($r['day']);
    $slot = $r['hs'] . '-' . $r['he'];
    $weeks[$week]['by'][$slot][$r['day']][] = $r;
    $weeks[$week]['slots'][$slot] = ['hs' => $r['hs'], 'he' => $r['he']];
}
$n = db()->prepare('SELECT week,text FROM notes WHERE week BETWEEN ? AND ?');
$n->execute([$w, $lastWeek]);
foreach ($n as $note) $weeks[$note['week']]['note'] = $note['text'];
$url = fn(int $ff, string $ww) => h('?f=' . $ff . '&w=' . $ww);
$today = date('Y-m-d');
$weekShortcut = (int)date('N') >= 6 ? 'Semaine prochaine' : 'Aujourd’hui';
head('Emploi du temps');
echo '<nav class="tabs">';
echo '<a class="tab' . ($f === 0 ? ' on' : '') . '" href="' . $url(0, $w) . '">Toutes les formations</a>';
foreach (FORMS as $id => [$name, $c]) echo '<a class="tab ' . $c . ($id === $f ? ' on' : '') . '" href="' . $url($id, $w) . '">' . h($name) . '</a>';
echo '</nav>';
if ($scheduleWarnings) echo '<div class="err" role="alert">Attention : un ou plusieurs cours comportent des données invalides ou se chevauchent. Contactez l’administration pour corriger le planning.</div>';
echo '<div class="schedule-shell' . ($f === 0 ? ' all-forms' : ' ' . h(FORMS[$f][1])) . '">';
echo '<div class="schedule-header"><div class="week-meta"><span class="meta-label">' . ($f === 0 ? 'Vue générale' : 'Formation') . '</span><h2>' . h($f === 0 ? 'Toutes les formations' : FORMS[$f][0]) . '</h2></div><div class="week-range"><strong class="period-label"><span class="period-title">Planning sur ' . $weekCount . ' semaines</span><span class="period-dates">' . h(schedule_period_label($w, $end)) . '</span></strong><a class="ic period-prev" href="' . $url($f, addd($w, -$weekCount * 7)) . '" aria-label="Période précédente">‹</a><a class="ic period-next" href="' . $url($f, addd($w, $weekCount * 7)) . '" aria-label="Période suivante">›</a><a class="ic period-today" href="' . $url($f, $defaultWeek) . '">' . $weekShortcut . '</a></div></div>';
if ($f === 0) {
    echo '<div class="formation-legend">';
    foreach (FORMS as $id => [$name]) echo '<span><i class="legend-mark legend-' . ($id - 1) . '" aria-hidden="true"></i>' . h($name) . '</span>';
    echo '</div>';
}
$formatTime = static fn(string $time): string => substr($time, 0, 2) . 'H' . (substr($time, 3, 2) === '00' ? '' : substr($time, 3, 2));
foreach ($weeks as $week => $data) {
    $slots = $data['slots'];
    uasort($slots, static fn(array $a, array $b): int => [$a['hs'], $a['he']] <=> [$b['hs'], $b['he']]);
    $isCurrentWeek = $week === monday();
    echo '<section class="schedule-week' . ($isCurrentWeek ? ' current-week' : '') . '"><h3 class="schedule-week-heading"><span>' . h(schedule_week_label($week)) . '</span>'
        . ($isCurrentWeek ? '<span class="current-week-badge">Semaine en cours</span>' : '') . '</h3>';
    if ($data['note'] !== '') echo '<div class="note">' . nl2br(h($data['note'])) . '</div>';
    echo '<div class="schedule-table-wrap"><table class="schedule-table"><thead><tr><th scope="col">Horaire</th>';
    foreach (DAYS as $i => $name) {
        $d = addd($week, $i);
        echo '<th scope="col"' . ($d === $today ? ' class="today"' : '') . '><span>' . $name . '</span><small>' . fd($d) . '</small></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($slots as $key => $slot) {
        echo '<tr><th scope="row" class="time-cell">' . h($formatTime($slot['hs']) . ' – ' . $formatTime($slot['he'])) . '</th>';
        foreach (DAYS as $i => $_) {
            $d = addd($week, $i);
            echo '<td>';
            if (empty($data['by'][$key][$d])) echo '<span class="no-course" aria-label="Aucun cours">—</span>';
            else foreach ($data['by'][$key][$d] as $course) {
                $formationName = FORMS[(int)$course['formation']][0];
                echo '<article class="session session-' . ((int)$course['formation'] - 1) . '" aria-label="Cours programmé : ' . h($formationName) . '"><span class="session-title">' . h($formationName) . '</span></article>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    if (!$slots) echo '<tr><td colspan="' . (count(DAYS) + 1) . '" class="empty-week">Aucun cours n’est prévu cette semaine.</td></tr>';
    echo '</tbody></table></div><div class="schedule-mobile">';
    for ($i = 0; $i < count(DAYS); $i++) {
        $day = addd($week, $i);
        $dayCourses = [];
        foreach ($data['by'] as $coursesBySlot) {
            foreach ($coursesBySlot[$day] ?? [] as $course) $dayCourses[] = $course;
        }
        usort($dayCourses, static fn(array $a, array $b): int => [$a['hs'], $a['formation']] <=> [$b['hs'], $b['formation']]);
        echo '<section class="schedule-day-card"><h4>' . DAYS[$i] . ' <time datetime="' . h($day) . '">' . fd($day) . '</time></h4>';
        if (!$dayCourses) echo '<p class="schedule-day-empty">Aucun cours prévu</p>';
        foreach ($dayCourses as $course) {
            $formationName = FORMS[(int)$course['formation']][0];
            echo '<article class="mobile-session session-' . ((int)$course['formation'] - 1) . '"><time>' . h($formatTime($course['hs']) . ' – ' . $formatTime($course['he'])) . '</time><span>' . h($formationName) . '</span></article>';
        }
        echo '</section>';
    }
    echo '</div></section>';
}
echo '</div>';
echo '<p class="foot">L’emploi du temps est mis à jour chaque semaine par l’administration. Consultez cette page avant chaque cours. · <a href="admin.php">Espace administrateur</a></p>';
foot();
