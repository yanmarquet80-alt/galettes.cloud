# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Crêpes et Galettes de Gérard** — a food-truck POS (point-of-sale) web application.
Live URL: `https://galettes.cloud`
Hosting: Hostinger (shared hosting, PHP + MySQL)

## Architecture

The app is a **3-file stack** with no build step:

| File | Role |
|---|---|
| `index.html` | Single-file SPA (~1700 lines): all CSS, HTML, and JS in one file |
| `api.php` | PHP REST API — handles all DB operations |
| `config.php` | DB credentials (constants) |

### Frontend (`index.html`)

Dark-theme SPA with **5 tabs** managed by `switchTab(tabName)`:

- **`caisse`** — POS screen: article grid (left) + ticket/payment panel (right)
- **`attente`** — Active orders queue with status controls
- **`session`** — Current session stats, top items, payment breakdown, order history
- **`carte`** — Custom article manager (CRUD via API)
- **`archives`** — Historical dashboard with Chart.js charts (bar + doughnut)

Key global state variables:
```js
let cart = [];          // current ticket items
let orders = [];        // session orders (polled every 8s)
let customItems = [];   // custom articles from DB
let nextNum;            // next order number
let payMode = 'cb';     // selected payment method
let archPeriod = 'month'; // archives period filter
let chartCA, chartPay;  // Chart.js instances
```

The app polls `get_orders` every 8 seconds (`setInterval(poll, 8000)`). Orders arrive on multiple devices simultaneously (food truck + kitchen screen use case).

**Article catalog** is split between hardcoded items in the JS (`MENU` array) and DB-stored custom items (`custom_items` table), merged in `buildGrid()`.

### Backend (`api.php`)

Single-file REST API. Routes are driven by `?action=` query param or `body.action`. All requests are POST except `get_orders` (GET supported).

Available actions:

| Action | Description |
|---|---|
| `get_orders` | Returns active orders (`archived = 0`) + next order number |
| `create_order` | Inserts a new order |
| `update_status` | Changes order status: `attente` → `pret` → `servi` / `annule` |
| `clear_session` | Archives current session (sets `archived = 1` — does NOT delete data) |
| `get_archives` | Returns archived orders filtered by `period`: `week` (7d), `month` (30d), `year` (365d) |
| `get_custom_items` | Lists custom articles |
| `create_item` | Creates a custom article (cats: `salee`, `sucree`, `boisson`) |
| `delete_item` | Deletes a custom article |

### Database Schema

Tables are auto-created/migrated on each API call:

```sql
orders (
  id, created_at, client, items JSON, total, pay,
  status VARCHAR(50),  -- attente | pret | servi | annule
  notes TEXT,
  archived TINYINT(1), -- 0 = current session, 1 = historical
  updated_at
)

custom_items (id, cat, name, descr, price)
```

## Deploying Changes to Hostinger

There is **no CI/CD pipeline**. Files must be uploaded manually via the Hostinger file manager:

1. Navigate to `https://hpanel.hostinger.com` → File Manager → `public_html/`
2. Right-click the target file → **Modifier** (opens Ace editor in browser)
3. To replace file content programmatically, use the Ace editor JS API in the browser console:
   ```js
   const editor = document.querySelector('.ace_editor').env.editor;
   editor.setValue(newContent, -1);  // -1 = move cursor to start
   ```
4. Click **Enregistrer** to save.

> **Tip for large files**: Inject only the changed sections using `getValue()` + string replacement + `setValue()` rather than passing the full file content. This avoids context window limits.

## Key Patterns

- **Date parsing**: Server returns MySQL `DATETIME` strings (`YYYY-MM-DD HH:MM:SS`). Use `parseDBDate()` helper to convert to JS Date objects (handles Safari's strict ISO parsing).
- **Currency formatting**: Use the `fmt(amount)` helper → returns `"12,50 €"` locale string.
- **XSS escaping**: Use `esc(str)` for all user-supplied content rendered as HTML.
- **Chart.js**: Version 4.4.4 loaded from CDN. Charts stored in `chartCA` / `chartPay` globals; always call `.destroy()` before recreating to avoid canvas reuse errors.
- **Template literals with backticks in JS injections**: When injecting JS code that itself contains backtick template literals into the Ace editor via the browser console, use `const BT = '\x60'` and string concatenation to avoid escaping issues.
