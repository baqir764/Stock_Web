## Imported Claude Cowork project instructions

# AGENTS.md — how to work on this project

These are the owner's standing instructions. Follow them in every session
without being asked again.

## 1. How to talk to the owner

- **Explain things in simple English.** Short sentences, no jargon. If a
  technical word is unavoidable, say what it means in the same sentence.
- **Give click-only instructions.** Never ask the owner to open a terminal,
  command prompt, PowerShell, or a browser console. Never paste commands for
  them to run. Everything they must do should be clicks in Git Gui, in the
  browser, or in XAMPP's control panel.
- The owner works on **Windows** with **XAMPP**, and uses **Git Gui** for all
  Git operations.
- The owner's local project folder is `C:/xampp/htdocs/stock-web`.

## 2. What to do after each completed change

1. **Test it** (see section 4 — the agent runs the tests, not the owner).
2. **Commit** the change with a clear, plain-English commit message.
3. **Push** to the current session's branch.
4. **Open a Pull Request** if one is not open yet, and describe the change in
   simple English.

## 3. Pull Request and branch rules

- **Keep the Pull Request open. Do not merge it and do not close it.** The
  owner wants to keep using the same session and the same PR for follow-up
  work.
- All work happens on the session's own branch (for this session:
  `arena/30d4f517-stock-web`). Never switch to, create, or push to a different
  branch.
- Local syncing is done **in Git Gui**, never by merging the PR on GitHub:
  1. In Git Gui, click **Remote → Fetch from → origin**.
  2. Then click **Merge → Local merge…**, pick the session's tracking branch
     (`origin/arena/01a0be58-stock-web`), and click **Merge**.
  - Guide the owner through those clicks. Do **not** tell them to merge the
    pull request on the GitHub website.

## 4. Protect the owner's data

- **Never replace, overwrite, reset, or re-seed the owner's local database.**
  `data/stock_management.sqlite` is the live data file. The copy in the
  repository must not be pushed over the owner's working copy, and the owner's
  working copy must not be committed back with test data in it.
- Schema changes must be **additive and idempotent**: add them to
  `stockapp_migrate()` in `config/db.php` (which only adds a column if it is
  missing), never by shipping a new database file.
- Tests must run against a **temporary copy** with synthetic records, reading
  only the *schema* from the bundled database. Verify the bundled file's
  checksum is unchanged after a test run.
- When giving the owner instructions that could touch data, tell them to back
  up first (the app has **Backup** built in — `backup.php`).

## 5. Testing

- Regression test for the printed subject line:
  `tests/printed_subject.py` — run as `python3 tests/printed_subject.py [path/to/php]`.
- Regression test for the date-based reports:
  `tests/report_dates.py` — run as `python3 tests/report_dates.py [path/to/php]`.
- Regression test for the Projects layer:
  `tests/projects.py` — run as `python3 tests/projects.py [path/to/php]`.
- Regression test for the item Categories layer:
  `tests/item_categories.py` — run as `python3 tests/item_categories.py [path/to/php]`.
- Regression test for the single item number:
  `tests/item_number.py` — run as `python3 tests/item_number.py [path/to/php]`.
- They all need PHP with PDO SQLite. The agent runs them in the sandbox; the owner
  is never asked to run them.
- Also lint changed PHP files (`php -l`) and smoke-load the main pages to be
  sure nothing produces warnings or fatal errors.
- A change is only "done" after the tests pass.

## 6. Business rules that must not regress

These were deliberate decisions. Keep them unless the owner says otherwise.

- **One item number (`Items.ItemCode`, shown as `Item #`)** — every item has
  exactly ONE identifier that people see, write on a bin label and type into
  a spreadsheet. Format: `P1-MO-0001` (project 1, Main Office),
  `P1-PO-0001` (project 1, Provincial Office), `NP-MO-0001` /
  `NP-PO-0001` ("No project"). Each (project, office) pair owns an
  independent sequence. It is auto-assigned when the item is created,
  protected by a UNIQUE index, and **never regenerated** — not by an edit,
  not by an import, not by a later migration. If an item's office or project
  is corrected afterwards, its number stays the one it was created with
  (like a SKU) while the Office/Project columns show where it now sits.
  - `Items.ItemID` is only the hidden technical primary key for
    `StockReceiptDetails` / `StockReleaseDetails`. Never print it, never
    export it, never put it in a spreadsheet, never ask anyone to type it.
  - `Items.ItemNo` (the old Excel numbers) is retired. It stays as a dormant
    column so an older backup still restores cleanly, but no page reads or
    writes it.
  - Every list, form, document view/print, report, export and import uses
    that single `Item #`. Exports write exactly one `Item #` column; imports
    match items by `Item #` (never by `ItemID`).
  - All schema work for this lives in `stockapp_item_codes()` in
    `config/db.php`. The one-time conversion is deterministic (ordered by
    project, office, ItemID), so restoring an old backup cannot shuffle
    anyone's numbers. Helpers: `item_code_prefix()`, `next_item_code()`,
    `normalize_item_code()`.

- **Printed subject (`StockReleases.Subject`)** — the line printed at the top
  of a stock card. It is **editable on the stock card form for new, existing
  and old cards**. Saving writes it; the details page (`release_view.php`) and
  the printed copy (`release_print.php`) always show the current value through
  `release_subject()`.
  - If the field is left **blank**, the **classic line** is stored/printed:
    `release_subject_classic()` — kept byte-for-byte, typo and all, so old
    cards print exactly as they always have.
  - Old cards created before the column existed have `NULL` and fall back to
    the classic line until someone edits them.
  - After a **validation error**, the form must show the text just typed, not
    the stored value.
  - A post that contains **no `Subject` field at all** (stale cached page) must
    leave the stored subject alone.
- **Bulk imports never rewrite an existing card's subject.** `import.php` sets
  `Subject` only on cards it *creates*; its UPDATE branch leaves `Subject`
  out. The import field is labelled "Printed subject (new cards only)".
- **Storekeeper name (`StorekeeperName`) stays protected.** It is editable when
  a card is *created*, but on an existing card it is shown read-only and is
  **deliberately excluded from the UPDATE** in both `release_form.php` and
  `import.php`. Editing or re-saving a card can never rewrite its storekeeper.
  The same forward-only rule applies to a card's **Office**.
- **Projects** (`projects.php`, `project_form.php`, admin-only) are an
  org-wide dimension on top of the office split. An item, a GRN or a stock
  card carries at most ONE `ProjectID`; `NULL` means **"No project"**, and
  that is where every record created before projects existed stays — nothing
  is ever moved or rewritten by a migration, an edit or an import.
  - A GRN or stock card may only contain items of **its own project**; the
    form offers only those items and the server refuses anything else **by
    name** (`wrongProject` in `receipt_form.php` / `release_form.php`), so a
    hand-crafted POST cannot mix projects either.
  - A document's project is fixed at creation, like its office — editing never
    moves stock between projects.
  - Lists and reports filter with `?project=` (`''` = all, `none` = No
    project, `<id>` = that project), via `project_scope()` /
    `project_condition()`. **Never put the project test in a LEFT JOIN's ON
    clause** — it must go in WHERE (`stock_balance()` learned this the hard
    way), otherwise other projects' items still come back.
  - A project that still holds items/GRNs/cards cannot be deleted.
- **Item Categories** (`categories.php`, `category_form.php`, admin-only) are
  sections of the item master — e.g. Consumables (مواد مصرفی) or Non-medical
  equipment (وسایل غیر طبی). An item carries at most ONE `CategoryID`;
  `NULL` means **"No category"**, where every item created before categories
  existed stays — nothing is ever moved or rewritten by a migration or edit.
  - Categories describe **ITEMS only**. A GRN or stock card can hold lines of
    several categories, so **documents are never filtered by category** — only
    the item-based views are: the items list, Stock Balance, the By Date
    per-item movement table and the Item Ledger picker/guard. Document lists
    (By Date GRNs/cards, Releases by Facility) stay whole and print a note
    saying category filtering is not applied there.
  - The filter is `?category=` (`''` = all, `none` = No category, `<id>` =
    that category) via `category_scope()` / `category_condition()`, the same
    shape as projects. The test goes in **WHERE, never in a LEFT JOIN's ON
    clause**; in the By Date aggregates it runs against the joined item
    (`JOIN Items it … $cSql` on `it.CategoryID`).
  - The two seed categories are inserted **only when the `ItemCategories`
    table is first created** (`$categoriesExisted` in `config/db.php`) — a
    category someone deleted is never resurrected.
  - A category that still has items filed under it cannot be deleted; a name
    is required. A forged/unknown `CategoryID` on the item form falls back to
    No category.
- **Reports are date-aware** (`reports.php`). The `from` / `to` GET parameters
  (plain `YYYY-MM-DD`) filter the **By Date** report, **Releases by Facility**
  and the **Item Ledger**; an empty side means open-ended, swapped dates are
  swapped back with a note, and a value that is not a real calendar day is
  treated as "not provided". Dates are TEXT columns, so string comparison is
  the date comparison — never add date functions to these queries. PDO/SQLite
  binds placeholders **by name**, so a name may appear only once per statement
  (use `:pf1/:pt1`, `:pf2/:pt2` style when a bound value is needed twice).
  The **Stock Balance** tab is always a live all-time snapshot and must never
  be limited by the dates. Rows with no date count as "before the period"
  (opening balance) but never inside it. The chosen period is printed inside
  each report heading; the date form itself is `no-print`.
- **Balances are never stored** — they are calculated live as
  *received − released*. Do not add a stored balance column.
- **Office scoping** — every record belongs to `main` or `provincial`, and
  staff accounts are limited to their own office. Admins see both. Keep
  `office_scope()` / `can_access_office()` checks on every query and form.
- CSRF tokens are required on the forms that change security-sensitive state.

## 7. Code style in this repo

- Plain procedural PHP, no framework, no build step, no composer packages.
  Keep it that way — the app must run by dropping the folder into `htdocs/`.
- Comments explain **why** a rule exists (often cross-referencing the twin
  code path in `receipt_form.php` / `import.php`). When a rule changes, update
  the comments in **every** place that states it — `release_form.php`,
  `import.php`, `includes/functions.php`, `config/db.php`, `release_view.php`,
  `release_print.php`.
- Escape all output with `h()` (which is `htmlspecialchars(..., ENT_QUOTES)`).
- SQLite through PDO; always use prepared statements with bound parameters.
