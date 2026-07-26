<?php
/**
 * Modèles d'emails (bilingues FR/EN, HTML sobre + version texte).
 * Chaque email part dans la langue du déjeuner (lunch.locale), sans modifier
 * durablement la locale de la requête web. Reply-To = organisateur pour les
 * emails adressés aux participants.
 */

declare(strict_types=1);

function participant_url(array $p): string
{
    return rtrim(config('app_url'), '/') . '/repondre.php?t=' . $p['token'];
}

function admin_url(array $lunch): string
{
    return rtrim(config('app_url'), '/') . '/dashboard.php?t=' . $lunch['admin_token'];
}

/** Exécute $fn avec la locale $loc, puis restaure la locale précédente. */
function with_locale(string $loc, callable $fn)
{
    $prev = current_locale();
    set_locale($loc);
    try {
        return $fn();
    } finally {
        set_locale($prev);
    }
}

function email_html(string $heading, string $bodyHtml): string
{
    $app = h(config('app_name', 'LunchSpot'));
    return '<!doctype html><html lang="' . h(current_locale()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#222;">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px;">'
        . '<div style="font-weight:bold;font-size:18px;color:#2b5a9e;margin-bottom:16px;">' . $app . '</div>'
        . '<div style="background:#fff;border:1px solid #e3e6ea;border-radius:8px;padding:24px;">'
        . '<h1 style="font-size:18px;margin:0 0 16px;">' . $heading . '</h1>'
        . $bodyHtml
        . '</div>'
        . '<div style="color:#8a8f98;font-size:12px;margin-top:16px;">' . h(__('email.footer', ['app' => config('app_name', 'LunchSpot')])) . '</div>'
        . '</div></body></html>';
}

function email_button(string $label, string $url): string
{
    return '<p style="margin:20px 0;"><a href="' . h($url) . '" '
        . 'style="background:#2b5a9e;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;display:inline-block;">'
        . h($label) . '</a></p>';
}

function lunch_locale(array $lunch): string
{
    return $lunch['locale'] ?? 'fr';
}

/* 1. Invitation initiale au participant. */
function send_participant_invite(array $lunch, array $participant): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $participant) {
        $url = participant_url($participant);
        $suffix = $lunch['deadline'] ? __('email.invite.ask_deadline', ['date' => fmt_datetime($lunch['deadline'])]) : '';
        $loc = $lunch['location'] ? '<p>' . h(__('email.invite.location', ['loc' => $lunch['location']])) . '</p>' : '';

        $html = email_html(
            h(__('email.invite.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.hello', ['name' => $participant['name']])) . '</p>'
            . '<p>' . h(__('email.invite.body', ['title' => $lunch['title']])) . '</p>'
            . $loc
            . '<p>' . h(__('email.invite.ask', ['suffix' => $suffix])) . '</p>'
            . email_button(__('email.invite.cta'), $url)
            . '<p style="font-size:13px;color:#666;">' . h(__('email.invite.note')) . '</p>'
        );
        $text = __('email.hello', ['name' => $participant['name']]) . "\n\n"
            . __('email.invite.body', ['title' => $lunch['title']]) . "\n"
            . ($lunch['location'] ? __('email.invite.location', ['loc' => $lunch['location']]) . "\n" : '')
            . __('email.invite.ask', ['suffix' => $suffix]) . "\n$url\n\n"
            . __('email.invite.note');

        send_mail($participant['email'], __('email.invite.subject', ['title' => $lunch['title']]), $html, $text, [
            'reply_to' => $lunch['organizer_email'],
            'from_name' => sender_name($lunch),
        ]);
        log_mail_event((int) $lunch['id'], 'invite', $participant['email']);
    });
}

/* 3. Confirmation « déjeuner créé » à l'organisateur. */
function send_organizer_created(array $lunch, array $participants): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $participants) {
        $admin = admin_url($lunch);
        $rows = '';
        foreach ($participants as $p) {
            $rows .= '<li>' . h($p['name']) . ' &lt;' . h($p['email']) . '&gt;</li>';
        }
        $html = email_html(
            h(__('email.created.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.created.body')) . '</p><ul>' . $rows . '</ul>'
            . '<p>' . h(__('email.created.dashboard')) . '</p>'
            . email_button(__('email.created.cta'), $admin)
        );
        $text = __('email.created.body') . "\n" . $admin . "\n";
        send_mail($lunch['organizer_email'], __('email.created.subject', ['title' => $lunch['title']]), $html, $text);
        log_mail_event((int) $lunch['id'], 'created', $lunch['organizer_email']);
    });
}

/* 4. Récapitulatif des placeholders au participant. */
function send_placeholders(array $lunch, array $participant, array $available, array $canceled): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $participant, $available, $canceled) {
        $prefix = __('email.ph.provisional_prefix');
        $attachments = [];
        $blocksHtml = '';
        $blocksText = '';

        foreach ($available as $slot) {
            $uid = placeholder_uid((int) $lunch['id'], (int) $participant['id'], (int) $slot['id']);
            $seq = ics_bump_sequence($uid, 'TENTATIVE');
            $ics = ics_build([
                'method' => 'REQUEST', 'uid' => $uid, 'sequence' => $seq, 'status' => 'TENTATIVE',
                'start_utc' => $slot['start_utc'], 'duration_min' => (int) $slot['duration_min'],
                'summary' => $prefix . ' ' . $lunch['title'],
                'description' => $prefix . ' ' . config('app_name', 'LunchSpot'),
                'location' => $lunch['location'] ?? '',
                'organizer_email' => $lunch['organizer_email'], 'organizer_name' => organizer_display_name($lunch),
                'attendees' => [['email' => $participant['email'], 'name' => $participant['name']]],
            ]);
            $attachments[] = ['filename' => 'provisoire-' . $slot['id'] . '.ics', 'content' => $ics,
                'mime' => 'text/calendar; charset=UTF-8; method=REQUEST'];
            $glink = google_calendar_link($slot['start_utc'], (int) $slot['duration_min'], $prefix . ' ' . $lunch['title'], '', $lunch['location'] ?? '');
            $blocksHtml .= '<li>' . h(fmt_slot($slot['start_utc'], (int) $slot['duration_min']))
                . ' — <a href="' . h($glink) . '">' . h(__('email.add_gcal')) . '</a></li>';
            $blocksText .= '- ' . fmt_slot($slot['start_utc'], (int) $slot['duration_min']) . "\n  Google : $glink\n";
        }

        $cancelHtml = '';
        $cancelText = '';
        foreach ($canceled as $slot) {
            $uid = placeholder_uid((int) $lunch['id'], (int) $participant['id'], (int) $slot['id']);
            $seq = ics_bump_sequence($uid, 'CANCEL');
            $ics = ics_build([
                'method' => 'CANCEL', 'uid' => $uid, 'sequence' => $seq, 'status' => 'CANCELLED',
                'start_utc' => $slot['start_utc'], 'duration_min' => (int) $slot['duration_min'],
                'summary' => $prefix . ' ' . $lunch['title'], 'location' => $lunch['location'] ?? '',
                'organizer_email' => $lunch['organizer_email'], 'organizer_name' => organizer_display_name($lunch),
                'attendees' => [['email' => $participant['email'], 'name' => $participant['name']]],
            ]);
            $attachments[] = ['filename' => 'annulation-' . $slot['id'] . '.ics', 'content' => $ics,
                'mime' => 'text/calendar; charset=UTF-8; method=CANCEL'];
            $cancelHtml .= '<li>' . h(fmt_slot($slot['start_utc'], (int) $slot['duration_min'])) . '</li>';
            $cancelText .= '- ' . fmt_slot($slot['start_utc'], (int) $slot['duration_min']) . "\n";
        }

        $availHtml = $available
            ? '<p>' . h(__('email.ph.block_intro')) . '</p><ul>' . $blocksHtml . '</ul>' : '';
        $cancelBlock = $canceled
            ? '<p><strong>' . h(__('email.ph.cancel_intro')) . '</strong></p><ul>' . $cancelHtml . '</ul>' : '';

        $html = email_html(
            h(__('email.ph.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.ph.recorded', ['name' => $participant['name']])) . '</p>'
            . $availHtml . $cancelBlock
            . '<p style="font-size:13px;color:#666;">' . h(__('email.ph.note')) . '</p>'
        );
        $text = __('email.ph.recorded', ['name' => $participant['name']]) . "\n\n"
            . ($available ? __('email.ph.block_intro') . "\n$blocksText\n" : '')
            . ($canceled ? __('email.ph.cancel_intro') . "\n$cancelText\n" : '')
            . __('email.ph.note');

        send_mail($participant['email'], __('email.ph.subject', ['title' => $lunch['title']]), $html, $text, [
            'reply_to' => $lunch['organizer_email'], 'attachments' => $attachments,
            'from_name' => sender_name($lunch),
        ]);
    });
}

/* 5. Nouveau créneau proposé. */
function send_new_slot_notice(array $lunch, array $slot, array $recipients, string $proposerName): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $slot, $recipients, $proposerName) {
        foreach ($recipients as $r) {
            $isOrg = empty($r['token']);
            $url = $isOrg ? admin_url($lunch) : participant_url($r);
            $cta = $isOrg ? __('email.newslot.cta_org') : __('email.newslot.cta_part');
            $html = email_html(
                h(__('email.newslot.heading', ['title' => $lunch['title']])),
                '<p>' . h(__('email.hello', ['name' => $r['name'] ?? ''])) . '</p>'
                . '<p>' . h(__('email.newslot.body', ['who' => $proposerName])) . '</p>'
                . '<p><strong>' . h(fmt_slot($slot['start_utc'], (int) $slot['duration_min'])) . '</strong></p>'
                . '<p>' . h(__('email.newslot.ask')) . '</p>'
                . email_button($cta, $url)
            );
            $text = __('email.hello', ['name' => $r['name'] ?? '']) . "\n\n"
                . __('email.newslot.body', ['who' => $proposerName]) . "\n"
                . fmt_slot($slot['start_utc'], (int) $slot['duration_min']) . "\n\n"
                . __('email.newslot.ask') . " $url";
            $opts = $isOrg ? [] : ['reply_to' => $lunch['organizer_email'], 'from_name' => sender_name($lunch)];
            send_mail($r['email'], __('email.newslot.subject', ['title' => $lunch['title']]), $html, $text, $opts);
        }
    });
}

/* 6. Confirmation définitive (REQUEST) + nettoyage placeholders. */
function send_confirmation(array $lunch, array $slot, array $recipient, array $allAttendees, bool $isOrganizerOnly, array $placeholderCancels): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $slot, $recipient, $allAttendees, $isOrganizerOnly, $placeholderCancels) {
        $uid = event_uid((int) $lunch['id'], (int) $slot['id']);
        $seq = ics_bump_sequence($uid, 'REQUEST');
        $ics = ics_build([
            'method' => 'REQUEST', 'uid' => $uid, 'sequence' => $seq, 'status' => 'CONFIRMED',
            'start_utc' => $slot['start_utc'], 'duration_min' => (int) $slot['duration_min'],
            'summary' => $lunch['title'], 'description' => config('app_name', 'LunchSpot'),
            'location' => $lunch['location'] ?? '',
            'organizer_email' => $lunch['organizer_email'],
            'organizer_name' => organizer_display_name($lunch),
            'attendees' => $allAttendees,
        ]);
        $attachments = [['filename' => 'invitation.ics', 'content' => $ics,
            'mime' => 'text/calendar; charset=UTF-8; method=REQUEST']];
        $cleanupText = '';
        foreach ($placeholderCancels as $ph) {
            $attachments[] = ['filename' => 'retrait-' . $ph['slot_id'] . '.ics', 'content' => $ph['ics'],
                'mime' => 'text/calendar; charset=UTF-8; method=CANCEL'];
            $cleanupText .= '- ' . $ph['label'] . "\n";
        }

        // Liste des convives : l'organisateur reçoit un lien Google qui crée
        // l'événement AVEC tout le monde déjà invité ; chaque participant reçoit
        // un lien pour son propre agenda (sans réinviter les autres).
        $guestEmails = array_column($allAttendees, 'email');
        $guestEmails = array_values(array_diff($guestEmails, [$lunch['organizer_email']]));
        $glink = google_calendar_link(
            $slot['start_utc'], (int) $slot['duration_min'], $lunch['title'], '',
            $lunch['location'] ?? '',
            $isOrganizerOnly ? $guestEmails : []
        );
        $loc = $lunch['location'] ? '<p>' . h(__('email.invite.location', ['loc' => $lunch['location']])) . '</p>' : '';
        $cleanupHtml = $placeholderCancels
            ? '<p style="font-size:13px;color:#666;"><strong>' . h(__('email.confirm.cleanup')) . '</strong></p><ul>'
                . implode('', array_map(fn($ph) => '<li>' . h($ph['label']) . '</li>', $placeholderCancels)) . '</ul>'
            : '';

        // Récapitulatif des convives (utile surtout à l'organisateur).
        $guestList = '';
        $guestText = '';
        foreach ($allAttendees as $a) {
            $guestList .= '<li>' . h($a['name'] ?? $a['email']) . ' &lt;' . h($a['email']) . '&gt;</li>';
            $guestText .= '- ' . ($a['name'] ?? $a['email']) . ' <' . $a['email'] . ">\n";
        }

        if ($isOrganizerOnly) {
            // Email « pilote » : l'organisateur envoie l'invitation depuis son agenda.
            $html = email_html(
                h(__('email.confirm.heading_org', ['title' => $lunch['title']])),
                '<p>' . h(__('email.confirm.body')) . '</p>'
                . '<p style="font-size:16px;"><strong>' . h(fmt_slot($slot['start_utc'], (int) $slot['duration_min'])) . '</strong></p>'
                . $loc
                . '<p>' . h(__('email.confirm.org_intro')) . '</p>'
                . '<ul>' . $guestList . '</ul>'
                . email_button(__('email.confirm.org_cta'), $glink)
                . '<p style="font-size:13px;color:#666;">' . h(__('email.confirm.org_note')) . '</p>'
                . $cleanupHtml
            );
            $text = __('email.confirm.body') . "\n"
                . fmt_slot($slot['start_utc'], (int) $slot['duration_min']) . "\n"
                . ($lunch['location'] ? __('email.invite.location', ['loc' => $lunch['location']]) . "\n" : '')
                . "\n" . __('email.confirm.org_intro') . "\n" . $guestText
                . "\n" . __('email.confirm.org_cta') . " : $glink\n"
                . "\n" . __('email.confirm.org_note') . "\n"
                . ($cleanupText ? "\n" . __('email.confirm.cleanup') . "\n$cleanupText" : '');
            $opts = ['attachments' => $attachments];
        } else {
            $html = email_html(
                h(__('email.confirm.heading', ['title' => $lunch['title']])),
                '<p>' . h(__('email.hello', ['name' => $recipient['name'] ?? ''])) . '</p>'
                . '<p>' . h(__('email.confirm.body')) . '</p>'
                . '<p style="font-size:16px;"><strong>' . h(fmt_slot($slot['start_utc'], (int) $slot['duration_min'])) . '</strong></p>'
                . $loc
                . '<p>' . h(__('email.confirm.with_guests')) . '</p>'
                . '<ul>' . $guestList . '</ul>'
                . '<p>' . h(__('email.confirm.ics_note')) . '</p>'
                . email_button(__('email.add_gcal'), $glink)
                . $cleanupHtml
            );
            $text = __('email.hello', ['name' => $recipient['name'] ?? '']) . "\n\n"
                . __('email.confirm.body') . "\n"
                . fmt_slot($slot['start_utc'], (int) $slot['duration_min']) . "\n"
                . ($lunch['location'] ? __('email.invite.location', ['loc' => $lunch['location']]) . "\n" : '')
                . "\n" . __('email.confirm.with_guests') . "\n" . $guestText
                . "\n" . __('email.confirm.ics_note') . "\nGoogle : $glink\n"
                . ($cleanupText ? "\n" . __('email.confirm.cleanup') . "\n$cleanupText" : '');
            $opts = [
                'attachments' => $attachments,
                'reply_to' => $lunch['organizer_email'],
                'from_name' => sender_name($lunch),
            ];
        }
        send_mail($recipient['email'], __('email.confirm.subject', ['title' => $lunch['title']]), $html, $text, $opts);
    });
}

/** Nom d'expéditeur pour les emails aux participants : « Marie (via LunchSpot) ». */
function sender_name(array $lunch): string
{
    $app = (string) config('app_name', 'LunchSpot');
    $n = trim((string) ($lunch['organizer_name'] ?? ''));
    return $n !== '' ? $n . ' (via ' . $app . ')' : $app;
}

/* 8. Désistement / réouverture. */
function send_reopen_notice(array $lunch, array $recipient, string $whoLeft, string $cancelIcs, bool $isOrganizerOnly): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $recipient, $whoLeft, $cancelIcs, $isOrganizerOnly) {
        $url = $isOrganizerOnly ? admin_url($lunch) : participant_url($recipient);
        $cta = $isOrganizerOnly ? __('email.reopen.cta_org') : __('email.reopen.cta_part');
        $attachments = $cancelIcs ? [['filename' => 'annulation.ics', 'content' => $cancelIcs,
            'mime' => 'text/calendar; charset=UTF-8; method=CANCEL']] : [];
        $html = email_html(
            h(__('email.reopen.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.hello', ['name' => $recipient['name'] ?? ''])) . '</p>'
            . '<p>' . h(__('email.reopen.body', ['who' => $whoLeft])) . '</p>'
            . '<p>' . h(__('email.reopen.ask')) . '</p>'
            . email_button($cta, $url)
        );
        $text = __('email.hello', ['name' => $recipient['name'] ?? '']) . "\n\n"
            . __('email.reopen.body', ['who' => $whoLeft]) . "\n"
            . __('email.reopen.ask') . " $url";
        $opts = ['attachments' => $attachments];
        if (!$isOrganizerOnly) {
            $opts['reply_to'] = $lunch['organizer_email'];
            $opts['from_name'] = sender_name($lunch);
        }
        send_mail($recipient['email'], __('email.reopen.subject', ['title' => $lunch['title']]), $html, $text, $opts);
    });
}

/* 9. Annulation du déjeuner. */
function send_cancellation(array $lunch, array $recipient, string $cancelIcs, bool $isOrganizerOnly): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $recipient, $cancelIcs, $isOrganizerOnly) {
        $attachments = $cancelIcs ? [['filename' => 'annulation.ics', 'content' => $cancelIcs,
            'mime' => 'text/calendar; charset=UTF-8; method=CANCEL']] : [];
        $html = email_html(
            h(__('email.cancel.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.hello', ['name' => $recipient['name'] ?? ''])) . '</p>'
            . '<p>' . h(__('email.cancel.body', ['title' => $lunch['title']])) . '</p>'
            . '<p>' . h(__('email.cancel.ics_note')) . '</p>'
        );
        $text = __('email.hello', ['name' => $recipient['name'] ?? '']) . "\n\n"
            . __('email.cancel.body', ['title' => $lunch['title']]);
        $opts = ['attachments' => $attachments];
        if (!$isOrganizerOnly) {
            $opts['reply_to'] = $lunch['organizer_email'];
            $opts['from_name'] = sender_name($lunch);
        }
        send_mail($recipient['email'], __('email.cancel.subject', ['title' => $lunch['title']]), $html, $text, $opts);
    });
}

/* 7. Relance participant. */
function send_reminder(array $lunch, array $participant): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $participant) {
        $url = participant_url($participant);
        $suffix = $lunch['deadline'] ? __('email.invite.ask_deadline', ['date' => fmt_datetime($lunch['deadline'])]) : '';
        $html = email_html(
            h(__('email.reminder.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.hello', ['name' => $participant['name']])) . '</p>'
            . '<p>' . h(__('email.reminder.body', ['title' => $lunch['title'], 'suffix' => $suffix])) . '</p>'
            . email_button(__('email.reminder.cta'), $url)
        );
        $text = __('email.hello', ['name' => $participant['name']]) . "\n\n"
            . __('email.reminder.body', ['title' => $lunch['title'], 'suffix' => $suffix]) . "\n$url";
        send_mail($participant['email'], __('email.reminder.subject', ['title' => $lunch['title']]), $html, $text, [
            'reply_to' => $lunch['organizer_email'],
            'from_name' => sender_name($lunch),
        ]);
    });
}

/* 10. Rapport d'échéance à l'organisateur. */
function send_deadline_report(array $lunch, array $missing): void
{
    with_locale(lunch_locale($lunch), function () use ($lunch, $missing) {
        $admin = admin_url($lunch);
        $items = '';
        $textList = '';
        foreach ($missing as $m) {
            $items .= '<li>' . h($m['name']) . ' &lt;' . h($m['email']) . '&gt;</li>';
            $textList .= "- {$m['name']} <{$m['email']}>\n";
        }
        $listHtml = $missing
            ? '<p>' . h(__('email.report.missing')) . '</p><ul>' . $items . '</ul>'
            : '<p>' . h(__('email.report.all_answered')) . '</p>';
        $html = email_html(
            h(__('email.report.heading', ['title' => $lunch['title']])),
            '<p>' . h(__('email.report.passed')) . '</p>' . $listHtml
            . '<p>' . h(__('email.report.actions')) . '</p>'
            . email_button(__('email.report.cta'), $admin)
        );
        $text = __('email.report.passed') . "\n"
            . ($missing ? __('email.report.missing') . "\n$textList" : __('email.report.all_answered') . "\n")
            . "\n$admin";
        send_mail($lunch['organizer_email'], __('email.report.subject', ['title' => $lunch['title']]), $html, $text);
    });
}

/* 2. Magic link (langue = navigateur du demandeur). Retourne le succès d'envoi. */
function send_magic_link(string $email, string $link): bool
{
    $app = config('app_name', 'LunchSpot');
    $min = (int) config('magic_link_ttl_minutes', 15);
    $html = email_html(
        h(__('email.magic.heading')),
        '<p>' . h(__('email.magic.body', ['app' => $app])) . '</p>'
        . email_button(__('email.magic.cta'), $link)
        . '<p style="font-size:13px;color:#666;">' . h(__('email.magic.note', ['min' => $min])) . '</p>'
    );
    $text = __('email.magic.body', ['app' => $app]) . "\n$link\n\n" . __('email.magic.note', ['min' => $min]);
    return send_mail($email, __('email.magic.subject', ['app' => $app]), $html, $text);
}
