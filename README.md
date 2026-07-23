# LunchSlot

Application web de planification de déjeuners professionnels multi-participants.

**Objectif :** supprimer tout délai entre l'accord sur une date et l'envoi de l'invitation
calendrier, et bloquer les agendas des participants pendant la phase de décision.

## Documentation

- [Spécification fonctionnelle & analyse du concept](docs/SPEC-FONCTIONNELLE-LunchSlot.md)

## Stack cible

PHP 8 + SQLite, sans build ni composer serveur, déployable par simple upload
(hébergement mutualisé Hostinger), cron via hPanel. Emails `mail()` / SMTP configurable.
