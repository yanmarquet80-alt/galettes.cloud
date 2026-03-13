# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Crêpes et Galettes de Gérard** — a food-truck POS (point-of-sale) web application.
Live URL: `https://galettes.cloud`
Hosting: Hostinger (shared hosting, PHP + MySQL)
GitHub repo: `https://github.com/yanmarquet80-alt/galettes.cloud` (branch `main`)

---

## Architecture

The app is a **3-file stack** with no build step:

| File | Role |
|---|---|
| `index.html` | Single-file SPA (~1700+ lines): all CSS, HTML, and JS in one file |
| `api.php` | PHP REST API — handles all DB operations |
| `config.php` | DB credentials (constants) — **NOT in git**, created manually on server |

---

## Deployment Workflow

### Auto-deploy via GitHub webhook ✅

Changes pushed to `main` are **automatically deployed** to Hostinger via webhook :

```
git push origin main  →  GitHub webhook  →  Hostinger pulls the repo
```

- Webhook URL (Hostinger → GitHub): `https://webhooks.hostinger.com/deploy/741949229a5f47d780bd39a30d82a83b`
- Configured in: GitHub → repo Settings → Webhooks
- Trigger: `push` event, `application/json`

### config.php — fichier hors git

`config.php` est exclu du repo (`.gitignore`). Il doit exister manuellement sur le serveur.
Si jamais il est perdu, le recréer via le File Browser Hostinger :

```
https://srv2083-files.hstgr.io/7fae91d45ab80653/files/public_html/config.php
```

Contenu :
```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'u927480631_gerard');
define('DB_PASS', 'Galettes2026Secure#');
define('DB_NAME', 'u927480631_galettes');
```

### Modifier un fichier manuellement (urgence)

Si besoin d'éditer directement sans passer par git :
1. Aller sur `https://srv2083-files.hstgr.io/7fae91d45ab80653/files/public_html/`
2. Cliquer sur le fichier → s'ouvre dans l'éditeur Ace
3. Pour modifier via la console JS :
   ```js
   const editor = document.querySelector('.ace_editor').env.editor;
   editor.setValue(newContent, -1);  // -1 = curseur au début
   // Puis Ctrl+S pour sauvegarder
   ```
4. **Tip** : pour les gros fichiers, préférer `getValue()` + remplacement de section + `setValue()` plutôt que de remplacer tout le fichier.

### Dépannage : erreur "Connexion base de données impossible"

Si l'API renvoie `{"ok":false,"error":"Connexion base de données impossible."}`, c'est une erreur MySQL.
Pour diagnostiquer, ajouter temporairement `'detail' => $e->getMessage()` dans le catch PDO, puis vérifier le message.

**Cause fréquente** : le mot de passe MySQL a été changé ou ne correspond pas à `config.php`.
**Fix** : hPanel → Sites web → galettes.cloud → Bases de données → Gestion → ⋮ → **Changer le mot de passe** → saisir `Galettes2026Secure#`.

---

## Frontend (`index.html`)

Dark-theme SPA avec **5 onglets** gérés par `switchTab(tabName)` :

- **`caisse`** — Caisse : grille d'articles (gauche) + ticket/paiement (droite)
- **`attente`** — File de commandes actives avec contrôles de statut
- **`session`** — Stats de la session courante, top articles, répartition paiements, historique
- **`carte`** — Gestion des articles personnalisés (CRUD via API)
- **`archives`** — Dashboard historique avec graphiques Chart.js (barre + donut)

### Variables globales JS

```js
let cart = [];             // articles du ticket en cours
let orders = [];           // commandes de la session (poll toutes les 8s)
let customItems = [];      // articles personnalisés depuis DB
let nextNum;               // prochain numéro de commande
let payMode = 'cb';        // mode de paiement sélectionné
let archPeriod = 'month';  // filtre période archives
let chartCA, chartPay;     // instances Chart.js

// Session management (ajouté en mars 2026)
let currentSession  = null;   // {id, name, lieu, date} — session active
let consultSession  = null;   // {session, orders[]} — session consultée (lecture seule)
let consultMode     = false;  // true = mode consultation (pas d'encaissement)
let sessionsList    = [];     // liste des sessions chargées au démarrage
```

### Démarrage de l'app

L'app démarre via `initApp()` (et non plus directement `startPolling()`).

```
initApp()
  └─ GET /api.php?action=get_sessions
       ├─ Session active existante → modal avec "Continuer" + liste sessions clôturées
       └─ Aucune session → modal avec formulaire "Nouvelle session"
```

Le modal de lancement (`#session-launch`) s'affiche à chaque chargement.

Fonctions clés du flux session :
| Fonction | Rôle |
|---|---|
| `initApp()` | Point d'entrée. Charge les sessions et affiche le modal |
| `showLaunchModal(sessions)` | Construit et affiche `#session-launch` |
| `continueSession(session)` | Ferme le modal, `currentSession = session`, lance le polling |
| `submitNewSession()` | Valide le formulaire, appelle `create_session`, puis `continueSession()` |
| `enterConsultMode(id)` | Appelle `get_session_orders`, passe en lecture seule |
| `exitConsultMode()` | Repasse en mode normal, réaffiche le modal |

---

## Backend (`api.php`)

REST API monofichier. Routes via `?action=` ou `body.action`. GET supporté pour `get_orders`.

### Actions disponibles

| Action | Description |
|---|---|
| `get_sessions` | Toutes les sessions avec agrégats (CA, nb commandes) |
| `create_session` | Crée une session `{name, lieu, date}`, désactive l'ancienne active |
| `get_session_orders` | Commandes d'une session spécifique `{session_id}` |
| `get_orders` | Commandes actives (`archived=0`) + numéro suivant + `current_session` |
| `create_order` | Insère une commande, rattachée à la session active |
| `update_status` | Change le statut : `attente` → `pret` → `servi` / `annule` |
| `clear_session` | Archive les commandes (`archived=1`) + clôture la session active |
| `get_archives` | Commandes archivées filtrées par `period` : `week`/`month`/`year` |
| `get_custom_items` | Liste les articles personnalisés |
| `create_item` | Crée un article (cats: `salee`, `sucree`, `boisson`) |
| `delete_item` | Supprime un article |
| `send_export_email` | Envoie un email HTML récapitulatif de session |

---

## Base de données

Tables auto-créées/migrées à chaque appel API.

### Schéma complet

```sql
orders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME      NOT NULL DEFAULT NOW(),
  client      VARCHAR(255)  DEFAULT NULL,
  items       JSON          NOT NULL,
  total       DECIMAL(10,2) NOT NULL,
  pay         VARCHAR(50)   NOT NULL,
  status      VARCHAR(50)   NOT NULL DEFAULT 'attente',  -- attente|pret|servi|annule
  notes       TEXT          DEFAULT NULL,
  archived    TINYINT(1)    NOT NULL DEFAULT 0,           -- 0=actif, 1=archivé
  session_id  INT UNSIGNED  DEFAULT NULL,
  updated_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)

custom_items (id, cat, name, descr, price)

sessions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(255) NOT NULL DEFAULT '',
  lieu       VARCHAR(255) NOT NULL DEFAULT '',
  date       DATE         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT NOW(),
  closed_at  DATETIME     DEFAULT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 0   -- 1 seule session active à la fois
)
```

### Infos MySQL

- Base : `u927480631_galettes`
- Utilisateur : `u927480631_gerard`
- Hôte : `localhost`
- Gestion via : hPanel → Sites web → galettes.cloud → Bases de données → Gestion

---

## Patterns clés

- **Date parsing** : Le serveur renvoie des `DATETIME` MySQL (`YYYY-MM-DD HH:MM:SS`). Utiliser `parseDBDate()` pour convertir en objet JS Date (gère le parsing strict de Safari).
- **Formatage monétaire** : Utiliser `fmt(amount)` → retourne `"12,50 €"`.
- **Échappement XSS** : Utiliser `esc(str)` pour tout contenu utilisateur rendu en HTML.
- **Chart.js** : Version 4.4.4 depuis CDN. Toujours appeler `.destroy()` avant de recréer un chart pour éviter les erreurs de réutilisation de canvas.
- **Backticks dans les injections JS** : Quand on injecte du JS contenant des template literals dans l'éditeur Ace via la console, utiliser `const BT = '\x60'` et la concaténation pour éviter les problèmes d'échappement.
- **Polling** : `poll()` ignore les appels si `consultMode === true`.
- **Mode consultation** : En mode consult, le bouton ENCAISSER est désactivé, le polling est pausé, et les données affichées viennent de `consultSession.orders`.
