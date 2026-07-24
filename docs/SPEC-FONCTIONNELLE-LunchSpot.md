# LunchSpot — Spécification fonctionnelle & analyse du concept

> **Statut :** document de conception (v1 — concept & spec, **décisions actées §12**, aucun développement).
> **Périmètre de ce document :** cadrer la solution, poser le vocabulaire, la machine à états,
> le modèle de données cible et les critères d'acceptation. **Aucun code n'est produit à ce stade.**

---

## 1. En une phrase

**LunchSpot** organise un déjeuner professionnel à 5–10 personnes en supprimant tout délai entre
« tout le monde est d'accord » et « c'est dans les agendas » : dès qu'un créneau convient à tous,
l'invitation calendrier part automatiquement, et pendant la phase de décision les créneaux
candidats sont **pré-bloqués** dans l'agenda de chaque participant pour éviter qu'une assistante
réserve par-dessus.

`LunchSpot` est le nom produit **et** le nom de l'arborescence : toute l'application vit dans
`lunchspot/`. Le projet est développé **from scratch** (aucune v1 héritée) ; il n'existe aucun autre
nom de code ni lien historique à préserver.

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

### Ce que LunchSpot change
| Cause | Réponse LunchSpot |
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
- **L'organisateur n'est PAS votant par défaut** (décision §12.7) : il reçoit les invitations mais ne
  compte pas dans « tous ». Une case **« je participe aussi »** à la création l'ajoute comme
  participant à part entière (nom + email), et il vote alors comme les autres.
- Le **proposeur d'un nouveau créneau** est **auto-marqué Disponible** sur le créneau qu'il propose.

**Créneau fraîchement proposé (décision §12.3) — TRANCHÉ : règle unique, aucun délai.** Un créneau
proposé compte comme tout autre créneau : unanime = tous ont coché Disponible dessus. Pas de fenêtre
de courtoisie — c'est le cœur du produit (zéro délai). Garde-fou naturel : un créneau tout neuf ne
peut devenir unanime que si littéralement **tout le monde** l'a validé, donc jamais de « confirmation
surprise » sur le dos d'un participant qui n'a pas répondu.

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
Valeurs **tranchées** (décisions §12.4 à §12.6) :

- **Protection `data/` + `config.php` — défense en profondeur (décision §12.5) :**
  `.htaccess` `Require all denied` sur `data/` + refus des fichiers sensibles, **et** base SQLite à
  **nom non devinable**, **et** emplacement **hors `public_html`** si l'arborescence Hostinger le
  permet (bonus). On ne dépend jamais d'un seul mécanisme.
- **Jetons** (participant, admin, magic link, session) **non devinables** : aléatoire cryptographique
  (≥ 128 bits), comparaison à temps constant. Les jetons **magic link et session** sont stockés
  **hashés** en base (jamais en clair).
- **Magic link (décision §12.6) :** usage unique, **expiration 15 min**, invalidation à la
  consommation. **Rate-limiting : max 3 envois / email / 15 min** et **~10 / IP / heure**. Réponse
  **toujours identique** — « si un compte existe, un email de connexion a été envoyé » — quel que soit
  le résultat, pour **ne jamais révéler** si l'adresse est connue.
- **Session organisateur (décision §12.6) :** cookie **`HttpOnly` + `Secure` + `SameSite=Lax`**
  (Lax laisse fonctionner l'arrivée depuis un lien email), jeton **stocké hashé**, **expiration
  absolue 30 j**, **déconnexion = suppression de la ligne** (révocation immédiate).
- **Normalisation email organisateur (décision §12.4) : minimale et sûre** → `trim` + minuscules
  **uniquement**. On ne déduplique **pas** les points Gmail ni les alias `+tag` (spécifique fournisseur
  et risque de faire correspondre deux personnes distinctes → usurpation).
- **CSRF :** jeton anti-CSRF sur **tous les POST** (réponse participant, création, actions dashboard,
  demande de magic link).
- Requêtes préparées partout, échappement HTML systématique en sortie.
- **Purge** au fil de l'eau des magic links consommés/expirés et des sessions expirées.

### 3.6 Exactitude fuseau horaire
Affichage en **Europe/Paris** (configurable), mais **UTC exact** dans les `.ics` (`DTSTART`/`DTEND` en
`...Z` ou `TZID` cohérent) et dans les liens Google Calendar (`dates=YYYYMMDDTHHMMSSZ/...`). Les
conversions doivent tenir compte de l'heure d'été/hiver — un décalage d'une heure sur une invitation
déjeuner est un bug visible.

### 3.7 Robustesse du schéma & accès par jeton
- **Accès par jeton** : chaque participant a un lien personnel à jeton unique ; le tableau de bord est
  accessible soit via **session organisateur**, soit via un **jeton d'administration** du déjeuner
  (lien partageable). Les jetons sont non devinables et stockés en base.
- **Migrations automatiques au démarrage** : le schéma évolue par ajout de tables/colonnes si absentes,
  **sans destruction** — un déploiement par simple upload doit pouvoir mettre à jour la base tout seul.
- Le rattachement d'un déjeuner à un organisateur se fait **par l'email organisateur** (normalisé,
  §3.5) ; « Mes déjeuners » liste les déjeuners de l'organisateur en session.

---

## 4. Rôles & authentification

### 4.1 Organisateur
- Se connecte par **magic link** (aucun mot de passe).
- Page de connexion : saisie email → email contenant un lien **à usage unique**, **15 min**,
  invalidé dès le premier usage.
- Le clic ouvre une **session durable (~30 jours)**, déconnexion possible.
- Espace **« Mes déjeuners »** : liste de tous ses déjeuners (à venir / passés) avec **statut**
  (en attente de réponses · confirmé · annulé) et accès au tableau de bord de chacun.
- La **création** d'un déjeuner **requiert d'être connecté** ; une case **« je participe aussi »**
  permet à l'organisateur de se compter comme participant votant (voir §3.2).
- Les **liens d'administration à jeton** existants restent valables (compatibilité).
- Anti-abus : **max 3 magic links / email / 15 min** et **~10 / IP / heure** ; **ne jamais révéler**
  si une adresse est connue (réponse identique dans tous les cas). Détails §3.5.

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

**Règles transverses :** **bilingues FR/EN** (langue du déjeuner), **HTML sobre + version texte**,
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
- **UI bilingue FR/EN**, responsive, sobre et professionnelle. Langue **détectée automatiquement**
  d'après les préférences du navigateur (`Accept-Language`), avec surcharge manuelle (sélecteur +
  cookie). Les emails d'un déjeuner partent dans la langue choisie à sa création ; le magic link suit
  la langue du navigateur du demandeur.
- **Fuseau Europe/Paris** (configurable) ; **conversions UTC exactes** dans les `.ics` et les liens
  Google.
- **Sécurité** : jetons non devinables ; magic links à usage unique et expirants ; `data/` et
  `config.php` **inaccessibles en HTTP** ; requêtes préparées ; échappement HTML systématique.

---

## 10. Périmètre de développement (from scratch)

Tout est à construire dans `lunchspot/` :

1. **Cœur métier** : création de déjeuner, réponses participants, moteur d'**unanimité** et
   **confirmation automatique**, propositions de créneaux, **désistement/réouverture/re-confirmation**.
2. **Génération `.ics`** RFC 5545 (`REQUEST`/`CANCEL`/placeholders `TENTATIVE`), **UID déterministes**
   par (participant, créneau), **SEQUENCE** incrémentée, et **liens Google Calendar** en parallèle.
3. **Mailer** `mail()` par défaut, **SMTP configurable**, mode **log** pour les tests ; HTML + texte,
   `Reply-To` = organisateur.
4. **Authentification organisateur par magic link** (jeton usage unique 15 min, session 30 j,
   déconnexion, anti-abus, non-divulgation) + espace **« Mes déjeuners »**.
5. **Tableau de bord organisateur** (matrice, bilans, actions) accessible par session **ou** jeton
   d'administration.
6. **Cron** de relances + rapport d'échéance.
7. **Migrations automatiques** au démarrage ; **sécurité** (protection `data/`/`config.php`, CSRF,
   requêtes préparées, échappement, jetons hashés).

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

## 12. Décisions actées

Tous les points ouverts ont été **tranchés** (validés par le porteur). Ils font désormais foi pour
le développement.

1. **Nom & arborescence** — ✅ **LunchSpot uniquement, développé from scratch.** Toute l'application
   vit dans `lunchspot/` ; aucun autre nom de code, aucune v1 héritée, aucun lien historique à
   préserver.
2. **Discours placeholders** — ✅ **« Blocage en un clic » (incitatif), pas « automatique ».** Les
   emails invitent explicitement à ajouter les créneaux à l'agenda ; lien Google mis en avant (canal
   fiable), PJ `.ics` en complément pour Outlook. On ne promet aucun blocage automatique universel
   (§3.1).
3. **Créneau proposé & unanimité** — ✅ **Règle unique, aucun délai.** Unanime = tous ont coché
   Disponible ; un créneau neuf est confirmable dès que littéralement tout le monde l'a validé (§3.2).
4. **Normalisation email organisateur** — ✅ **Minimale et sûre : `trim` + minuscules uniquement.**
   Pas de déduplication des points Gmail ni des alias `+tag` (risque d'usurpation) (§3.5).
5. **Protection `data/` / `config.php`** — ✅ **Défense en profondeur :** `.htaccess` `Require all
   denied` + nom de base non devinable + hors `public_html` si possible (§3.5).
6. **Anti-abus magic link & session** — ✅ Magic link : usage unique, **15 min**, **3 / email / 15 min**,
   **~10 / IP / h**, réponse non divulgante, jeton **hashé**. Session : cookie
   **HttpOnly+Secure+SameSite=Lax**, **30 j** absolus, jeton **hashé**, déconnexion = révocation (§3.5).
7. **Organisateur votant ?** — ✅ **Non par défaut** (destinataire seulement) ; case **« je participe
   aussi »** pour le compter comme participant votant (§3.2, §4.1).

### 12.1 Défauts retenus sur les zones laissées implicites par le brief

| Sujet | Décision |
|---|---|
| **CSRF** | Jeton anti-CSRF sur tous les POST (§3.5). |
| **Ajout d'un participant après confirmation** | Interdit tant que « confirmé » ; possible seulement en « en attente ». |
| **Deadline atteinte sans aucune unanimité** | Rapport unique à l'organisateur (§6.6) ; déjeuner reste « en attente » jusqu'à action manuelle. |
| **Proposeur d'un créneau** | Auto-marqué « Disponible » sur le créneau proposé (§3.2). |
| **Rétention / RGPD** | Purge des magic links consommés/expirés et sessions expirées ; aucune donnée sensible au-delà de nom + email. |
| **Idempotence confirmation** | Transaction SQLite + garde sur le statut → jamais deux confirmations ni emails en double (§3.3). |
```
