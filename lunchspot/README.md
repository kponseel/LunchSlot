# LunchSpot — application

Planification de déjeuners professionnels multi-participants. **PHP 8 + SQLite**, sans build ni
composer, déployable par simple upload de fichiers. Interface **bilingue FR/EN** (détection via le
navigateur). Voir la spécification fonctionnelle : [`../docs/SPEC-FONCTIONNELLE-LunchSpot.md`](../docs/SPEC-FONCTIONNELLE-LunchSpot.md).

## Idée cœur

- **Confirmation automatique** : dès qu'un créneau convient à *tous*, l'invitation calendrier
  (`.ics REQUEST` + lien Google) part sans intervention humaine.
- **Placeholders** : à chaque réponse, chaque créneau « Disponible » est pré-bloqué
  (`.ics TENTATIVE` + lien Google), UID déterministes pour pouvoir les annuler ensuite.

## Lancer en local (serveur PHP intégré)

```bash
cd <racine du dépôt>
cp lunchspot/config.example.php lunchspot/config.php   # puis ajustez si besoin
php -S localhost:8000 -t lunchspot lunchspot/router.php
```

Ouvrez http://localhost:8000. En transport email `log` (par défaut), aucun email n'est réellement
envoyé : ils sont écrits dans `lunchspot/data/maillog/*.eml` (pratique pour les tests). Le
`router.php` reproduit, pour le serveur intégré, les protections que `.htaccess` applique en
production (blocage de `data/`, `config.php`, `*.inc.php`).

> `app_url` (dans `config.php`) doit correspondre à l'URL réelle de service (ici
> `http://localhost:8000`). En production, mettez l'URL publique complète.

## Tests

```bash
php lunchspot/tests/run.php
```

Suite sans dépendance couvrant les 6 critères d'acceptation + cas limites (fuseau→UTC,
idempotence de la confirmation, organisateur votant, désistement sans autre unanimité,
confirmation manuelle, annulation, structure iCalendar). La base et les emails de test sont isolés
dans un dossier temporaire (`LUNCHSPOT_DB`) — aucune donnée de dev n'est touchée.

## Déploiement Hostinger (hébergement mutualisé)

1. Uploadez le dossier `lunchspot/` dans `public_html` (ou le sous-dossier voulu) via le File
   Manager hPanel.
2. Copiez `config.example.php` en `config.php` et renseignez : `app_url`, `timezone`,
   `mail_transport` (`mail` ou `smtp`), les paramètres SMTP le cas échéant.
3. Vérifiez que `data/` est inscriptible (la base SQLite s'y crée toute seule au premier accès ;
   migrations automatiques).
4. `.htaccess` protège déjà `data/` et `config.php` en HTTP. En bonus, placez `data/` hors du
   dossier public si votre hébergement le permet et ajustez `db_path`.
5. **Cron** (hPanel → Cron Jobs) : `php /home/USER/domains/…/lunchspot/cron_relance.php`
   (par ex. toutes les heures) pour les relances et le rapport d'échéance.

## Structure

```
lunchspot/
  index.php            Accueil + connexion (magic link)
  login.php verify.php logout.php   Flux d'authentification
  mes-dejeuners.php    Espace organisateur (liste)
  creer.php            Création d'un déjeuner
  dashboard.php        Tableau de bord (session OU jeton d'admin)
  repondre.php         Réponse participant (jeton) : dispo, proposition, désistement
  cron_relance.php     Relances + rapport d'échéance (cron)
  setlang.php          Changement de langue (FR/EN)
  router.php           Routeur pour `php -S` (protections locales)
  config.example.php   Modèle de configuration
  inc/                 Bibliothèque (db, i18n, ical, gcal, mailer, smtp, model, engine, auth, emails, layout)
  data/                Base SQLite + journaux mail (protégé, non versionné)
```

## Sécurité (résumé)

Jetons non devinables et hashés (magic link, session), magic link à usage unique (15 min) avec
anti-abus non divulgant, session durcie (cookie `HttpOnly`/`Secure`/`SameSite=Lax`, 30 j), CSRF sur
tous les POST, requêtes préparées, échappement HTML systématique, `data/`+`config.php` inaccessibles
en HTTP.
