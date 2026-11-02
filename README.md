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

## Run it locally

Put the whole `stock-web` folder into XAMPP's `htdocs/` folder, start Apache
in the XAMPP control panel, then open `http://localhost/stock-web` in your
browser. The app's links are written without `.php` endings, so it needs
Apache's rewrite rules (they are in `.htaccess`); the plain PHP development
server does not provide those rules.

Sign in with the `admin` account. It asks for a new password on first login.

## What's inside

| Page | Purpose |
|---|---|
| Dashboard (`index.php`) | Totals, low-stock alerts, live stock-balance table, recent activity |
| Items (`items.php`) | Add / edit / delete items. Every item has one **Item #** (below) |
| Stock In (`receipts.php`) | Record GRNs — one form, multiple item lines |
| Stock Out (`releases.php`) | Issue a stock card to a health facility — one form, multiple item lines |
| Health Facilities (`health_facilities.php`) | Add / edit facilities and their district |
| Projects (`projects.php`, admin) | Add programmes of work — each project keeps its own items, its own stock and its own reports, completely separate. Records with no project stay under "No project" |
| Categories (`categories.php`, admin) | Group items into sections — e.g. Consumables (مواد مصرفی) and Non-medical equipment (وسایل غیر طبی). Items, Stock Balance and movement reports can be filtered per category; items with no category stay under "No category" |
| Reports (`reports.php`) | Printable stock balance and releases-by-facility (Ctrl/Cmd+P → Save as PDF) |

Balances are never stored — they're calculated live as *received − released*
every time a page loads, so they can never drift out of sync the way a
hand-maintained Excel column can.

## Item numbers (Item #)

There is **one** item identifier, called `Item #`, and it belongs to one
project **and** one office:

| Example | Meaning |
|---|---|
| `P1-MO-0001` | Project 1 · Main Office |
| `P1-PO-0001` | Project 1 · Provincial Office |
| `NP-MO-0001` | No project · Main Office |
| `NP-PO-0001` | No project · Provincial Office |

Each project+office pair starts its own sequence at `0001`. The number is
assigned automatically when the item is created and never changes afterwards —
even if the item is later moved to another office or filed under another
project. The database enforces uniqueness with an index. The old `Item ID`
and `Item No` columns are no longer shown or used; the internal row key that
links receipt/release lines stays hidden inside the database only. Exports
contain exactly one `Item #` column, and imports match items by `Item #`.

## Data file

`data/stock_management.sqlite` is the live database. Back it up by copying
that one file — that's the entire system's data. If you'd rather run on
MySQL/MariaDB instead of SQLite (e.g. for a busier, multi-user shared host),
say so and I can adapt `config/db.php` and the queries — the page code
barely changes since it all goes through the small set of functions in
`includes/functions.php`.

## Security and accounts

The app has a sign-in screen, `admin` / `staff` roles, password hashing, a
lockout after repeated failed attempts, and a forced password change on first
login. Staff accounts only see their own office; admins see both. Reports,
users, projects, categories and backup are admin-only. Before putting it on a
public server, use HTTPS and take a Backup from the Backup page first.
