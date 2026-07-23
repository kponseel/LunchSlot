<?php
/**
 * Internationalisation FR/EN.
 * Langue résolue par : ?lang= → cookie ls_lang → Accept-Language → 'fr'.
 * Les emails peuvent forcer une locale via set_locale() (locale du déjeuner).
 */

declare(strict_types=1);

const SUPPORTED_LOCALES = ['fr', 'en'];

function detect_locale(): string
{
    // 1. Paramètre explicite.
    $q = $_GET['lang'] ?? null;
    if (is_string($q) && in_array($q, SUPPORTED_LOCALES, true)) {
        return $q;
    }
    // 2. Cookie.
    $c = $_COOKIE['ls_lang'] ?? null;
    if (is_string($c) && in_array($c, SUPPORTED_LOCALES, true)) {
        return $c;
    }
    // 3. En-tête Accept-Language.
    $al = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (preg_match_all('/([a-z]{2})(?:-[a-z]{2})?\s*(?:;q=([0-9.]+))?/i', $al, $m, PREG_SET_ORDER)) {
        $best = null;
        $bestQ = -1;
        foreach ($m as $part) {
            $lang = strtolower($part[1]);
            $q = isset($part[2]) && $part[2] !== '' ? (float) $part[2] : 1.0;
            if (in_array($lang, SUPPORTED_LOCALES, true) && $q > $bestQ) {
                $best = $lang;
                $bestQ = $q;
            }
        }
        if ($best !== null) {
            return $best;
        }
    }
    return 'fr';
}

/** Locale courante (avec surcharge possible). */
function current_locale(?string $set = null): string
{
    static $locale = null;
    if ($set !== null) {
        $locale = in_array($set, SUPPORTED_LOCALES, true) ? $set : 'fr';
        return $locale;
    }
    if ($locale === null) {
        $locale = detect_locale();
    }
    return $locale;
}

/** Force la locale (emails dans la langue du déjeuner). */
function set_locale(string $loc): void
{
    current_locale($loc);
}

/** Traduction avec substitution de :placeholders. */
function __(string $key, array $vars = []): string
{
    $loc = current_locale();
    $dict = i18n_dict();
    $str = $dict[$loc][$key] ?? $dict['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace(':' . $k, (string) $v, $str);
    }
    return $str;
}

/** Sélecteur de langue (liens) pour l'en-tête. */
function locale_switcher_html(): string
{
    $cur = current_locale();
    $base = rtrim(config('app_url'), '/');
    $out = [];
    foreach (SUPPORTED_LOCALES as $l) {
        $label = strtoupper($l);
        if ($l === $cur) {
            $out[] = '<strong>' . $label . '</strong>';
        } else {
            $out[] = '<a href="' . h($base . '/setlang.php?lang=' . $l) . '">' . $label . '</a>';
        }
    }
    return '<span class="langsw">' . implode(' · ', $out) . '</span>';
}

function i18n_dict(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $d = ['fr' => [], 'en' => []];

    $d['fr'] = [
        // Navigation / commun
        'nav.my_lunches' => 'Mes déjeuners',
        'nav.new_lunch' => 'Nouveau déjeuner',
        'nav.logout' => 'Déconnexion',
        'footer.tagline' => 'planification de déjeuners professionnels.',
        'status.en_attente' => 'En attente de réponses',
        'status.confirme' => 'Confirmé',
        'status.annule' => 'Annulé',

        // Accueil / connexion
        'index.title' => 'Connexion',
        'index.h1' => 'Organisez un déjeuner en supprimant les allers-retours',
        'index.pitch' => 'LunchSlot cale votre déjeuner professionnel à plusieurs : chacun indique ses disponibilités, les créneaux sont <strong>pré-bloqués dans les agendas</strong>, et dès qu\'une date convient à tout le monde, l\'<strong>invitation calendrier part automatiquement</strong>.',
        'index.login_h2' => 'Connexion organisateur',
        'index.login_help' => 'Saisissez votre email : vous recevrez un lien de connexion à usage unique (valable :min minutes). Aucun mot de passe.',
        'index.email_label' => 'Votre email',
        'index.login_btn' => 'Recevoir mon lien de connexion',
        'login.sent' => 'Si un compte est associé à cette adresse, un lien de connexion vient d\'être envoyé. Pensez à vérifier vos spams.',

        // Verify / logout / auth
        'verify.invalid_title' => 'Lien invalide',
        'verify.invalid_h1' => 'Lien invalide ou expiré',
        'verify.invalid_body' => 'Ce lien de connexion a expiré, a déjà été utilisé, ou n\'est pas valide. Les liens sont à usage unique et expirent après :min minutes.',
        'verify.request_new' => 'Demander un nouveau lien',
        'verify.connected' => 'Vous êtes connecté·e.',
        'logout.done' => 'Vous êtes déconnecté·e.',
        'auth.please_login' => 'Veuillez vous connecter pour continuer.',

        // Mes déjeuners
        'my.title' => 'Mes déjeuners',
        'my.h1' => 'Mes déjeuners',
        'my.new_btn' => '+ Nouveau déjeuner',
        'my.upcoming' => 'À venir',
        'my.past' => 'Passés / annulés',
        'my.none' => 'Aucun déjeuner.',
        'my.col_title' => 'Titre',
        'my.col_status' => 'Statut',
        'my.col_slots' => 'Créneaux',
        'my.col_participants' => 'Participants',
        'my.dashboard_btn' => 'Tableau de bord',

        // Création
        'create.title' => 'Nouveau déjeuner',
        'create.h1' => 'Nouveau déjeuner',
        'create.title_label' => 'Titre *',
        'create.title_ph' => 'Déjeuner projet Acme',
        'create.location_label' => 'Restaurant / lieu (optionnel)',
        'create.location_ph' => 'Le Bistrot, 12 rue…',
        'create.deadline_label' => 'Date limite de réponse (optionnel)',
        'create.participants_label' => 'Participants',
        'create.participants_hint' => 'nom + email',
        'create.slots_label' => 'Créneaux proposés',
        'create.slots_hint' => 'date, heure, durée',
        'create.i_participate' => 'Je participe aussi (je compte alors dans l\'unanimité)',
        'create.my_name_label' => 'Mon nom (si je participe)',
        'create.my_name_ph' => 'Votre nom',
        'create.p_name' => 'Nom',
        'create.p_email' => 'Email',
        'create.add_participant' => '+ Ajouter un participant',
        'create.add_slot' => '+ Ajouter un créneau',
        'create.remove' => 'Retirer',
        'create.submit' => 'Créer et envoyer les invitations',
        'create.err_title' => 'Le titre est obligatoire.',
        'create.err_participant' => 'Participant invalide : « :line » (attendu : Nom, email).',
        'create.err_no_participant' => 'Ajoutez au moins un participant.',
        'create.err_slot' => 'Créneau invalide : « :line » (attendu : AAAA-MM-JJ, HH:MM, durée).',
        'create.err_no_slot' => 'Ajoutez au moins un créneau.',
        'create.created_flash' => 'Déjeuner créé. Les invitations ont été envoyées.',

        // Réponse participant
        'resp.title' => 'Mes disponibilités',
        'resp.invalid_h1' => 'Lien invalide',
        'resp.invalid_body' => 'Ce lien de réponse n\'est pas valide.',
        'resp.hello' => 'Bonjour :name.',
        'resp.canceled_body' => 'Ce déjeuner a été annulé par l\'organisateur.',
        'resp.confirmed_intro' => '✅ Déjeuner confirmé pour le :',
        'resp.confirmed_email_note' => 'L\'invitation calendrier vous a été envoyée par email.',
        'resp.withdraw_btn' => 'Je me désiste',
        'resp.withdraw_confirm_js' => 'Confirmer votre désistement ?',
        'resp.your_avail_h2' => 'Vos disponibilités',
        'resp.no_slots' => 'Aucun créneau pour le moment.',
        'resp.available' => 'Disponible',
        'resp.unavailable' => 'Indisponible',
        'resp.proposed_tag' => '(proposé)',
        'resp.save_btn' => 'Enregistrer mes disponibilités',
        'resp.propose_h2' => 'Proposer un autre créneau',
        'resp.date' => 'Date',
        'resp.time' => 'Heure',
        'resp.duration' => 'Durée (min)',
        'resp.propose_btn' => 'Proposer ce créneau',
        'resp.saved' => 'Vos disponibilités sont enregistrées. Un email récapitulatif vous a été envoyé.',
        'resp.confirmed_flash' => 'Vos réponses ont permis de confirmer le déjeuner ! Vérifiez votre email.',
        'resp.proposed_flash' => 'Votre créneau a été proposé ; les autres participants sont notifiés.',
        'resp.invalid_slot' => 'Créneau invalide.',
        'resp.withdraw_flash' => 'Votre désistement est enregistré ; les participants ont été prévenus.',

        // Tableau de bord
        'dash.title' => 'Tableau de bord',
        'dash.not_found' => 'Déjeuner introuvable ou lien invalide.',
        'dash.deadline' => 'Date limite',
        'dash.no_deadline' => 'aucune',
        'dash.matrix_h2' => 'Matrice créneaux × participants',
        'dash.slot' => 'Créneau',
        'dash.summary' => 'Bilan',
        'dash.confirmed_here' => 'Confirmé',
        'dash.possible' => 'possible :x/:n',
        'dash.impossible' => 'impossible',
        'dash.action' => 'Action',
        'dash.confirm_btn' => 'Confirmer',
        'dash.responded_h2' => 'Qui a répondu',
        'dash.complete' => 'Complet',
        'dash.partial' => 'Partiel',
        'dash.pending' => 'En attente',
        'dash.last_reminded' => 'Dernière relance : :when',
        'dash.copy_link' => 'Lien de réponse',
        'dash.resend' => 'Renvoyer l\'invitation',
        'dash.remind' => 'Relancer',
        'dash.addslot_h2' => 'Ajouter un créneau',
        'dash.addslot_btn' => 'Ajouter (et notifier les participants)',
        'dash.cancel_h2' => 'Annuler le déjeuner',
        'dash.cancel_btn' => 'Annuler le déjeuner',
        'dash.cancel_confirm_js' => 'Annuler ce déjeuner et prévenir tout le monde ?',
        'dash.confirmed_banner' => 'Déjeuner confirmé pour le :slot',
        'dash.canceled_banner' => 'Ce déjeuner est annulé.',
        'dash.flash_added' => 'Créneau ajouté ; les participants sont notifiés.',
        'dash.flash_resent' => 'Invitation renvoyée.',
        'dash.flash_reminded' => 'Relance envoyée.',
        'dash.flash_remind_skip' => 'Relance ignorée (intervalle anti-spam non écoulé).',
        'dash.flash_confirmed' => 'Déjeuner confirmé ; invitations envoyées.',
        'dash.flash_confirm_refused' => 'Impossible : un participant a refusé ce créneau.',
        'dash.flash_canceled' => 'Déjeuner annulé ; annulations envoyées.',
        'dash.you_participate' => 'Vous participez à ce déjeuner.',

        // Emails — communs
        'email.footer' => 'Email automatique — :app.',
        'email.hello' => 'Bonjour :name,',
        'email.add_gcal' => 'Ajouter à Google Calendar',

        // Email invitation
        'email.invite.subject' => 'Invitation — :title',
        'email.invite.heading' => 'Vous êtes invité·e : :title',
        'email.invite.body' => 'Vous êtes convié·e au déjeuner « :title ».',
        'email.invite.location' => 'Lieu : :loc',
        'email.invite.ask' => 'Merci d\'indiquer vos disponibilités :suffix :',
        'email.invite.ask_deadline' => 'avant le :date',
        'email.invite.cta' => 'Indiquer mes disponibilités',
        'email.invite.note' => 'Aucun compte n\'est nécessaire. Ce lien vous est personnel.',

        // Email créé (organisateur)
        'email.created.subject' => 'Déjeuner créé — :title',
        'email.created.heading' => 'Déjeuner créé : :title',
        'email.created.body' => 'Votre déjeuner a bien été créé et les invitations ont été envoyées à :',
        'email.created.dashboard' => 'Suivez les réponses depuis votre tableau de bord :',
        'email.created.cta' => 'Ouvrir le tableau de bord',

        // Email placeholders
        'email.ph.subject' => 'Vos disponibilités — :title',
        'email.ph.heading' => 'Vos disponibilités enregistrées — :title',
        'email.ph.recorded' => 'Bonjour :name, vos réponses ont bien été prises en compte.',
        'email.ph.block_intro' => 'Pour éviter qu\'un de ces créneaux soit réservé par-dessus, ajoutez-les à votre agenda en un clic (lien Google ci-dessous) ou via la pièce jointe .ics :',
        'email.ph.cancel_intro' => 'Blocages à retirer (annulation jointe ; si votre agenda ne la traite pas, supprimez-les manuellement) :',
        'email.ph.note' => 'Ces créneaux sont provisoires. Vous recevrez l\'invitation définitive dès qu\'une date convient à tout le monde.',
        'email.ph.provisional_prefix' => '[Provisoire]',

        // Email nouveau créneau
        'email.newslot.subject' => 'Nouveau créneau — :title',
        'email.newslot.heading' => 'Nouveau créneau proposé — :title',
        'email.newslot.body' => ':who a proposé un nouveau créneau :',
        'email.newslot.ask' => 'Merci de vous positionner :',
        'email.newslot.cta_part' => 'Me positionner',
        'email.newslot.cta_org' => 'Voir le tableau de bord',

        // Email confirmation
        'email.confirm.subject' => 'Confirmé — :title',
        'email.confirm.heading' => '✅ Confirmé : :title',
        'email.confirm.body' => 'Le déjeuner est confirmé pour le :',
        'email.confirm.ics_note' => 'L\'invitation calendrier est en pièce jointe.',
        'email.confirm.cleanup' => 'Blocages provisoires à supprimer (annulations jointes ; sinon supprimez-les manuellement) :',

        // Email désistement / réouverture
        'email.reopen.subject' => 'Créneau annulé — :title',
        'email.reopen.heading' => 'Créneau annulé — :title',
        'email.reopen.body' => ':who s\'est désisté·e : le créneau confirmé est annulé et le déjeuner est rouvert.',
        'email.reopen.ask' => 'Merci de revérifier vos disponibilités :',
        'email.reopen.cta_part' => 'Revérifier mes disponibilités',
        'email.reopen.cta_org' => 'Voir le tableau de bord',

        // Email annulation
        'email.cancel.subject' => 'Annulé — :title',
        'email.cancel.heading' => 'Déjeuner annulé — :title',
        'email.cancel.body' => 'Le déjeuner « :title » a été annulé par l\'organisateur.',
        'email.cancel.ics_note' => 'Si un créneau était confirmé, l\'annulation calendrier est jointe.',

        // Email relance
        'email.reminder.subject' => 'Rappel — :title',
        'email.reminder.heading' => 'Rappel : vos disponibilités — :title',
        'email.reminder.body' => 'Nous attendons encore vos disponibilités pour « :title » :suffix.',
        'email.reminder.cta' => 'Répondre maintenant',

        // Email rapport d'échéance
        'email.report.subject' => 'Échéance — :title',
        'email.report.heading' => 'Échéance atteinte — :title',
        'email.report.passed' => 'La date limite de réponse est dépassée.',
        'email.report.missing' => 'Participants sans réponse complète :',
        'email.report.all_answered' => 'Tous les participants ont répondu, mais aucun créneau ne fait l\'unanimité.',
        'email.report.actions' => 'Vous pouvez relancer, ajouter un créneau, confirmer manuellement ou annuler :',
        'email.report.cta' => 'Ouvrir le tableau de bord',

        // Email magic link
        'email.magic.subject' => 'Votre lien de connexion — :app',
        'email.magic.heading' => 'Votre lien de connexion',
        'email.magic.body' => 'Voici votre lien de connexion à :app :',
        'email.magic.cta' => 'Me connecter',
        'email.magic.note' => 'Ce lien expire dans :min minutes et ne fonctionne qu\'une fois. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.',
    ];

    $d['en'] = [
        'nav.my_lunches' => 'My lunches',
        'nav.new_lunch' => 'New lunch',
        'nav.logout' => 'Log out',
        'footer.tagline' => 'professional lunch scheduling.',
        'status.en_attente' => 'Awaiting replies',
        'status.confirme' => 'Confirmed',
        'status.annule' => 'Cancelled',

        'index.title' => 'Sign in',
        'index.h1' => 'Schedule a lunch without the back-and-forth',
        'index.pitch' => 'LunchSlot books your team lunch: everyone marks their availability, candidate slots are <strong>pre-blocked in calendars</strong>, and as soon as a time works for everyone the <strong>calendar invite is sent automatically</strong>.',
        'index.login_h2' => 'Organiser sign-in',
        'index.login_help' => 'Enter your email: you\'ll get a single-use sign-in link (valid for :min minutes). No password.',
        'index.email_label' => 'Your email',
        'index.login_btn' => 'Send my sign-in link',
        'login.sent' => 'If an account matches this address, a sign-in link has just been sent. Check your spam folder too.',

        'verify.invalid_title' => 'Invalid link',
        'verify.invalid_h1' => 'Invalid or expired link',
        'verify.invalid_body' => 'This sign-in link has expired, was already used, or is invalid. Links are single-use and expire after :min minutes.',
        'verify.request_new' => 'Request a new link',
        'verify.connected' => 'You are signed in.',
        'logout.done' => 'You are signed out.',
        'auth.please_login' => 'Please sign in to continue.',

        'my.title' => 'My lunches',
        'my.h1' => 'My lunches',
        'my.new_btn' => '+ New lunch',
        'my.upcoming' => 'Upcoming',
        'my.past' => 'Past / cancelled',
        'my.none' => 'No lunches.',
        'my.col_title' => 'Title',
        'my.col_status' => 'Status',
        'my.col_slots' => 'Slots',
        'my.col_participants' => 'Participants',
        'my.dashboard_btn' => 'Dashboard',

        'create.title' => 'New lunch',
        'create.h1' => 'New lunch',
        'create.title_label' => 'Title *',
        'create.title_ph' => 'Acme project lunch',
        'create.location_label' => 'Restaurant / place (optional)',
        'create.location_ph' => 'The Bistro, 12 Main St…',
        'create.deadline_label' => 'Response deadline (optional)',
        'create.participants_label' => 'Participants',
        'create.participants_hint' => 'name + email',
        'create.slots_label' => 'Proposed slots',
        'create.slots_hint' => 'date, time, duration',
        'create.i_participate' => 'I\'m attending too (I then count toward unanimity)',
        'create.my_name_label' => 'My name (if attending)',
        'create.my_name_ph' => 'Your name',
        'create.p_name' => 'Name',
        'create.p_email' => 'Email',
        'create.add_participant' => '+ Add participant',
        'create.add_slot' => '+ Add slot',
        'create.remove' => 'Remove',
        'create.submit' => 'Create and send invitations',
        'create.err_title' => 'Title is required.',
        'create.err_participant' => 'Invalid participant: “:line” (expected: Name, email).',
        'create.err_no_participant' => 'Add at least one participant.',
        'create.err_slot' => 'Invalid slot: “:line” (expected: YYYY-MM-DD, HH:MM, duration).',
        'create.err_no_slot' => 'Add at least one slot.',
        'create.created_flash' => 'Lunch created. Invitations have been sent.',

        'resp.title' => 'My availability',
        'resp.invalid_h1' => 'Invalid link',
        'resp.invalid_body' => 'This response link is not valid.',
        'resp.hello' => 'Hello :name.',
        'resp.canceled_body' => 'This lunch was cancelled by the organiser.',
        'resp.confirmed_intro' => '✅ Lunch confirmed for:',
        'resp.confirmed_email_note' => 'The calendar invite has been emailed to you.',
        'resp.withdraw_btn' => 'Withdraw',
        'resp.withdraw_confirm_js' => 'Confirm your withdrawal?',
        'resp.your_avail_h2' => 'Your availability',
        'resp.no_slots' => 'No slots yet.',
        'resp.available' => 'Available',
        'resp.unavailable' => 'Unavailable',
        'resp.proposed_tag' => '(proposed)',
        'resp.save_btn' => 'Save my availability',
        'resp.propose_h2' => 'Propose another slot',
        'resp.date' => 'Date',
        'resp.time' => 'Time',
        'resp.duration' => 'Duration (min)',
        'resp.propose_btn' => 'Propose this slot',
        'resp.saved' => 'Your availability has been saved. A summary email has been sent to you.',
        'resp.confirmed_flash' => 'Your replies confirmed the lunch! Check your email.',
        'resp.proposed_flash' => 'Your slot has been proposed; the other participants are notified.',
        'resp.invalid_slot' => 'Invalid slot.',
        'resp.withdraw_flash' => 'Your withdrawal is recorded; participants have been notified.',

        'dash.title' => 'Dashboard',
        'dash.not_found' => 'Lunch not found or invalid link.',
        'dash.deadline' => 'Deadline',
        'dash.no_deadline' => 'none',
        'dash.matrix_h2' => 'Slots × participants matrix',
        'dash.slot' => 'Slot',
        'dash.summary' => 'Summary',
        'dash.confirmed_here' => 'Confirmed',
        'dash.possible' => 'possible :x/:n',
        'dash.impossible' => 'impossible',
        'dash.action' => 'Action',
        'dash.confirm_btn' => 'Confirm',
        'dash.responded_h2' => 'Who replied',
        'dash.complete' => 'Complete',
        'dash.partial' => 'Partial',
        'dash.pending' => 'Pending',
        'dash.last_reminded' => 'Last reminder: :when',
        'dash.copy_link' => 'Response link',
        'dash.resend' => 'Resend invitation',
        'dash.remind' => 'Remind',
        'dash.addslot_h2' => 'Add a slot',
        'dash.addslot_btn' => 'Add (and notify participants)',
        'dash.cancel_h2' => 'Cancel the lunch',
        'dash.cancel_btn' => 'Cancel the lunch',
        'dash.cancel_confirm_js' => 'Cancel this lunch and notify everyone?',
        'dash.confirmed_banner' => 'Lunch confirmed for :slot',
        'dash.canceled_banner' => 'This lunch is cancelled.',
        'dash.flash_added' => 'Slot added; participants are notified.',
        'dash.flash_resent' => 'Invitation resent.',
        'dash.flash_reminded' => 'Reminder sent.',
        'dash.flash_remind_skip' => 'Reminder skipped (anti-spam interval not elapsed).',
        'dash.flash_confirmed' => 'Lunch confirmed; invitations sent.',
        'dash.flash_confirm_refused' => 'Not possible: a participant declined this slot.',
        'dash.flash_canceled' => 'Lunch cancelled; cancellations sent.',
        'dash.you_participate' => 'You are attending this lunch.',

        'email.footer' => 'Automated email — :app.',
        'email.hello' => 'Hello :name,',
        'email.add_gcal' => 'Add to Google Calendar',

        'email.invite.subject' => 'Invitation — :title',
        'email.invite.heading' => 'You\'re invited: :title',
        'email.invite.body' => 'You are invited to the lunch “:title”.',
        'email.invite.location' => 'Place: :loc',
        'email.invite.ask' => 'Please mark your availability :suffix:',
        'email.invite.ask_deadline' => 'before :date',
        'email.invite.cta' => 'Mark my availability',
        'email.invite.note' => 'No account needed. This link is personal to you.',

        'email.created.subject' => 'Lunch created — :title',
        'email.created.heading' => 'Lunch created: :title',
        'email.created.body' => 'Your lunch has been created and invitations were sent to:',
        'email.created.dashboard' => 'Track replies from your dashboard:',
        'email.created.cta' => 'Open the dashboard',

        'email.ph.subject' => 'Your availability — :title',
        'email.ph.heading' => 'Your availability saved — :title',
        'email.ph.recorded' => 'Hello :name, your replies have been recorded.',
        'email.ph.block_intro' => 'To keep any of these slots from being booked over, add them to your calendar in one click (Google link below) or via the attached .ics:',
        'email.ph.cancel_intro' => 'Blocks to remove (cancellation attached; if your calendar doesn\'t process it, delete them manually):',
        'email.ph.note' => 'These slots are provisional. You\'ll get the final invite as soon as a time works for everyone.',
        'email.ph.provisional_prefix' => '[Provisional]',

        'email.newslot.subject' => 'New slot — :title',
        'email.newslot.heading' => 'New slot proposed — :title',
        'email.newslot.body' => ':who proposed a new slot:',
        'email.newslot.ask' => 'Please weigh in:',
        'email.newslot.cta_part' => 'Weigh in',
        'email.newslot.cta_org' => 'View the dashboard',

        'email.confirm.subject' => 'Confirmed — :title',
        'email.confirm.heading' => '✅ Confirmed: :title',
        'email.confirm.body' => 'The lunch is confirmed for:',
        'email.confirm.ics_note' => 'The calendar invite is attached.',
        'email.confirm.cleanup' => 'Provisional blocks to delete (cancellations attached; otherwise delete manually):',

        'email.reopen.subject' => 'Slot cancelled — :title',
        'email.reopen.heading' => 'Slot cancelled — :title',
        'email.reopen.body' => ':who withdrew: the confirmed slot is cancelled and the lunch is reopened.',
        'email.reopen.ask' => 'Please re-check your availability:',
        'email.reopen.cta_part' => 'Re-check my availability',
        'email.reopen.cta_org' => 'View the dashboard',

        'email.cancel.subject' => 'Cancelled — :title',
        'email.cancel.heading' => 'Lunch cancelled — :title',
        'email.cancel.body' => 'The lunch “:title” was cancelled by the organiser.',
        'email.cancel.ics_note' => 'If a slot was confirmed, the calendar cancellation is attached.',

        'email.reminder.subject' => 'Reminder — :title',
        'email.reminder.heading' => 'Reminder: your availability — :title',
        'email.reminder.body' => 'We\'re still waiting for your availability for “:title” :suffix.',
        'email.reminder.cta' => 'Reply now',

        'email.report.subject' => 'Deadline — :title',
        'email.report.heading' => 'Deadline reached — :title',
        'email.report.passed' => 'The response deadline has passed.',
        'email.report.missing' => 'Participants without a complete reply:',
        'email.report.all_answered' => 'Everyone replied, but no slot is unanimous.',
        'email.report.actions' => 'You can remind, add a slot, confirm manually or cancel:',
        'email.report.cta' => 'Open the dashboard',

        'email.magic.subject' => 'Your sign-in link — :app',
        'email.magic.heading' => 'Your sign-in link',
        'email.magic.body' => 'Here is your sign-in link to :app:',
        'email.magic.cta' => 'Sign in',
        'email.magic.note' => 'This link expires in :min minutes and works only once. If you didn\'t request it, ignore this email.',
    ];

    return $d;
}
