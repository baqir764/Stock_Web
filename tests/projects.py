"""Isolated regression test: python3 tests/projects.py [path/to/php]

Requires PHP with PDO SQLite. Only schema is read from the bundled database;
all requests and writes run in a temporary app copy with synthetic records.

Covers the Projects layer: creating projects, items/GRNs/stock cards filed
under one project (or "No project"), each project's stock staying completely
separate (a document can never contain another project's items), per-project
lists and reports, old records staying in "No project", and imports filing
only newly created documents under a project.
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

with tempfile.TemporaryDirectory(prefix="stock-projects-") as tmp:
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
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (1,'tester','unused','Test User','admin',NULL)")
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (2,'staff','unused','Staff User','staff','provincial')")
        db.execute("INSERT INTO HealthFacilities (HFID,HFName,Office) VALUES (1,'Main facility','main'),(2,'Provincial facility','provincial')")
        db.execute("INSERT INTO Projects (ProjectID,ProjectName) VALUES (1,'First project'),(2,'Second project')")
        db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID) VALUES (1,'Paper','pack','main',1)")
        db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID) VALUES (2,'Soap','bar','main',2)")
        db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office,ProjectID) VALUES (3,'Chlorine','litre','main',NULL)")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office,ProjectID) VALUES (1,'GRN-P1','2026-08-01','S','main',1)")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office,ProjectID) VALUES (2,'GRN-NP','2026-08-02','S','main',NULL)")
        db.execute("INSERT INTO StockReceiptDetails (ReceiptID,ItemID,Quantity) VALUES (1,1,100),(2,3,50)")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office,ProjectID) VALUES (1,'STC-P1',1,'2026-08-10','main',1)")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office,ProjectID) VALUES (2,'STC-NP',1,'2026-08-11','main',NULL)")
        db.execute("INSERT INTO StockReleaseDetails (ReleaseID,ItemID,Quantity) VALUES (1,1,10),(2,3,5)")

    (app / "runner.php").write_text('''<?php
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
''')

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

    def sql(query, params=()):
        with sqlite3.connect(database) as db:
            return db.execute(query, params).fetchall()

    def one(query, params=()):
        return sql(query, params)[0][0]

    # ---- projects are admin-only ----
    page = request('projects.php')
    assert 'Second project' in page and 'New project' in page
    assert request('projects.php', user=2) == '', 'staff must be redirected away from Projects'
    assert request('project_form.php', user=2) == ''
    print('PASS: the Projects pages exist and are admin-only')

    # ---- create a project through the form ----
    request('project_form.php', post={'ProjectName': 'Third project', 'Description': 'created by test'})
    assert one("SELECT ProjectName FROM Projects WHERE ProjectID=3") == 'Third project'
    assert 'Third project' in request('projects.php')
    request('project_form.php', post={'ProjectName': '   ', 'Description': ''})
    assert sql("SELECT COUNT(*) FROM Projects")[0][0] == 3, 'a blank name must not create a project'
    print('PASS: a project is created through the form, blank names refused')

    # ---- item lists separate by project ----
    for proj, shown, hidden in [('1', ['Paper'], ['Soap', 'Chlorine']),
                                ('2', ['Soap'], ['Paper', 'Chlorine']),
                                ('none', ['Chlorine'], ['Paper', 'Soap'])]:
        page = request('items.php', {'project': proj})
        for name in shown:
            assert f'>{name}</a>' in page or f'>{name}</td>' in page or name in page, (proj, name)
        for name in hidden:
            assert name not in page, (proj, name)
    page = request('items.php')
    for name in ('Paper', 'Soap', 'Chlorine'):
        assert name in page
    print('PASS: the items list separates projects and "No project"')

    # ---- new items are filed under the chosen project ----
    request('item_form.php', post={'ItemName': 'Bucket', 'ItemNameLocal': '', 'Description': '',
                                   'Unit': 'pcs', 'Office': 'main', 'ProjectID': '2'})
    assert one("SELECT ProjectID FROM Items WHERE ItemName='Bucket'") == 2
    request('item_form.php', post={'ItemName': 'Free stock', 'ItemNameLocal': '', 'Description': '',
                                   'Unit': 'pcs', 'Office': 'main', 'ProjectID': 'none'})
    assert one("SELECT ProjectID FROM Items WHERE ItemName='Free stock'") is None
    request('item_form.php', post={'ItemName': 'Forged', 'ItemNameLocal': '', 'Description': '',
                                   'Unit': 'pcs', 'Office': 'main', 'ProjectID': '999'})
    assert one("SELECT ProjectID FROM Items WHERE ItemName='Forged'") is None, 'a forged project id must not stick'
    print('PASS: items are filed under the chosen project; forged ids fall back to No project')

    # ---- a GRN holds only its own project's items ----
    request('receipt_form.php', get={'office': 'main'}, post={'GRNNumber': 'GRN-NEW1', 'ReceiptDate': '2026-09-01', 'Supplier': '',
                                      'GroupName': '', 'ProjectID': '1',
                                      'item_id': ['1'], 'quantity': ['5']})
    assert one("SELECT ProjectID FROM StockReceipts WHERE GRNNumber='GRN-NEW1'") == 1
    assert one("SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ReceiptID=(SELECT ReceiptID FROM StockReceipts WHERE GRNNumber='GRN-NEW1')") == 5
    out = request('receipt_form.php', get={'office': 'main'}, post={'GRNNumber': 'GRN-MIX', 'ReceiptDate': '2026-09-01', 'Supplier': '',
                                            'GroupName': '', 'ProjectID': '1',
                                            'item_id': ['1', '3'], 'quantity': ['5', '5']})
    assert 'different project' in out, 'mixing projects on one GRN must be refused'
    assert one("SELECT COUNT(*) FROM StockReceipts WHERE GRNNumber='GRN-MIX'") == 0
    print('PASS: a GRN is filed under one project and refuses another project\'s items')

    # ---- a stock card holds only its own project's items, balances separate ----
    request('release_form.php', get={'office': 'main'}, post={'StockCardNo': 'STC-NEW1', 'HFID': '1', 'ReceiverName': 'R',
                                      'ReleaseDate': '2026-09-02', 'StorekeeperName': 'K', 'Subject': '',
                                      'ProjectID': '1', 'item_id': ['1'], 'quantity': ['4']})
    assert one("SELECT ProjectID FROM StockReleases WHERE StockCardNo='STC-NEW1'") == 1
    out = request('release_form.php', get={'office': 'main'}, post={'StockCardNo': 'STC-MIX', 'HFID': '1', 'ReceiverName': 'R',
                                            'ReleaseDate': '2026-09-02', 'StorekeeperName': 'K', 'Subject': '',
                                            'ProjectID': '1', 'item_id': ['2'], 'quantity': ['1']})
    assert 'different project' in out
    assert one("SELECT COUNT(*) FROM StockReleases WHERE StockCardNo='STC-MIX'") == 0
    # project 1: 100 in, 10+4 out -> 86 ; no-project: 50 in, 5 out -> 45
    bal = request('reports.php', {'report': 'balance', 'project': '1'})
    assert 'Paper' in bal and 'Chlorine' not in bal and 'Soap' not in bal
    bal = request('reports.php', {'report': 'balance', 'project': 'none'})
    assert 'Chlorine' in bal and 'Paper' not in bal
    print('PASS: stock cards stay inside their project and balances are per project')

    # ---- editing an old, project-less card never files it under a project ----
    before = sql("SELECT StockCardNo, ReceiverName, ProjectID FROM StockReleases WHERE ReleaseID=2")[0]
    request('release_form.php', get={'id': 2},
            post={'StockCardNo': 'STC-NP', 'HFID': '1', 'ReceiverName': 'R edited',
                  'ReleaseDate': '2026-08-11', 'Subject': '', 'ProjectID': '1',
                  'item_id': ['3'], 'quantity': ['5']})
    after = sql("SELECT StockCardNo, ReceiverName, ProjectID FROM StockReleases WHERE ReleaseID=2")[0]
    assert after[2] is None, 'editing must not move an old card into a project'
    assert after[1] == 'R edited'
    page = request('release_form.php', get={'id': 2})
    assert 'value="No project" disabled' in page
    print('PASS: editing an old card keeps it in "No project"')

    # ---- reports separate by project ----
    dates = request('reports.php', {'report': 'dates', 'from': '2026-08-01', 'to': '2026-08-31', 'project': '1'})
    assert 'GRN-P1' in dates and 'STC-P1' in dates and 'GRN-NP' not in dates and 'STC-NP' not in dates
    dates = request('reports.php', {'report': 'dates', 'from': '2026-08-01', 'to': '2026-08-31', 'project': 'none'})
    assert 'GRN-NP' in dates and 'STC-NP' in dates and 'GRN-P1' not in dates
    byhf = request('reports.php', {'report': 'byhf', 'project': '1'})
    assert 'Main facility' in byhf
    ledger = request('reports.php', {'report': 'item', 'item_id': '1', 'project': '1'})
    assert 'GRN-P1' in ledger and 'STC-P1' in ledger
    refused = request('reports.php', {'report': 'item', 'item_id': '3', 'project': '1'})
    assert 'GRN-NP' not in refused
    print('PASS: every report can be limited to one project or to "No project"')

    # ---- details and print show the project ----
    view = request('release_view.php', {'id': 1})
    assert 'First project' in view
    prnt = request('release_print.php', {'id': 1})
    assert 'First project' in prnt
    assert '>Project</th>' not in request('release_print.php', {'id': 2}), 'a project-less card must print no project row'
    print('PASS: details pages and printed copies name the project')

    # ---- imports file only NEW documents under a project ----
    csv = app / 'cards.csv'
    csv.write_text('StockCardNo,HFID,ItemID,Quantity\nSTC-P1,1,1,2\nIMPORTED,1,1,2\n')
    request('import.php', get={'type': 'card'},
            post={'office': 'main', 'project_id': '2', 'printed_subject': '', 'storekeeper_name': ''},
            files={'import_file': {'name': 'cards.csv', 'tmp_name': str(csv),
                                   'error': 0, 'size': csv.stat().st_size}})
    assert one("SELECT ProjectID FROM StockReleases WHERE StockCardNo='IMPORTED'") == 2
    assert one("SELECT ProjectID FROM StockReleases WHERE StockCardNo='STC-P1'") == 1, 'import must not move an existing card'
    assert one("SELECT ProjectID FROM StockReleases WHERE StockCardNo='STC-NP'") is None
    print('PASS: an import files only newly created documents under a project')

    # ---- a project with records cannot be deleted ----
    out = request('projects.php', post={'delete_project': '1'})
    assert one("SELECT COUNT(*) FROM Projects WHERE ProjectID=1") == 1
    request('projects.php', post={'delete_project': '2'})
    assert one("SELECT COUNT(*) FROM Projects WHERE ProjectID=2") == 1, 'project 2 now holds an imported card'
    request('projects.php', post={'delete_project': '3'})
    assert one("SELECT COUNT(*) FROM Projects WHERE ProjectID=3") == 0, 'an empty project must be deletable'
    print('PASS: a project that still holds records cannot be deleted; an empty one can')

    error_log = app / 'logs/php-error.log'
    if error_log.exists():
        unexpected = [line for line in error_log.read_text().splitlines()
                      if 'Deprecated:' not in line]
        assert not unexpected, '\n'.join(unexpected)
    print('All project regression checks passed; bundled database untouched.')
