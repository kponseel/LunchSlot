<?php
/**
 * Connexion SQLite (PDO) + migrations automatiques au démarrage.
 *
 * Toutes les dates sont stockées en UTC au format 'Y-m-d H:i:s'.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = ls_db_path();
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Fiabilité concurrente (confirmation atomique) : WAL + attente sur verrou.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    db_migrate($pdo);

    return $pdo;
}

/**
 * Crée/complète le schéma sans jamais détruire de données existantes.
 */
function db_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS organizers (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        email         TEXT NOT NULL UNIQUE,
        created_at    TEXT NOT NULL,
        last_login_at TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS magic_links (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at    TEXT,
        created_ip TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_magic_email ON magic_links (email, created_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        organizer_id INTEGER NOT NULL,
        token_hash   TEXT NOT NULL UNIQUE,
        expires_at   TEXT NOT NULL,
        created_at   TEXT NOT NULL,
        last_seen_at TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS lunches (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        title             TEXT NOT NULL,
        location          TEXT,
        organizer_email   TEXT NOT NULL,
        admin_token       TEXT NOT NULL UNIQUE,
        status            TEXT NOT NULL DEFAULT \'en_attente\',
        timezone          TEXT NOT NULL DEFAULT \'Europe/Paris\',
        deadline          TEXT,
        confirmed_slot_id INTEGER,
        created_at        TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lunch_org ON lunches (organizer_email)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS participants (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        lunch_id       INTEGER NOT NULL,
        name           TEXT NOT NULL,
        email          TEXT NOT NULL,
        token          TEXT NOT NULL UNIQUE,
        is_organizer   INTEGER NOT NULL DEFAULT 0,
        last_reminded_at TEXT,
        created_at     TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_part_lunch ON participants (lunch_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS slots (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        lunch_id     INTEGER NOT NULL,
        start_utc    TEXT NOT NULL,
        duration_min INTEGER NOT NULL DEFAULT 60,
        proposed_by  INTEGER,
        created_at   TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_slot_lunch ON slots (lunch_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS responses (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        participant_id INTEGER NOT NULL,
        slot_id        INTEGER NOT NULL,
        available      INTEGER NOT NULL,
        updated_at     TEXT NOT NULL,
        UNIQUE (participant_id, slot_id)
    )');

    // Suivi des séquences iCalendar par UID (placeholders + événements confirmés).
    $pdo->exec('CREATE TABLE IF NOT EXISTS ics_state (
        uid         TEXT PRIMARY KEY,
        sequence    INTEGER NOT NULL DEFAULT 0,
        last_method TEXT,
        updated_at  TEXT NOT NULL
    )');

    // Journal d'événements email — sert à l'idempotence (ex. rapport d'échéance unique).
    $pdo->exec('CREATE TABLE IF NOT EXISTS mail_events (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        lunch_id   INTEGER,
        kind       TEXT NOT NULL,
        recipient  TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mailev ON mail_events (lunch_id, kind)');

    // Compteur de rate-limiting magic link par IP.
    $pdo->exec('CREATE TABLE IF NOT EXISTS magic_ip_hits (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ip         TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ip_hits ON magic_ip_hits (ip, created_at)');

    // Migrations additives.
    db_add_column_if_missing($pdo, 'lunches', 'locale', "TEXT NOT NULL DEFAULT 'fr'");
    db_add_column_if_missing($pdo, 'lunches', 'organizer_name', 'TEXT');
}

/**
 * Ajoute une colonne si elle n'existe pas déjà (migration additive sûre).
 */
function db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $c) {
        if ($c['name'] === $column) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}
