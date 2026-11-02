# AAIWO Stock Management — Access Database Setup

This package converts the `stock-web` app (PHP + SQLite) into a native Microsoft
Access database: **AAIWO_StockManagement.accdb**.

## What's already done for you

The `.accdb` file already contains, fully built and ready to use:

- All **8 tables** — Districts, Items, Users, HealthFacilities, StockReceipts,
  StockReceiptDetails, StockReleases, StockReleaseDetails — with the same
  columns, data types and primary keys as the source database.
- **All your data**, imported exactly as it was in `stock_management.sqlite`
  (17 districts, 82 items, 116 health facilities, 2 GRNs, 83 GRN lines, 116
  stock cards, 8,929 release lines, 2 user accounts).
- **All the relationships**, with referential integrity enforced by Access:
  - Districts → Health Facilities
  - Items → GRN lines / Stock Card lines
  - Stock Receipts → its lines (cascade delete)
  - Stock Releases → its lines (cascade delete)
  - Health Facilities → Stock Releases
  - Users → Stock Receipts / Stock Releases (created-by / updated-by)

What's **not** in the file yet is the point-and-click interface (forms,
reports, a home screen) — Access's form/report designer can only be driven by
Access itself, not by the tool that built the raw database. That's what the
one-time setup step below adds.

## One-time setup (about 2 minutes)

1. Open **AAIWO_StockManagement.accdb** in Microsoft Access (2010 or newer).
2. If Access shows a yellow security bar, click **Enable Content** — the setup
   code needs to run once.
3. Open the VBA editor: **Database Tools** tab → **Visual Basic** (or `Alt+F11`).
4. In the VBA editor: **File → Import File…**, select `RunAllSetup.bas` from
   this package, click Open. A module called `modAAIWOSetup` appears in the
   Modules list.
5. Open the Immediate window (`Ctrl+G`), type the following, and press Enter:

   ```
   RunAllSetup
   ```

6. Wait a few seconds — you'll see a "Setup complete" message box summarizing
   what was built. Close and reopen the database (or just switch to it) and
   you'll land on the **AAIWO Stock Management** switchboard.

That's it — every Query, Form, Report and the switchboard now exist as real
Access objects, exactly like they'd look if you'd built them by hand in the
Access designer. `RunAllSetup` is safe to run again if you ever want to (it
skips anything already built, and only re-creates the queries so query logic
stays current).

## What you get

| Web page | Access equivalent |
|---|---|
| Items | `frmItems` — editable list |
| Health Facilities | `frmHealthFacilities` — editable list with a District lookup |
| Districts | `frmDistricts` — editable list |
| Users | `frmUsers` — editable list (see password note below) |
| Stock In / GRN + lines | `frmStockReceipts` with an embedded line-items subform |
| Stock Out / Stock Card + lines | `frmStockReleases` with an embedded line-items subform, blocks over-issuing (see below) |
| Reports → Stock Balance | `rptStockBalance` |
| Reports → Releases by Facility | `rptReleasesByFacility` |
| GRN print slip | `rptGRN` (open filtered to one GRN, or preview all) |
| Stock Card print slip | `rptStockCard` (same) |
| (new) Low stock list | `qryLowStock`, reachable from the switchboard |

A **switchboard** (`frmSwitchboard`) is set as the startup form, with one
button per screen above.

New records added through the Items/Districts/Health Facilities/Users lists,
and new GRNs/Stock Cards (and their line items), get their ID field
auto-filled the same way the web app did (next available number) — you don't
need to type an ID yourself.

## Two things that don't fully carry over from the web app

**1. Login / passwords.** The web app used bcrypt password hashes to log
people in. Access has no built-in way to verify a bcrypt hash, so this
setup does **not** implement real login security — `frmUsers` just shows the
existing hash as read-only text (it can't be used to reset a password from
here). Instead, opening the switchboard asks "who is using the database right
now" and just tags the records you create/edit with that username for
attribution — it is **not** access control. Anyone who can open the file can
use it. If you need real per-user security, that has to live at the level of
who has the file / SharePoint / network folder, not inside Access.

**2. The "can't release more than what's in stock" check.** This one
specific rule (blocking a Stock Card line if the quantity exceeds what's
still in stock for that item) needs actual VBA code behind the release-lines
subform, which in turn needs one extra Access setting:

> File → Options → Trust Center → Trust Center Settings → Macro Settings →
> check **"Trust access to the VBA project object model"**.

If that setting isn't on when you run `RunAllSetup`, everything else above
still gets built — the summary message box will just say this one check was
skipped. Turn the setting on and run `RunAllSetup` again to add it
retroactively (it won't touch anything already built).

## Files in this package

- `AAIWO_StockManagement.accdb` — the database (tables, data, relationships).
- `RunAllSetup.bas` — the one-time setup module (import once, run once).
- This instructions file.
