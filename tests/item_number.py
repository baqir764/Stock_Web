"""Isolated regression test: python3 tests/item_number.py [path/to/php]

Requires PHP with PDO SQLite. Only the SCHEMA is read from the bundled
database; every request and every write runs in a temporary app copy with
synthetic records, so the owner's live data file is never touched.

Covers the single item identifier ("Item #"):
  * one number per (project, office) pair: P1-MO-0001 / P1-PO-0001 /
    NP-MO-0001 / NP-PO-0001 / P2-MO-0001 ...
  * one-time, deterministic conversion of legacy MO-01/PO-01 codes and the
    retired Items.ItemNo values, guarded by a real UNIQUE index
  * a new item gets the next number in its own project+office sequence
  * an existing item's number is immutable (office/project edits never
    rewrite it), and an import never rewrites it either
  * the number is the ONLY identifier shown: lists, dashboard, document
    views/prints, reports, exports and import file formats
  * the hidden internal ItemID is still used for the detail rows only
"""
import hashlib
import io
import json
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv[1] if len(sys.argv) > 1 else "php"
BUNDLED = ROOT / "data/stock_management.sqlite"
BEFORE = hashlib.sha256(BUNDLED.read_bytes()).hexdigest()

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

with tempfile.TemporaryDirectory(prefix="stock-item-no-") as tmp:
    app = Path(tmp)
    for name in ("config", "includes", "assets"):
        shutil.copytree(ROOT / name, app / name)
    for source in ROOT.glob("*.php"):
        shutil.copy(source, app / source.name)
    (app / "data").mkdir()
    (app / "sessions").mkdir()
    (app / "logs").mkdir()
    database = app / "data/stock_management.sqlite"

    with sqlite3.connect(f"file:{BUNDLED}?mode=ro", uri=True) as source:
        schema = source.execute(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        ).fetchall()

    with sqlite3.connect(database) as db:
        for (sql,) in schema:
            db.execute(sql)
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Active,MustChangePassword,Office) "
                   "VALUES (1,'tester','unused','Test Admin','admin',1,0,NULL)")
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Active,MustChangePassword,Office) "
                   "VALUES (2,'staff','unused','Staff User','staff',1,0,'provincial')")
        db.execute("INSERT INTO Projects (ProjectID,ProjectName) VALUES (1,'First project'),(2,'Second project')")
        db.execute("INSERT INTO HealthFacilities (HFID,HFName,Office) VALUES (1,'Main facility','main'),"
                   "(2,'Provincial facility','provincial')")
        db.execute("INSERT INTO ItemCategories (CategoryID,CategoryName,CategoryNameLocal) "
                   "VALUES (1,'Consumables','مواد مصرفی')")
        # Deliberately messy legacy data: old per-office codes, one blank
        # code, and the retired ItemNo numbers that must never be shown.
        db.execute("""INSERT INTO Items
            (ItemID,ItemNo,ItemCode,ItemName,Unit,Office,ProjectID,CategoryID) VALUES
            (1,83,'MO-01','Paper','pack','main',NULL,1),
            (2,84,'MO-02','Soap','bar','main',1,1),
            (3,85,NULL,'Chlorine','litre','main',1,NULL),
            (4,86,'MO-03','Gloves','box','main',2,NULL),
            (5,87,'PO-01','Mask','box','provincial',1,NULL),
            (6,88,'PO-02','Tape','roll','provincial',NULL,NULL),
            (7,89,'PO-03','Pen','pcs','provincial',2,NULL)""")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office,ProjectID) "
                   "VALUES (1,'GRN-1','2026-08-01','S','main',NULL)")
        db.execute("INSERT INTO StockReceiptDetails (ReceiptID,ItemID,Quantity) VALUES (1,1,10),(1,2,5)")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office,ProjectID) "
                   "VALUES (1,'STC-1',1,'2026-08-10','main',NULL)")
        db.execute("INSERT INTO StockReleaseDetails (ReleaseID,ItemID,Quantity) VALUES (1,1,2)")

    (app / "runner.php").write_text(RUNNER)

    def request(page, get=None, post=None, files=None, user=1, binary=False):
        req = dict(page=page, get=get or {}, post=post or {}, files=files or {}, user=user)
        if post is not None:
            req['method'] = 'POST'
            req['post']['csrf_token'] = 'test-token'
        result = subprocess.run([PHP, 'runner.php', json.dumps(req)], cwd=app,
                                capture_output=True, text=not binary)
        assert result.returncode == 0, (result.stderr or '') + (result.stdout or b'')[:2000]
        if binary:
            return result.stdout
        out = result.stdout
        assert 'Fatal error' not in out, out[-2000:]
        assert 'Warning:' not in out, out[-2000:]
        return out

    def one(sql):
        with sqlite3.connect(database) as db:
            return db.execute(sql).fetchone()[0]

    def code_map():
        with sqlite3.connect(database) as db:
            return dict(db.execute("SELECT ItemID, ItemCode FROM Items").fetchall())

    def xlsx_strings(raw):
        """Return every byte of the zip payload of an .xlsx page response."""
        start = raw.find(b'PK\x03\x04')
        assert start >= 0, 'export did not produce a zip/xlsx file'
        with zipfile.ZipFile(io.BytesIO(raw[start:])) as z:
            return b''.join(z.read(n) for n in z.namelist())

    # ==================== 1. one-time deterministic conversion ============
    request('items.php')
    expected = {
        1: 'NP-MO-0001', 6: 'NP-PO-0001',
        2: 'P1-MO-0001', 3: 'P1-MO-0002', 5: 'P1-PO-0001',
        4: 'P2-MO-0001', 7: 'P2-PO-0001',
    }
    assert code_map() == expected, code_map()
    assert one("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='UQ_Items_ItemCode'") == 1
    assert one("SELECT COUNT(DISTINCT ItemCode) FROM Items") == 7
    assert one("SELECT COUNT(*) FROM Items WHERE ItemCode IS NULL OR TRIM(ItemCode)=''") == 0
    print('PASS: legacy MO-/PO- codes, a blank code and ItemNo values become one per-project+office Item #')

    # A second connection must not re-number anything.
    request('index.php')
    assert code_map() == expected

    # Simulate restoring an older backup: one legacy code comes back, the
    # deterministic conversion must restore exactly the same numbers.
    with sqlite3.connect(database) as db:
        db.execute("UPDATE Items SET ItemCode='MO-01' WHERE ItemID=1")
    request('categories.php')
    assert code_map() == expected, code_map()
    print('PASS: conversion is idempotent and deterministic — a restored old code cannot shuffle numbers')

    # The UNIQUE index is a real database constraint, not just a PHP rule.
    try:
        with sqlite3.connect(database) as db:
            db.execute("UPDATE Items SET ItemCode='P1-MO-0001' WHERE ItemID=3")
        raise AssertionError('database allowed a duplicate Item #')
    except sqlite3.IntegrityError:
        pass
    print('PASS: the database itself refuses a duplicate Item #')

    # ==================== 2. new items + immutability ====================
    request('item_form.php', post={'ItemName': 'Bucket', 'ItemNameLocal': '', 'Description': '',
                                  'Unit': 'pcs', 'Office': 'main', 'ProjectID': '1', 'CategoryID': 'none'})
    assert one("SELECT ItemCode FROM Items WHERE ItemName='Bucket'") == 'P1-MO-0003'
    request('item_form.php', post={'ItemName': 'Free stock', 'ItemNameLocal': '', 'Description': '',
                                  'Unit': 'pcs', 'Office': 'provincial', 'ProjectID': 'none', 'CategoryID': 'none'})
    assert one("SELECT ItemCode FROM Items WHERE ItemName='Free stock'") == 'NP-PO-0002'
    print('PASS: a new item gets the next number in its own project+office sequence')

    # Editing office AND project must not rewrite the number (SKU rule).
    request('item_form.php', get={'id': '2'},
            post={'ItemName': 'Soap renamed', 'ItemNameLocal': '', 'Description': '',
                  'Unit': 'bar', 'Office': 'provincial', 'ProjectID': '2', 'CategoryID': 'none'})
    assert one("SELECT ItemCode FROM Items WHERE ItemID=2") == 'P1-MO-0001'
    assert one("SELECT Office FROM Items WHERE ItemID=2") == 'provincial'
    assert one("SELECT ProjectID FROM Items WHERE ItemID=2") == 2
    print('PASS: an existing Item # is immutable even when office and project change')

    # ==================== 3. exactly one identifier is shown ==============
    listings = {
        'items list': request('items.php'),
        'dashboard': request('index.php'),
        'stock balance report': request('reports.php', get={'report': 'balance'}),
        'item ledger': request('reports.php', get={'report': 'item', 'item_id': '1'}),
        'GRN details': request('receipt_view.php', get={'id': '1'}),
        'stock card details': request('release_view.php', get={'id': '1'}),
        'GRN print': request('receipt_print.php', get={'id': '1'}),
        'stock card print': request('release_print.php', get={'id': '1'}),
    }
    for name, out in listings.items():
        assert 'Item #' in out, f'{name} has no Item # column'
        assert 'NP-MO-0001' in out, f'{name} does not show the item number'
        assert '<th>Item ID</th>' not in out, f'{name} still shows the internal ItemID'
        assert '<th>Item No</th>' not in out, f'{name} still shows the retired ItemNo'
        assert '<th>Item Id</th>' not in out, f'{name} still shows a second identifier'
    assert '<td>83</td>' not in listings['items list'], 'legacy ItemNo is still printed'
    assert '<th>Item Code</th>' not in listings['items list']
    print('PASS: lists, dashboard, views, prints and reports all show one Item # and no legacy/internal identifiers')

    # ==================== 4. exports use only Item # =====================
    for page, params in (('export_items.php', None), ('export_excel.php', {'type': 'grn'}),
                         ('export_excel.php', {'type': 'card'})):
        blob = xlsx_strings(request(page, get=params, binary=True))
        assert b'Item #' in blob, f'{page} does not export an Item # column'
        assert b'Item ID' not in blob, f'{page} still exports the internal ItemID'
        assert b'Item Code' not in blob, f'{page} still exports a second identifier'
    print('PASS: all Excel exports carry exactly one Item # column')

    # ==================== 5. imports match by Item # =====================
    def upload(page, text, name, post):
        f = app / name
        f.write_text(text)
        return request(page, post=post,
                       files={'import_file': {'name': name, 'tmp_name': str(f),
                                              'error': 0, 'size': f.stat().st_size}})

    grn_csv = ('GRNNumber,ReceiptDate,Supplier,Item #,Quantity\n'
               'GRN-NEW,2026-09-01,S,P1-MO-0002,7\n'
               'GRN-NEW,2026-09-01,S,NOPE-9999,3\n')
    out = upload('import.php', grn_csv, 'grn.csv',
                 {'office': 'main', 'import_type': 'grn', 'type': 'grn'})
    assert 'Skipped unknown Item #(s): NOPE-9999' in out, out
    assert one("SELECT COUNT(*) FROM StockReceiptDetails rd JOIN StockReceipts r ON r.ReceiptID=rd.ReceiptID "
               "WHERE r.GRNNumber='GRN-NEW' AND rd.ItemID=3 AND rd.Quantity=7") == 1
    assert 'Item #' in request('import.php', get={'type': 'grn'})
    assert 'matching is by Item # only' in request('import.php', get={'type': 'grn'})

    card_csv = ('StockCardNo,HFID,Item #,Quantity\n'
                'STC-NEW,1,NP-MO-0001,2\n')
    upload('import.php', card_csv, 'cards.csv',
           {'office': 'main', 'import_type': 'card', 'type': 'card',
            'printed_subject': '', 'storekeeper_name': ''})
    assert one("SELECT COUNT(*) FROM StockReleaseDetails rd JOIN StockReleases r ON r.ReleaseID=rd.ReleaseID "
               "WHERE r.StockCardNo='STC-NEW' AND rd.ItemID=1") == 1
    print('PASS: GRN / stock-card imports match by Item # and report unknown numbers')

    items_csv = ('Item #,ItemName,Unit,Description\n'
                 'P1-MO-0002,Chlorine renamed,litre,updated by import\n'
                 ',Brand new item,box,created by import\n')
    upload('import_items.php', items_csv, 'items.csv',
           {'office': 'main', 'project_id': '2'})
    assert one("SELECT ItemName FROM Items WHERE ItemID=3") == 'Chlorine renamed'
    assert one("SELECT ItemCode FROM Items WHERE ItemID=3") == 'P1-MO-0002', 'import rewrote an existing Item #'
    assert one("SELECT ItemCode FROM Items WHERE ItemName='Brand new item'") == 'P2-MO-0002'
    print('PASS: item import matches by Item #, creates in the chosen project and never rewrites a number')

    # ==================== 6. the live data file is untouched ==============
    assert hashlib.sha256(BUNDLED.read_bytes()).hexdigest() == BEFORE, 'bundled database changed!'
    print('All single-item-number regression checks passed; bundled database untouched.')
