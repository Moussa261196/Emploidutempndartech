<?php
require __DIR__ . '/../app/bootstrap.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

expect(schedule_week_label('2026-11-09') === 'Semaine du lundi 9 novembre au samedi 14 novembre 2026', 'Le libellé de semaine doit afficher les jours et dates en toutes lettres.');
expect(schedule_week_label('2026-12-28') === 'Semaine du lundi 28 décembre 2026 au samedi 2 janvier 2027', 'Le libellé de semaine doit gérer correctement un changement d’année.');
expect(schedule_period_label('2026-10-26', '2026-12-26') === 'Du lundi 26 octobre au samedi 26 décembre 2026', 'Le libellé de période doit être lisible et sans dates numériques.');

foreach (['2026-10-26', '2026-12-28', '2026-10-05', '2027-01-04'] as $start) {
    $weeks = 12;
    $courses = generate_schedule($start, $weeks);
    expect(count($courses) === 11 * $weeks, 'Le nombre de cours générés est incorrect pour ' . $start . '.');
    expect(validate_schedule($courses, $start, $weeks) === [], 'Le planning généré est invalide pour ' . $start . '.');

    for ($i = 0; $i < $weeks; $i++) {
        $week = addd($start, 7 * $i);
        $offset = schedule_week_offset($week);
        $days = [];
        foreach ($courses as $course) {
            if ($course['day'] >= $week && $course['day'] <= addd($week, 5)) $days[$course['day']][] = $course;
        }

        foreach (range(0, 3) as $dayIndex) {
            $sessions = $days[addd($week, $dayIndex)];
            expect(count($sessions) === 2, 'Chaque jour du lundi au jeudi doit avoir deux séances.');
            $formations = array_column($sessions, 'formation');
            expect(in_array(2, $formations, true), 'La bureautique doit être programmée chaque jour du lundi au jeudi.');
            expect(count(array_intersect($formations, [1, 3])) === 1, 'Une seule formation alternante doit être programmée chaque jour.');
            expect($formations[0] !== $formations[1], 'Deux formations ne doivent pas se chevaucher.');
            $expectedAlternating = ($dayIndex % 2 === 0) === ($offset % 2 === 0) ? 1 : 3;
            expect(in_array($expectedAlternating, $formations, true), 'L’alternance du lundi au jeudi est incorrecte.');
            $morning = array_values(array_filter($sessions, static fn(array $course): bool => $course['hs'] === '08:00'));
            expect(count($morning) === 1, 'Chaque journée du lundi au jeudi doit avoir une séance du matin.');
            $expectedMorningFormation = $dayIndex < 2 ? $expectedAlternating : 2;
            expect($morning[0]['formation'] === $expectedMorningFormation, 'Le créneau du matin doit alterner entre la formation et la bureautique selon le jour.');
        }
        foreach ([1, 3] as $formation) {
            $formationSlots = array_column(array_filter($courses, static fn(array $course): bool =>
                $course['formation'] === $formation && $course['day'] >= $week && $course['day'] <= addd($week, 3)
            ), 'hs');
            expect(in_array('08:00', $formationSlots, true) && in_array('12:00', $formationSlots, true), 'Chaque formation doit avoir des cours matin et après-midi chaque semaine.');
        }

        $friday = $days[addd($week, 4)];
        expect(count($friday) === 1 && $friday[0]['formation'] === 2, 'Le vendredi doit être réservé à une seule séance de bureautique.');
        $saturday = $days[addd($week, 5)];
        expect(count($saturday) === 2, 'Le samedi doit comporter deux séances.');
        $saturdayByFormation = [];
        foreach ($saturday as $course) $saturdayByFormation[$course['formation']] = $course['hs'];
        expect(isset($saturdayByFormation[1], $saturdayByFormation[3]) && $saturdayByFormation[1] !== $saturdayByFormation[3], 'Le samedi doit partager les créneaux entre le développement web et l’infographie.');
        expect(($saturdayByFormation[1] === '08:00') === ($offset % 2 === 0), 'L’ordre du samedi doit s’inverser chaque semaine.');
    }
    for ($i = 0; $i < $weeks - 1; $i++) {
        $monday = addd($start, 7 * $i);
        $nextMonday = addd($monday, 7);
        $morningByWeek = [];
        foreach ($courses as $course) {
            if (($course['day'] === $monday || $course['day'] === $nextMonday) && $course['hs'] === '08:00') {
                $morningByWeek[$course['day']] = $course['formation'];
            }
        }
        expect(isset($morningByWeek[$monday], $morningByWeek[$nextMonday]), 'Le créneau du matin doit être attribué les deux lundis.');
        expect($morningByWeek[$monday] !== $morningByWeek[$nextMonday], 'La formation qui commence le lundi à 08h doit changer la semaine suivante.');
    }
}

$invalid = generate_schedule('2026-10-26', 1);
$invalid[] = ['formation' => 1, 'day' => '2026-10-30', 'hs' => '08:00', 'he' => '12:00', 'title' => 'Cours interdit le vendredi', 'room' => ''];
expect(validate_schedule($invalid, '2026-10-26', 1) !== [], 'Le validateur doit refuser une formation autre que la bureautique le vendredi.');

$invalid = generate_schedule('2026-10-26', 1);
$invalid[0]['hs'] = '07:00';
expect(validate_schedule($invalid, '2026-10-26', 1) !== [], 'Le validateur doit refuser un créneau hors des horaires autorisés.');

$invalid = generate_schedule('2026-10-26', 1);
$invalid[] = $invalid[0];
expect(validate_schedule($invalid, '2026-10-26', 1) !== [], 'Le validateur doit refuser un créneau en double.');

$invalid = generate_schedule('2026-10-26', 1);
$invalid[1]['formation'] = 3;
expect(validate_schedule($invalid, '2026-10-26', 1) !== [], 'Le validateur doit refuser une alternance hebdomadaire incorrecte.');

echo "Planning validé sur quatre périodes de 12 semaines, avec contrôles des horaires, doublons, alternances et cours interdits.\n";
