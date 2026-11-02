"""Isolated regression test: python3 tests/item_categories.py [path/to/php]

Requires PHP with PDO SQLite. Only schema is read from the bundled database;
all requests and writes run in temporary app copies with synthetic records.

Covers the item Categories (بخش بندی) layer: the migration seeding
"Consumables" and "Non-medical equipment" once on a fresh install (and never
resurrecting a deleted seed), admin CRUD for categories, items being filed
under one category (or "No category"), the categories page being admin-only,
the items list and the item-based reports (Stock Balance, By Date per-item
movement, Item Ledger picker) honouring the category filter, document lists
staying whole (a GRN can hold items of several categories), and the category
filter combining with the project filter.
"""
import json
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv[1] if len(sys.argv) > 1 else "php"

RUNNER = '''<?php
ini_set('session.save_path', __DIR__ . '/sessions');
$req = json_decode($argv[1], true);
$_GET = $req['get'] ?? [];
$_POST = $req['post'] ?? [];
$_FILES = $req['files'] ?? [];
$_SERVER['REQUEST_METHOD'] = $req['method'] ?? 'GET';
$_SERVER['SCRIPT_NAME'] = $req['page'];
$_SERVER['REQUEST_URI'] = '/' . $req['page'];
session_start();
$_SESSION['user'] = ['UserID' => $req['user'] ?? 1];
$_SESSION['csrf'] = 'test-token';
require __DIR__ . '/' . $req['page'];
'''


def make_app(tmp_name, seed_categories):
    """Copy the app into tmp_name and build a synthetic database.

    seed_categories=False leaves the database exactly like the bundled one
    (no ItemCategories table, no Items.CategoryID column) so the migration in
    config/db.php has to do its work on first page load.
    """
    tmp = tempfile.mkdtemp(prefix=tmp_name)
    app = Path(tmp)
    for name in ("config", "includes"):
        shutil.copytree(ROOT / name, app / name)
    for source in ROOT.glob("*.php"):
        shutil.copy(source, app / source.name)
    (app / "data").mkdir()
    (app / "sessions").mkdir()
    database = app / "data/stock_management.sqlite"
    with sqlite3.connect(f"file:{ROOT / 'data/stock_management.sqlite'}?mode=ro", uri=True) as source:
        schema = source.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'").fetchall()
    with sqlite3.connect(database) as db:
        for (sql,) in schema:
            db.execute(sql)
        # The bundled database predates projects: build the same schema the
        # migration in config/db.php would add on first page load.
        db.execute('''CREATE TABLE IF NOT EXISTS Projects (
            ProjectID INTEGER PRIMARY KEY AUTOINCREMENT,
            ProjectName TEXT NOT NULL,
            Description TEXT,
            CreatedByUserID INTEGER,
            CreatedAt TEXT,
            UpdatedByUserID INTEGER,
            UpdatedAt TEXT
        )''')
        for table in ('Items', 'StockReceipts', 'StockReleases'):
            cols = [r[1] for r in db.execute(f'PRAGMA table_info({table})')]
            if 'ProjectID' not in cols:
                db.execute(f'ALTER TABLE {table} ADD COLUMN ProjectID INTEGER')
        if seed_categories:
            db.execute('''CREATE TABLE ItemCategories (
                CategoryID INTEGER PRIMARY KEY AUTOINCREMENT,
                CategoryName TEXT NOT NULL,
                CategoryNameLocal TEXT,
                CreatedAt TEXT
            )''')
            db.execute("INSERT INTO ItemCategories (CategoryID,CategoryName,CategoryNameLocal) VALUES (1,'Consumables','مواد مصرفی'),(2,'Non-medical equipment','وسایل غیر طبی')")
            cols = [r[1] for r in db.execute('PRAGMA table_info(Items)')]
            if 'CategoryID' not in cols:
                db.execute('ALTER TABLE Items ADD COLUMN CategoryID INTEGER')
            db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (1,'tester','unused','Test User','admin',NULL)")
            db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (2,'staff','unused','Staff User','staff','provincial')")
            db.execute("INSERT INTO HealthFacilities (HFID,HFName,Office) VALUES (1,'Main facility','main'),(2,'Provincial facility','provincial')")
            # Paper: consumable. Soap: non-medical equipment. Chlorine: no
            # category, like every item created before categories existed.
            db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID,CategoryID) VALUES (1,'Paper','pack','main',NULL,1)")
            db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID,CategoryID) VALUES (2,'Soap','bar','main',NULL,2)")
            db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID,CategoryID) VALUES (3,'Chlorine','litre','main',NULL,NULL)")
            db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office,ProjectID) VALUES (1,'GRN-C1','2026-08-01','S','main',NULL)")
            # One GRN holding two categories on purpose: documents must never
            # be filtered by category.
            db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office,ProjectID) VALUES (2,'GRN-MIX','2026-08-02','S','main',NULL)")
            db.execute("INSERT INTO StockReceiptDetails (ReceiptID,ItemID,Quantity) VALUES (1,1,100),(2,2,40),(2,3,50)")
            db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office,ProjectID) VALUES (1,'STC-C1',1,'2026-08-10','main',NULL)")
            db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office,ProjectID) VALUES (2,'STC-MIX',1,'2026-08-11','main',NULL)")
            db.execute("INSERT INTO StockReleaseDetails (ReleaseID,ItemID,Quantity) VALUES (1,1,10),(2,2,4),(2,3,5)")
        else:
            db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (1,'tester','unused','Test User','admin',NULL)")
    (app / "runner.php").write_text(RUNNER)
    return app, database


def runner_for(app, database):
    def request(page, get=None, post=None, user=1, files=None):
        req = dict(page=page, get=get or {}, post=post or {}, files=files or {}, user=user)
        if post is not None:
            req['method'] = 'POST'
            req['post']['csrf_token'] = 'test-token'
        result = subprocess.run([PHP, 'runner.php', json.dumps(req)], cwd=app,
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stderr + result.stdout
        assert 'Fatal error' not in result.stdout, result.stdout[-2000:]
        return result.stdout

    def one(sql):
        with sqlite3.connect(database) as db:
            return db.execute(sql).fetchone()[0]

    return request, one


# ==================== 1. fresh-install migration ====================
app, database = make_app("stock-cat-fresh-", seed_categories=False)
request, one = runner_for(app, database)

out = request('categories.php')
assert one("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='ItemCategories'") == 1
assert one("SELECT COUNT(*) FROM ItemCategories") == 2
assert one("SELECT CategoryName FROM ItemCategories WHERE CategoryNameLocal='مواد مصرفی'") == 'Consumables'
assert one("SELECT CategoryName FROM ItemCategories WHERE CategoryNameLocal='وسایل غیر طبی'") == 'Non-medical equipment'
assert 'CategoryID' in [r[1] for r in sqlite3.connect(database).execute('PRAGMA table_info(Items)')]
assert 'Consumables' in out and 'Non-medical equipment' in out
print('PASS: a fresh install gets the ItemCategories table, the two seeded sections and Items.CategoryID')

# ==================== 2. deleted seeds are never resurrected ====================
with sqlite3.connect(database) as db:
    db.execute("DELETE FROM ItemCategories WHERE CategoryName='Consumables'")
request('categories.php')
request('items.php')
assert one("SELECT COUNT(*) FROM ItemCategories") == 1, 'a deleted seed category must not come back'
assert one("SELECT COUNT(*) FROM ItemCategories WHERE CategoryName='Consumables'") == 0
print('PASS: seeding happens once — a deleted category is never recreated')
shutil.rmtree(app)

# ==================== main app with synthetic records ====================
app, database = make_app("stock-cat-", seed_categories=True)
request, one = runner_for(app, database)

# ==================== 3. admin CRUD ====================
request('category_form.php', post={'CategoryName': 'Surgical supplies', 'CategoryNameLocal': 'وسایل جراحی'})
assert one("SELECT COUNT(*) FROM ItemCategories WHERE CategoryName='Surgical supplies' AND CategoryNameLocal='وسایل جراحی'") == 1
new_id = one("SELECT CategoryID FROM ItemCategories WHERE CategoryName='Surgical supplies'")
request('category_form.php', get={'id': str(new_id)}, post={'CategoryName': 'Surgical supplies (renamed)', 'CategoryNameLocal': ''})
assert one(f"SELECT CategoryName FROM ItemCategories WHERE CategoryID={new_id}") == 'Surgical supplies (renamed)'
request('category_form.php', post={'CategoryName': 'Temporary', 'CategoryNameLocal': ''})
tmp_id = one("SELECT CategoryID FROM ItemCategories WHERE CategoryName='Temporary'")
request('categories.php', post={'delete_category': str(tmp_id)})
assert one(f"SELECT COUNT(*) FROM ItemCategories WHERE CategoryID={tmp_id}") == 0
# deleting a category that items are filed under must be refused
request('categories.php', post={'delete_category': '1'})
assert one("SELECT COUNT(*) FROM ItemCategories WHERE CategoryID=1") == 1
assert one("SELECT COUNT(*) FROM Items WHERE CategoryID=1") == 1, 'refusing the delete must leave items untouched'
# empty name refused
out = request('category_form.php', post={'CategoryName': '   ', 'CategoryNameLocal': ''})
assert 'required' in out
assert one("SELECT COUNT(*) FROM ItemCategories WHERE TRIM(CategoryName)=''") == 0
print('PASS: categories can be created, renamed and deleted — but only while unused, and a name is required')

# ==================== 4. staff cannot reach the categories pages ====================
out = request('categories.php', user=2)
assert 'New category' not in out, 'the categories page is admin-only'
out = request('category_form.php', user=2)
assert 'CategoryName' not in out
print('PASS: the categories pages are admin-only')

# ==================== 5. the item form files items under a category ====================
request('item_form.php', post={'ItemName': 'Bucket', 'ItemNameLocal': '', 'Description': '',
                               'Unit': 'pcs', 'Office': 'main', 'ProjectID': '', 'CategoryID': '2'})
assert one("SELECT CategoryID FROM Items WHERE ItemName='Bucket'") == 2
request('item_form.php', post={'ItemName': 'Free stock', 'ItemNameLocal': '', 'Description': '',
                               'Unit': 'pcs', 'Office': 'main', 'ProjectID': '', 'CategoryID': 'none'})
assert one("SELECT CategoryID FROM Items WHERE ItemName='Free stock'") is None
request('item_form.php', post={'ItemName': 'Forged cat', 'ItemNameLocal': '', 'Description': '',
                               'Unit': 'pcs', 'Office': 'main', 'ProjectID': '', 'CategoryID': '999'})
assert one("SELECT CategoryID FROM Items WHERE ItemName='Forged cat'") is None, 'a forged category id must not stick'
# editing keeps/changes the category
bucket_id = one("SELECT ItemID FROM Items WHERE ItemName='Bucket'")
request('item_form.php', get={'id': str(bucket_id)}, post={'ItemName': 'Bucket', 'ItemNameLocal': '', 'Description': '',
                                                           'Unit': 'pcs', 'Office': 'main', 'ProjectID': '', 'CategoryID': 'none'})
assert one(f"SELECT CategoryID FROM Items WHERE ItemID={bucket_id}") is None
# the edit form shows the saved category as selected
out = request('item_form.php', get={'id': '1'})
assert 'name="CategoryID"' in out
print('PASS: the item form saves the chosen category; forged ids fall back to No category')

# ==================== 6. the items list filter ====================
out = request('items.php', {'category': '1'})
assert 'Paper' in out and 'Soap' not in out and 'Chlorine' not in out
assert 'Consumables' in out
out = request('items.php', {'category': 'none'})
assert 'Chlorine' in out and 'Paper' not in out and 'Soap' not in out
out = request('items.php')
assert 'Paper' in out and 'Soap' in out and 'Chlorine' in out
assert 'id="categoryFilter"' in out
# the Category column labels every row
assert 'No category' in out
# combining with the project filter (no project + consumables)
out = request('items.php', {'project': 'none', 'category': '1'})
assert 'Paper' in out and 'Soap' not in out
print('PASS: the items list filters by category alone and combined with project')

# ==================== 7. Stock Balance report ====================
bal = request('reports.php', {'report': 'balance', 'category': '1'})
assert 'Paper' in bal and 'Soap' not in bal and 'Chlorine' not in bal
assert 'Consumables' in bal, 'the balance report shows the category column'
bal = request('reports.php', {'report': 'balance', 'category': 'none'})
assert 'Chlorine' in bal and 'Paper' not in bal
assert 'No category' in bal
bal = request('reports.php', {'report': 'balance'})
assert 'Paper' in bal and 'Soap' in bal and 'Chlorine' in bal
print('PASS: Stock Balance honours the category filter and labels each row')

# ==================== 8. By Date report ====================
out = request('reports.php', {'report': 'dates', 'all': '1', 'category': '1'})
# per-item movement: only Paper (received 100, released 10)
assert 'Paper' in out
assert '>90.00<' in out, 'closing balance must cover only the consumable'
# document lists stay whole: the mixed GRN is still listed even though it
# holds a non-medical item too
assert 'GRN-MIX' in out and 'GRN-C1' in out
out = request('reports.php', {'report': 'dates', 'all': '1', 'category': 'none'})
assert 'Chlorine' in out and 'Paper' not in out
out = request('reports.php', {'report': 'dates', 'all': '1'})
assert 'Paper' in out and 'Soap' in out and 'Chlorine' in out
# a category note is shown while filtered
out = request('reports.php', {'report': 'dates', 'all': '1', 'category': '2'})
assert 'document lists' in out.lower() or 'whole GRNs' in out
print('PASS: By Date filters the per-item movement but never hides whole documents')

# ==================== 9. Item Ledger report ====================
out = request('reports.php', {'report': 'item', 'item_id': '1', 'category': '1'})
assert 'GRN-C1' in out and 'STC-C1' in out
# an item of another category is refused while the filter is on
out = request('reports.php', {'report': 'item', 'item_id': '2', 'category': '1'})
assert "wasn't found" in out and 'GRN-MIX' not in out
# the picker only offers the filtered category's items
out = request('reports.php', {'report': 'item', 'category': '1'})
assert '>Paper' in out or 'Paper' in out
assert 'Soap' not in out and 'Chlorine' not in out
# the filter survives the item picker navigation
assert 'category=1' in out
print('PASS: the Item Ledger picker and guard honour the category filter')

# ==================== 10. Releases by Facility note ====================
out = request('reports.php', {'report': 'byhf', 'category': '1'})
assert 'Main facility' in out
assert 'Category filtering is not applied' in out
print('PASS: Releases by Facility stays document-wide and says so')

shutil.rmtree(app)
bundled = ROOT / 'data/stock_management.sqlite'
print('ALL ITEM-CATEGORY GROUPS PASSED')
