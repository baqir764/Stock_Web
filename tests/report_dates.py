"""Isolated regression test: python3 tests/report_dates.py [path/to/php]

Requires PHP with PDO SQLite. Only schema is read from the bundled database;
all requests and writes run in a temporary app copy with synthetic records.

Covers the date-based reports on reports.php: the "By Date" period report
(GRNs received, stock cards released, per-item opening/movement/closing),
the date filter on Releases by Facility and the Item Ledger, open-ended
ranges, swapped and invalid dates, office scoping, and the fact that the
Stock Balance tab stays a live all-time snapshot.
"""
import datetime
import json
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv[1] if len(sys.argv) > 1 else "php"

with tempfile.TemporaryDirectory(prefix="stock-dates-") as tmp:
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
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (1,'tester','unused','Test User','admin',NULL)")
        db.execute("INSERT INTO HealthFacilities (HFID,HFName,Office) VALUES (1,'Main facility','main'),(2,'Provincial facility','provincial')")
        db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office) VALUES (1,'Paper','pack','main'),(2,'Soap','bar','main'),(3,'Chlorine','litre','provincial')")
        # GRNs: July, August (two offices), September
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office) VALUES (1,'GRN-A','2026-07-01','Supplier A','main')")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office) VALUES (2,'GRN-B','2026-08-15','Supplier B','main')")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office) VALUES (3,'GRN-C','2026-09-10','Supplier C','main')")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,ReceiptDate,Supplier,Office) VALUES (4,'GRN-P','2026-08-20','Supplier P','provincial')")
        db.execute("INSERT INTO StockReceiptDetails (ReceiptID,ItemID,Quantity) VALUES (1,1,100),(2,1,50),(2,2,20),(3,2,5),(4,3,7)")
        # Stock cards: July, August (two offices), September, and one with NO date
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office) VALUES (1,'STC-1',1,'2026-07-20','main')")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office) VALUES (2,'STC-2',1,'2026-08-20','main')")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office) VALUES (3,'STC-3',1,'2026-09-15','main')")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office) VALUES (4,'STC-OLD',1,NULL,'main')")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,ReleaseDate,Office) VALUES (5,'STC-P',2,'2026-08-25','provincial')")
        db.execute("INSERT INTO StockReleaseDetails (ReleaseID,ItemID,Quantity) VALUES (1,1,10),(2,1,30),(2,2,4),(3,2,1),(4,1,5),(5,3,2)")

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

    def request(get=None):
        req = dict(page='reports.php', get=get or {})
        result = subprocess.run([PHP, 'runner.php', json.dumps(req)], cwd=app,
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stderr + result.stdout
        assert 'Fatal error' not in result.stdout, result.stdout[-2000:]
        return result.stdout

    def movement_rows(html):
        """{item name: (opening, in, out, closing)} from the By Date table."""
        pattern = re.compile(
            r'report=item&item_id=\d+[^"]*">([^<]+)</a></td>\s*<td>[^<]*</td>\s*'
            r'<td>[^<]*</td>\s*'  # Category column
            r'<td class="muted">[^<]*</td>\s*'
            r'<td class="num">([\-\d,.]+)</td>\s*<td class="num">([\-\d,.]+)</td>\s*'
            r'<td class="num">([\-\d,.]+)</td>\s*<td class="num"><strong>([\-\d,.]+)</strong>')
        return {name: tuple(float(v.replace(',', '')) for v in nums)
                for name, *nums in pattern.findall(html)}

    def byhf_counts(html):
        return {name: int(n) for name, n in re.findall(
            r'<td>([^<]+)</td>\s*<td>[^<]*</td>\s*<td class="num">(\d+)</td>', html)}

    def detail_values(html):
        return [v.replace(',', '') for v in re.findall(
            r'<div class="label">[^<]*</div>\s*<div class="value">(?:<strong>)?([^<(]+)', html)]

    AUG = {'from': '2026-08-01', 'to': '2026-08-31'}

    # ---- the By Date tab opens on "this month so far" ----
    today = datetime.date.today()
    page = request({'report': 'dates'})
    assert f'name="from" value="{today.replace(day=1).isoformat()}"' in page, 'default from is not the 1st of this month'
    assert f'name="to" value="{today.isoformat()}"' in page, 'default to is not today'
    assert 'Period:' in page
    print('PASS: By Date opens on this month so far, with the period printed on the page')

    # ---- August, all offices ----
    page = request({'report': 'dates', **AUG})
    for shown in ('GRN-B', 'GRN-P', 'STC-2', 'STC-P'):
        assert shown in page, f'{shown} should be inside the August period'
    for hidden in ('GRN-A', 'GRN-C', 'STC-1', 'STC-3', 'STC-OLD'):
        assert hidden not in page, f'{hidden} should be outside the August period'
    vals = detail_values(page)
    assert vals[0].startswith('77.00'), vals      # 70 (GRN-B) + 7 (GRN-P)
    assert vals[1].startswith('36.00'), vals      # 34 (STC-2) + 2 (STC-P)
    assert vals[2].strip() == '41.00', vals       # net change
    rows = movement_rows(page)
    assert rows['Paper'] == (85.0, 50.0, 30.0, 105.0), rows   # opening counts the undated card
    assert rows['Soap'] == (0.0, 20.0, 4.0, 16.0), rows
    assert rows['Chlorine'] == (0.0, 7.0, 2.0, 5.0), rows
    assert 'Period: 1 Aug 2026 — 31 Aug 2026' in page
    assert 'class="panel no-print"' in page, 'the date form must not print'
    print('PASS: August period lists exactly the in-period GRNs and cards, totals and per-item movement are right')

    # ---- same period, one office only ----
    page = request({'report': 'dates', **AUG, 'office': 'main'})
    assert 'GRN-B' in page and 'STC-2' in page
    assert 'GRN-P' not in page and 'STC-P' not in page and 'Chlorine' not in page
    rows = movement_rows(page)
    # Same as all offices for Paper (all of its activity is main office), and
    # the undated card still counts in the opening balance.
    assert rows['Paper'] == (85.0, 50.0, 30.0, 105.0), rows
    print('PASS: the period respects the office filter')

    # ---- open-ended ranges ----
    page = request({'report': 'dates', 'to': '2026-08-31'})
    assert 'Beginning' in page and 'GRN-A' in page and 'GRN-C' not in page
    assert movement_rows(page)['Paper'][0] == 0.0
    page = request({'report': 'dates', 'from': '2026-09-01'})
    assert 'present' in page and 'GRN-C' in page and 'STC-3' in page and 'GRN-B' not in page
    print('PASS: an empty From means "from the beginning", an empty To means "up to today"')

    # ---- swapped dates are put back the right way round ----
    page = request({'report': 'dates', 'from': '2026-08-31', 'to': '2026-08-01'})
    assert 'wrong way round' in page and 'GRN-B' in page and 'GRN-A' not in page
    print('PASS: swapped dates are swapped back, with a note')

    # ---- Releases by Facility honours the period ----
    counts = byhf_counts(request({'report': 'byhf', **AUG}))
    assert counts.get('Main facility') == 1 and counts.get('Provincial facility') == 1, counts
    counts = byhf_counts(request({'report': 'byhf'}))
    assert counts.get('Main facility') == 4, counts   # STC-1,2,3 and the undated one
    print('PASS: Releases by Facility counts only cards inside the chosen period')

    # ---- Item Ledger honours the period and starts its running balance right ----
    page = request({'report': 'item', 'item_id': '1', 'office': 'main', **AUG})
    assert 'GRN-B' in page and 'STC-2' in page
    assert 'GRN-A' not in page and 'STC-1' not in page and 'STC-OLD' not in page
    assert 'Received in period' in page and 'Balance on 31 Aug 2026' in page
    balances = re.findall(r'<td class="num">([\-\d,.]+)</td>\s*</tr>', page)
    assert balances == ['135.00', '105.00'], balances   # opening 85 + 50, then − 30
    page = request({'report': 'item', 'item_id': '1', 'office': 'main'})
    assert 'STC-OLD' in page, 'the all-time ledger must still include the undated card'
    print('PASS: the ledger filters by period and its running balance starts from the opening balance')

    # ---- an unusable date is ignored, not an error ----
    page = request({'report': 'item', 'item_id': '1', 'office': 'main', 'from': '2026-13-99'})
    assert 'GRN-A' in page and 'STC-OLD' in page
    print('PASS: an invalid date is ignored instead of breaking the report')

    # ---- Stock Balance stays a live all-time snapshot ----
    page = request({'report': 'balance', **AUG})
    assert 'never limited by the dates above' in page
    row = re.search(r'>Paper</a>.*?<td class="num">([\d,]+)</td>\s*<td class="num">([\d,]+)</td>\s*<td class="num"><strong>([\d,\-]+)</strong>', page, re.S)
    assert row and row.groups() == ('150', '45', '105'), row.groups() if row else None
    print('PASS: Stock Balance ignores the dates and stays a live snapshot')

    error_log = app / 'logs/php-error.log'
    if error_log.exists():
        unexpected = [line for line in error_log.read_text().splitlines()
                      if 'Deprecated:' not in line]
        assert not unexpected, '\n'.join(unexpected)
    print('All date-report regression checks passed; bundled database untouched.')
