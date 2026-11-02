# Stock Management — PHP + SQLite web app

A self-contained web version of the same data you had in the Excel workbook
and the Access package: item master, GRN receipts, stock-card releases to
health facilities, and live balance reporting — all in a browser.

## Requirements

- PHP 7.4+ (8.x fine) with the `pdo_sqlite` extension, which ships enabled
  by default in almost every PHP install (XAMPP, MAMP, Laragon, most shared
  hosting, and plain `apt install php`).
- No MySQL/database server needed — the whole database is the single file
  `data/stock_management.sqlite`, already populated with your data.

## Run it locally in 30 seconds

```bash
cd stock-web
php -S localhost:8000
```
Then open **http://localhost:8000** in your browser.

Or drop the whole `stock-web` folder into XAMPP's `htdocs/`, MAMP's
`htdocs/`, or any shared host that supports PHP, and browse to it.

## What's inside

| Page | Purpose |
|---|---|
| Dashboard (`index.php`) | Totals, low-stock alerts, live stock-balance table, recent activity |
| Items (`items.php`) | Add / edit / delete items (name, local name, unit, description) |
| Stock In (`receipts.php`) | Record GRNs — one form, multiple item lines |
| Stock Out (`releases.php`) | Issue a stock card to a health facility — one form, multiple item lines |
| Health Facilities (`health_facilities.php`) | Add / edit facilities and their district |
| Projects (`projects.php`, admin) | Add programmes of work — each project keeps its own items, its own stock and its own reports, completely separate. Records with no project stay under "No project" |
| Categories (`categories.php`, admin) | Group items into sections — e.g. Consumables (مواد مصرفی) and Non-medical equipment (وسایل غیر طبی). Items, Stock Balance and movement reports can be filtered per category; items with no category stay under "No category" |
| Reports (`reports.php`) | Printable stock balance and releases-by-facility (Ctrl/Cmd+P → Save as PDF) |

Balances are never stored — they're calculated live as *received − released*
every time a page loads, so they can never drift out of sync the way a
hand-maintained Excel column can.

## Data file

`data/stock_management.sqlite` is the live database. Back it up by copying
that one file — that's the entire system's data. If you'd rather run on
MySQL/MariaDB instead of SQLite (e.g. for a busier, multi-user shared host),
say so and I can adapt `config/db.php` and the queries — the page code
barely changes since it all goes through the small set of functions in
`includes/functions.php`.

## Security note before putting this on a public server

This starter doesn't include a login screen — right now anyone who can reach
the URL can add/edit records. Add authentication (even a simple
username/password gate in `includes/header.php`) before deploying anywhere
other than your own local machine or a private network.
