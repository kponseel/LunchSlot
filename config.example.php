<?php
/**
 * LunchSpot — configuration.
 *
 * Copiez ce fichier en `config.php` et adaptez les valeurs.
 * `config.php` ne doit JAMAIS être accessible en HTTP (voir .htaccess).
 */

return [
    // Nom affiché du produit.
    'app_name' => 'LunchSpot',

    // Affichage des erreurs PHP à l'écran. false en production (recommandé) :
    // les erreurs vont dans les logs, pas dans la page. true seulement pour déboguer.
    'debug' => false,

    // URL de base publique, SANS slash final.
    // Sert à construire les liens absolus dans les emails.
    // Ex. production : 'https://exemple.com/lunchspot'
    // Ex. serveur PHP intégré : 'http://localhost:8000'
    'app_url' => 'http://localhost:8000',

    // Fuseau d'affichage. Les .ics et liens Google sont convertis en UTC.
    'timezone' => 'Europe/Paris',

    // Chemin du fichier SQLite (créé automatiquement au premier lancement).
    'db_path' => __DIR__ . '/data/lunchspot.sqlite',

    // Domaine utilisé dans les UID iCalendar (partie après @). Doit être stable.
    'ics_domain' => 'lunchspot.local',

    // ---- Emails --------------------------------------------------------
    // Transport : 'log' (écrit dans data/maillog, aucun envoi — idéal en dev/test),
    //             'mail' (fonction mail() PHP), 'smtp' (voir smtp_* ci-dessous).
    'mail_transport' => 'log',

    'mail_from'      => 'lunchspot@exemple.com',
    'mail_from_name' => 'LunchSpot',

    // Utilisés si mail_transport = 'smtp'
    'smtp_host'     => '',
    'smtp_port'     => 587,
    'smtp_security' => 'tls', // 'tls' (STARTTLS), 'ssl' (implicite), '' (aucune)
    'smtp_user'     => '',
    'smtp_pass'     => '',

    // ---- Anti-abus magic link -----------------------------------------
    'magic_link_ttl_minutes'   => 15,   // durée de validité d'un lien de connexion
    'session_ttl_days'         => 30,   // durée d'une session organisateur
    'magic_per_email_per_15min' => 3,   // envois max par email / 15 min
    'magic_per_ip_per_hour'     => 10,  // envois max par IP / heure

    // ---- Relances (cron) ----------------------------------------------
    'reminder_min_interval_hours' => 20, // délai minimal entre 2 relances d'une même personne
];
