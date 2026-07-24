# LunchSpot

Application web de planification de déjeuners professionnels multi-participants.
**PHP 8 + SQLite**, sans build ni composer, interface **bilingue FR/EN**, mobile-first.

**Objectif :** supprimer tout délai entre l'accord sur une date et l'envoi de l'invitation
calendrier, et pré-bloquer les agendas des participants pendant la phase de décision.

- [Spécification fonctionnelle & analyse du concept](docs/SPEC-FONCTIONNELLE-LunchSpot.md)

## Idée cœur

- **Confirmation automatique** : dès qu'un créneau convient à *tous*, l'invitation calendrier
  (`.ics REQUEST` + lien Google) part sans intervention humaine.
- **Placeholders** : à chaque réponse, chaque créneau « Disponible » est pré-bloqué
  (`.ics TENTATIVE` + lien Google), UID déterministes pour pouvoir les annuler ensuite.

## Arborescence

L'application est **à la racine du dépôt** (déployable tel quel dans un `public_html`).

```
index.php login.php verify.php logout.php   Authentification (magic link)
mes-dejeuners.php  creer.php                Espace organisateur
dashboard.php  repondre.php                 Tableau de bord / réponse participant
cron_relance.php                            Relances + rapport d'échéance (cron)
setlang.php  router.php                     Langue / routeur serveur intégré
config.example.php                          Modèle de configuration
inc/                                        Bibliothèque (db, i18n, ical, gcal, mailer, …)
data/                                       Base SQLite + journaux mail (protégé, non versionné)
tests/run.php                               Suite de tests
docs/                                       Spécification
```

## Lancer en local

```bash
cp config.example.php config.php
php -S localhost:8000 router.php
```
Ouvre http://localhost:8000. En transport email `log` (défaut), les emails sont écrits dans
`data/maillog/*.eml`. `app_url` dans `config.php` doit correspondre à l'URL réelle de service.

## Tests

```bash
php tests/run.php
```
Couvre les 6 critères d'acceptation + cas limites (base et emails isolés dans un dossier temporaire).

## Déploiement Hostinger via Git

L'appli étant à la racine, on la déploie directement dans le `public_html` du domaine.

1. **hPanel → Avancé → Git** : Repository `https://github.com/kponseel/LunchSlot.git`,
   Branch `main`, Directory `public_html` (le dossier racine du domaine).
2. **Créer `config.php`** dans `public_html/` (copié de `config.example.php`), avec `app_url`,
   `timezone` et les réglages email (SMTP conseillé). `config.php` n'est pas dans Git et **survit
   aux `git pull`**.
3. **Auto-déploiement** : ajouter le webhook Hostinger dans GitHub
   (`Settings → Webhooks`, event *push*). Chaque push sur `main` met le site à jour.
4. **Cron** (hPanel → Cron) : `php /…/public_html/cron_relance.php`, toutes les heures.

Sécurité déjà en place : `data/`, `config.php`, `.git`, `tests/`, `docs/` sont inaccessibles en
HTTP (`.htaccess`) ; jetons hashés, magic link à usage unique, session durcie, CSRF, requêtes
préparées, échappement systématique, migrations SQLite automatiques.
