<?php
/**
 * Liens « Ajouter à Google Calendar » (URL template, dates en UTC).
 */

declare(strict_types=1);

/**
 * @param array $guests Emails à pré-inviter (paramètre `add` de Google Calendar).
 *                      Utilisé pour l'invitation définitive : l'organisateur
 *                      obtient un événement contenant déjà tous les participants.
 */
function google_calendar_link(string $startUtc, int $durationMin, string $title, string $details = '', string $location = '', array $guests = []): string
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
    $guests = array_values(array_unique(array_filter(array_map('trim', $guests))));
    if ($guests) {
        $params['add'] = implode(',', $guests);
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}
