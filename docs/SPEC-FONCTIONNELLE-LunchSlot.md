# LunchSlot — Spécification fonctionnelle & analyse du concept

> **Statut :** document de conception (v0 — concept & spec, aucun développement).
> **Périmètre de ce document :** cadrer la solution, poser le vocabulaire, la machine à états,
> le modèle de données cible et les critères d'acceptation. **Aucun code n'est produit à ce stade.**

---

## 1. En une phrase

**LunchSlot** organise un déjeuner professionnel à 5–10 personnes en supprimant tout délai entre
« tout le monde est d'accord » et « c'est dans les agendas » : dès qu'un créneau convient à tous,
l'invitation calendrier part automatiquement, et pendant la phase de décision les créneaux
candidats sont **pré-bloqués** dans l'agenda de chaque participant pour éviter qu'une assistante
réserve par-dessus.

`LunchSlot` est le nom produit **et** le nom de l'arborescence cible : **renommage complet** de la
v1 `dejeuner-pro/` vers `lunchslot/` (décision §12.1). Le nom « Déjeuner Pro » disparaît de l'UI,
des emails, des chemins et de la documentation. La seule précaution associée est une **redirection de
compatibilité** pour ne pas casser les liens déjà envoyés (voir §3.7).

---

## 2. Le problème et la proposition de valeur

### Le problème réel
Un déjeuner à 7 côté client + 1 fournisseur se cale par email/Doodle en plusieurs jours. Pendant
ces jours, les agendas bougent (assistantes, réunions qui se posent) : la date « validée » ne tient
déjà plus au moment où l'invitation calendrier est enfin envoyée. Deux causes distinctes :

1. **Le délai accord → invitation.** Entre le dernier « oui » et l'envoi de l'invite, il se passe
   des heures ou des jours (quelqu'un doit s'en occuper).
2. **L'absence de réservation pendant la décision.** Tant que rien n'est confirmé, les créneaux
   candidats restent « libres » aux yeux des agendas → ils se font grignoter.

### Ce que LunchSlot change
| Cause | Réponse LunchSlot |
|---|---|
| Délai accord → invitation | **Confirmation automatique** : l'unanimité déclenche l'envoi de l'invite définitive, sans intervention humaine. |
| Créneaux non réservés pendant la décision | **Placeholders TENTATIVE** : à chaque réponse, chaque créneau « Disponible » est pré-bloqué (.ics `STATUS:TENTATIVE` + lien Google Calendar). |

Le reste (dashboard, relances, désistement) sert ces deux idées : réduire le temps humain et
garder les agendas « chauds » jusqu'à la confirmation.

---

## 3. Analyse critique — ce qui mérite une décision explicite

Cette section n'est pas dans le brief mais conditionne une implémentation robuste. Chaque point
appelle une décision (marquée **[À trancher]** quand ce n'est pas déjà implicite).

### 3.1 Le blocage d'agenda est incitatif, pas coercitif
Un `.ics STATUS:TENTATIVE` en pièce jointe **ne bloque réellement** l'agenda que si le participant
**agit** : ouverture de la pièce jointe (Outlook l'ajoute souvent automatiquement en tentative) ou
clic sur le lien « Ajouter à Google Calendar » (Gmail/Google Agenda ne parsent pas fiablement une
PJ .ics `TENTATIVE` sans action utilisateur). **Conséquence produit :** le message doit être
explicite — « ajoutez ces blocages à votre agenda pour éviter qu'ils soient réservés » — et le lien
Google est le canal fiable, la PJ .ics le complément pour Outlook. On ne promet pas un blocage
automatique universel ; on promet un blocage **en un clic**, systématiquement proposé.

### 3.2 Définition rigoureuse de l'« unanimité »
La confirmation automatique dépend d'une définition sans ambiguïté :

- **Unanime** = pour un créneau donné, **tous les participants actuellement invités** ont répondu
  **Disponible** sur ce créneau.
- Un participant **sans réponse** sur ce créneau ⇒ créneau **pas** unanime (l'absence n'est pas un oui).
- Ajout d'un participant ou d'un créneau ⇒ recalcul ; un déjeuner confirmé n'est **pas** rouvert par
  un simple ajout de créneau (voir machine à états), mais un ajout de **participant** avant
  confirmation entre normalement dans le calcul.
- **Départage** : si plusieurs créneaux deviennent unanimes en même temps, on retient **le plus tôt**
  (date/heure de début la plus proche).

**[À trancher]** Un créneau **proposé par un participant** compte-t-il pour l'unanimité dès qu'il
existe, ou seulement une fois que tous ont eu l'occasion de se positionner ? Recommandation : il
compte comme tout créneau — unanime = tous ont coché Disponible dessus — donc un créneau tout neuf
ne peut être unanime que si littéralement tout le monde l'a validé.

### 3.3 Idempotence & conditions de course
« La dernière réponse rend le créneau unanime → confirmation instantanée » implique que l'évaluation
se fait **à chaque écriture de réponse**. Risques : double-soumission, deux participants qui valident
quasi simultanément, cron qui tourne pendant une réponse. **Exigences :**
- La bascule `en_attente → confirmé` doit être **atomique et idempotente** (un déjeuner ne se
  confirme qu'une fois ; verrou applicatif / transaction SQLite / garde sur le statut courant).
- Chaque email « à effet de bord » (confirmation, provisoires, annulations) doit être **traçable**
  pour ne pas partir deux fois (journal d'envois).
- Les UID .ics étant **déterministes** (couple participant × créneau), un renvoi produit le **même**
  UID avec `SEQUENCE` incrémentée — jamais un doublon calendrier.

### 3.4 Désistement → réouverture → re-confirmation sans boucle
Le désistement d'un participant confirmé doit : annuler l'événement pour **tous** (même UID,
`SEQUENCE+1`), repasser en `en_attente`, réévaluer. Si un **autre** créneau est déjà unanime →
re-confirmation immédiate. **Garde-fou :** la re-confirmation réutilise la même logique atomique ;
on borne pour éviter tout cycle (un créneau annulé pour cause de désistement ne peut pas
re-déclencher immédiatement le même créneau).

### 3.5 Sécurité sur hébergement mutualisé
- `data/` (SQLite) et `config.php` **inaccessibles en HTTP** : `.htaccess` de deny + emplacement
  hors `public_html` si possible, noms non devinables en secours.
- Jetons (participant, admin, magic link) **non devinables** : aléatoire cryptographique, longueur
  suffisante, comparaison à temps constant.
- **Magic link** : usage unique, expiration 15 min, invalidation à la consommation, **jamais** de
  fuite d'information sur l'existence de l'adresse (message identique « si un compte existe, un lien
  a été envoyé »), **rate-limiting** par adresse et par IP.
- Requêtes préparées partout, échappement HTML systématique en sortie.

### 3.6 Exactitude fuseau horaire
Affichage en **Europe/Paris** (configurable), mais **UTC exact** dans les `.ics` (`DTSTART`/`DTEND` en
`...Z` ou `TZID` cohérent) et dans les liens Google Calendar (`dates=YYYYMMDDTHHMMSSZ/...`). Les
conversions doivent tenir compte de l'heure d'été/hiver — un décalage d'une heure sur une invitation
déjeuner est un bug visible.

### 3.7 Compatibilité ascendante (contrainte forte)
- Les **liens à jeton existants** (admin et participant) doivent rester valables.
- Le **schéma SQLite existant** ne doit pas casser : les évolutions passent par des **migrations
  automatiques au démarrage** (création de tables/colonnes si absentes, sans destruction).
- Le rattachement des déjeuners existants à un organisateur se fait **par l'email organisateur**.

**Conséquence du renommage `dejeuner-pro/` → `lunchslot/` (décision §12.1).** Le changement de nom
produit (UI, emails, doc) est purement cosmétique et sans risque. En revanche, **changer le chemin de
déploiement** casserait les liens à jeton **déjà envoyés** qui pointent vers l'ancien dossier
(`.../dejeuner-pro/respond.php?...`, `.../dejeuner-pro/admin.php?...`). Pour respecter la contrainte
« ne pas casser les liens existants » :
- prévoir une **redirection de compatibilité** de l'ancien chemin vers le nouveau (par ex. `.htaccess`
  `RewriteRule ^dejeuner-pro/(.*)$ /lunchslot/$1 [R=301,L]`, ou un dossier-stub `dejeuner-pro/` qui
  redirige), **conservée tant que des liens anciens peuvent circuler** ;
- le renommage de dossier n'a **aucun impact** sur le schéma SQLite ni sur la valeur des jetons : seuls
  les **URLs** changent, pas les données. Les jetons restent identiques et valides.
- Les **nouveaux** emails générés utilisent directement le chemin `lunchslot/`.

---

## 4. Rôles & authentification

### 4.1 Organisateur
- Se connecte par **magic link** (aucun mot de passe).
- Page de connexion : saisie email → email contenant un lien **à usage unique**, **15 min**,
  invalidé dès le premier usage.
- Le clic ouvre une **session durable (~30 jours)**, déconnexion possible.
- Espace **« Mes déjeuners »** : liste de tous ses déjeuners (à venir / passés) avec **statut**
  (en attente de réponses · confirmé · annulé) et accès au tableau de bord de chacun.
- La **création** d'un déjeuner **requiert d'être connecté**.
- Les **liens d'administration à jeton** existants restent valables (compatibilité).
- Anti-abus : fréquence d'envoi de magic links limitée par adresse ; **ne jamais révéler** si une
  adresse est connue.

### 4.2 Participant
- **Aucun compte.** Agit uniquement via un **lien personnel à jeton unique** reçu par email.
- Depuis ce lien : marque Disponible/Indisponible par créneau, modifiable tant que rien n'est
  confirmé, et peut **proposer** de nouveaux créneaux.

---

## 5. Machine à états d'un déjeuner

```
        création (organisateur connecté)
                 │
                 ▼
          ┌─────────────┐   ajout créneau / proposition participant / relance
          │ EN_ATTENTE  │◄───────────────────────────────────────────────┐
          └─────────────┘                                                 │
             │        │                                                   │
   unanimité │        │ annulation organisateur                          │ désistement
   (auto ou  │        ▼                                                   │ (réouverture)
   manuelle) │   ┌──────────┐                                             │
             ▼   │ ANNULÉ   │ (CANCEL calendrier à tous)                  │
        ┌──────────┐└──────────┘                                          │
        │ CONFIRMÉ │──────────────────────────────────────────────────────┘
        └──────────┘   (un participant se désiste → CANCEL + retour EN_ATTENTE,
             │          puis re-confirmation immédiate si un autre créneau est unanime)
             ▼
        (déjeuner passé → « passé » dans « Mes déjeuners »)
```

- **EN_ATTENTE** : collecte des réponses, placeholders actifs, relances possibles.
- **CONFIRMÉ** : un créneau retenu, invitation `REQUEST` envoyée, placeholders des autres créneaux
  annulés (`CANCEL`).
- **ANNULÉ** : annulation calendrier envoyée à tous.
- **Réouverture** : désistement post-confirmation ⇒ retour EN_ATTENTE + réévaluation immédiate.

---

## 6. Parcours fonctionnels (détaillés)

### 6.1 Création d'un déjeuner *(organisateur connecté)*
Saisie : **titre**, **restaurant/lieu** (optionnel), **participants** (nom + email),
**créneaux proposés** (date, heure, durée), **date limite de réponse**.
À la création :
- email d'**invitation individuel** à chaque participant avec son **lien personnel** ;
- email de **confirmation « déjeuner créé »** à l'organisateur.

### 6.2 Réponse participant *(sans compte)*
- Disponible / Indisponible **par créneau**, en quelques clics.
- Réponses **modifiables** tant que rien n'est confirmé.
- **Force de proposition** : propose un nouveau créneau → les autres participants **et**
  l'organisateur sont **notifiés par email** et invités à se positionner sur cette nouvelle date.

### 6.3 Blocage d'agenda (placeholders) — à chaque validation de réponses
Pour **chaque créneau déclaré Disponible**, le participant reçoit immédiatement un email contenant :
- un **événement provisoire** en PJ `.ics` avec **`STATUS:TENTATIVE`**, **+**
- un **lien « Ajouter à Google Calendar »** (`calendar.google.com/render?action=TEMPLATE`).
- **UID stables et déterministes** par couple (participant, créneau) → annulables plus tard.
- S'il **retire** un créneau → il reçoit l'**annulation** correspondante (`.ics METHOD:CANCEL`) +
  consigne de suppression manuelle en secours.

### 6.4 Confirmation automatique *(fonctionnalité clé)*
Dès qu'un créneau convient à **TOUS** :
- bascule **immédiate** en **« Confirmé »** sans intervention humaine ;
- envoi à **tous + organisateur** de l'invitation définitive : **`.ics METHOD:REQUEST` + lien Google** ;
- **nettoyage des placeholders** : `.ics METHOD:CANCEL` reprenant les **UID des provisoires** pour
  suppression automatique quand le client mail le supporte, **+ fallback** liste claire des blocages
  à supprimer manuellement.
- Si plusieurs créneaux unanimes → **le plus tôt**.

### 6.5 Tableau de bord organisateur
- Statut du déjeuner.
- **Qui a répondu** : complet / partiel / en attente + date de dernière relance.
- **Matrice créneaux × participants** (✓ / ✗ / —) avec **bilan par créneau** :
  confirmé, possible x/n, impossible.
- **Lien de réponse copiable** et **renvoi d'email** par participant.
- **Relance manuelle** des retardataires.
- **Ajout de créneaux** (participants notifiés).
- **Confirmation manuelle** d'un créneau sans refus.
- **Annulation** du déjeuner (annulation calendrier à tous).

### 6.6 Relances automatiques *(cron)*
Script exécutable en **tâche cron hPanel** :
- relance les participants **sans réponse complète** tant que la **deadline** n'est pas passée
  (**max 1 relance / ~20 h / personne**) ;
- à **deadline dépassée** : **rapport unique** à l'organisateur (qui manque, actions possibles),
  envoyé **une seule fois**.

### 6.7 Désistement après confirmation
- Le participant peut se désister : **annulation calendrier à tous** (**même UID, `SEQUENCE+1`**),
  **réouverture** sur les créneaux restants, chacun invité à revérifier ses disponibilités ;
- si un **autre créneau** est déjà unanime → **re-confirmation immédiate**.

---

## 7. Notifications email (matrice)

| # | Événement déclencheur | Destinataire(s) | PJ .ics | Lien Google |
|---|---|---|---|---|
| 1 | Création — invitation initiale | chaque participant | — | — |
| 2 | Magic link de connexion | organisateur | — | — |
| 3 | « Déjeuner créé » | organisateur | — | — |
| 4 | Validation de réponses — récap provisoires | participant concerné | `TENTATIVE` × créneaux dispo | ✔ par créneau |
| 4b | Retrait d'un créneau | participant concerné | `CANCEL` du provisoire | — |
| 5 | Nouveau créneau proposé | autres participants + organisateur | — | — |
| 6 | Confirmation définitive | tous + organisateur | `REQUEST` + `CANCEL` des provisoires | ✔ |
| 7 | Relance | participant en retard | — | — |
| 8 | Désistement / réouverture | tous + organisateur | `CANCEL` (SEQUENCE+1) | — |
| 9 | Annulation du déjeuner | tous | `CANCEL` | — |
| 10 | Rapport d'échéance | organisateur | — | — |

**Règles transverses :** toutes en **français**, **HTML sobre + version texte**,
**`Reply-To` = organisateur** pour les emails aux participants, **PJ `.ics`** dès qu'un événement est
en jeu, **lien Google Calendar systématique** à côté de chaque `.ics`.

---

## 8. Modèle de données — évolutions cibles (magic link + « Mes déjeuners »)

> Objectif : ajouter l'authentification organisateur **sans casser** le schéma v1 (migrations
> additives au démarrage). Les tables ci-dessous décrivent les **ajouts** ; les tables v1
> (déjeuners, participants, créneaux, réponses, envois .ics…) sont conservées telles quelles, avec
> au besoin l'ajout d'une colonne d'email organisateur pour le rattachement.

- **`organizers`** — un organisateur par email.
  `id`, `email` (unique, normalisé en minuscules), `created_at`, `last_login_at`.
- **`magic_links`** — jetons de connexion à usage unique.
  `id`, `organizer_email`, `token_hash` (on stocke un **hash**, pas le jeton en clair), `expires_at`
  (création + 15 min), `used_at` (null tant qu'inutilisé), `created_ip`, `created_at`.
- **`sessions`** — sessions durables ~30 jours.
  `id`, `organizer_id`, `token_hash`, `expires_at`, `created_at`, `last_seen_at`, révocable
  (déconnexion).
- **`magic_link_throttle`** *(ou compteur applicatif)* — rate-limiting par email et par IP.
- **Rattachement** : chaque déjeuner est relié à un organisateur par **email organisateur**
  (colonne existante ou ajoutée) → « Mes déjeuners » = déjeuners dont l'email organisateur
  correspond à l'organisateur en session. Les **liens admin à jeton** restent une voie d'accès
  parallèle et valable.

**Principes de sécurité stockage :** jetons stockés **hashés**, comparaison à temps constant,
purge des magic links expirés/consommés au fil de l'eau.

---

## 9. Contraintes techniques impératives (rappel de cadrage)

- **Hébergement mutualisé Hostinger** : **PHP 8 + SQLite**, **aucun build**, **aucun composer** sur
  le serveur, **déployable par simple upload** (File Manager hPanel), **cron via hPanel**.
- **Emails** : `mail()` par défaut, **SMTP configurable** dans un fichier de config simple.
- **UI 100 % française**, responsive, sobre et professionnelle.
- **Fuseau Europe/Paris** (configurable) ; **conversions UTC exactes** dans les `.ics` et les liens
  Google.
- **Sécurité** : jetons non devinables ; magic links à usage unique et expirants ; `data/` et
  `config.php` **inaccessibles en HTTP** ; requêtes préparées ; échappement HTML systématique.

---

## 10. Périmètre de l'évolution vs existant

**Déjà couvert par la v1 (`dejeuner-pro/`, renommée `lunchslot/`)** — à ne pas casser :
pages index/admin/respond · `cron_relance.php` · génération `.ics` RFC 5545 validée
(REQUEST/CANCEL, séquences, placeholders `TENTATIVE` à UID déterministes) · liens Google Calendar ·
mailer `mail()`/SMTP/log · propositions de créneaux par les participants ·
désistement/réouverture/re-confirmation.

**À ajouter (objet de cette évolution) :**
1. **Authentification organisateur par magic link** (login, jeton usage unique 15 min, session 30 j,
   déconnexion, anti-abus, non-divulgation).
2. **Espace « Mes déjeuners »** (liste à venir/passés, statut, accès dashboard, rattachement par
   email organisateur).
3. **Garde d'accès** : création de déjeuner conditionnée à une session valide, **tout en gardant**
   les liens admin à jeton fonctionnels.
4. **Migrations automatiques** au démarrage pour les nouvelles tables.

---

## 11. Critères d'acceptation (tests locaux — serveur PHP intégré)

1. **Magic link** : connexion organisateur OK ; lien **expiré** ou **déjà utilisé** → **refus
   propre** ; espace « Mes déjeuners » listant bien ses déjeuners.
2. **Création → réponse** : chaque participant reçoit son lien ; une réponse déclenche l'email de
   **provisoires `TENTATIVE`** + **un lien Google par créneau** choisi.
3. **Proposition** : un participant propose un créneau → les autres sont **notifiés** et peuvent se
   positionner.
4. **Confirmation instantanée** : la dernière réponse rendant un créneau unanime déclenche
   l'invitation **`REQUEST`** à tous **+ `CANCEL`** des provisoires **reprenant les bons UID**.
5. **Désistement** : `CANCEL` (**`SEQUENCE+1`**) + **réouverture**, puis **re-confirmation
   automatique** si un autre créneau est déjà unanime.
6. **Cron** : relances avec **intervalle anti-spam** respecté ; **rapport d'échéance** envoyé **une
   seule fois**.

---

## 12. Points ouverts à valider avec le porteur

1. **Nom & arborescence** — ✅ **TRANCHÉ : renommage complet en LunchSlot.** Produit et dossier
   deviennent `lunchslot/` ; « Déjeuner Pro » disparaît de l'UI, des emails et de la doc. Précaution
   retenue : redirection de compatibilité `dejeuner-pro/ → lunchslot/` pour préserver les liens à
   jeton déjà envoyés (détail §3.7).
2. **Créneau proposé & unanimité** : un créneau tout neuf peut-il déclencher une confirmation dès que
   tous l'ont validé, sans délai de courtoisie ? (Recommandation : oui, règle unique et prévisible.)
3. **Placeholders** : assume-t-on explicitement que le blocage est « en un clic » (incitatif) et non
   « automatique garanti » côté Gmail/Google ? (Impact sur le texte des emails.)
4. **Portée du magic link** : un organisateur = un email ; que fait-on si un déjeuner a été créé avec
   un email organisateur légèrement différent (casse, alias) → normalisation ?
5. **Emplacement `data/` / `config.php`** sur l'hébergement Hostinger visé : hors `public_html`
   possible, ou protection uniquement par `.htaccess` ?
```
