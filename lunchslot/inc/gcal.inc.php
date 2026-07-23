<?php
/**
 * Liens « Ajouter à Google Calendar » (URL template, dates en UTC).
 */

declare(strict_types=1);

function google_calendar_link(string $startUtc, int $durationMin, string $title, string $details = '', string $location = ''): string
{
    $tzUtc = new DateTimeZone('UTC');
    $start = new DateTime($startUtc, $tzUtc);
    $end = clone $start;
    $end->modify('+' . $durationMin . ' minutes');

    $params = [
        'action' => 'TEMPLATE',
        'text'   => $title,
        'dates'  => $start->format('Ymd\THis\Z') . '/' . $end->format('Ymd\THis\Z'),
    ];
    if ($details !== '') {
        $params['details'] = $details;
    }
    if ($location !== '') {
        $params['location'] = $location;
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}
