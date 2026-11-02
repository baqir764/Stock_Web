"""Isolated regression test: python3 tests/printed_subject.py [path/to/php]

Requires PHP with PDO SQLite. Only schema is read from the bundled database;
all requests and writes run in a temporary app copy with synthetic records.

Covers the printed subject line on a stock card: editable when creating a card
AND when editing an existing or old one, saved to the row, shown on the details
page and the printed copy, kept as typed when a save fails validation, falling
back to the classic line when blank — while the storekeeper name stays
protected and bulk imports never rewrite an existing card's subject.
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
CLASSIC = "Stationery adn Hygiene for the month of (July,Aug,Sep)2026"


def php_h(text):
    """Mirror of h() in includes/functions.php — htmlspecialchars(ENT_QUOTES)."""
    return (text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
                .replace('"', '&quot;').replace("'", '&#039;'))

with tempfile.TemporaryDirectory(prefix="stock-subject-") as tmp:
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
        # Always exercise the pre-feature schema, even if the bundled DB changes.
        if "Subject" in [r[1] for r in db.execute("PRAGMA table_info(StockReleases)")]:
            db.execute("ALTER TABLE StockReleases DROP COLUMN Subject")
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (1,'tester','unused','Test User','admin','main')")
        db.execute("INSERT INTO Users (UserID,Username,PasswordHash,FullName,Role,Office) VALUES (2,'staff','unused','Staff User','staff','provincial')")
        db.execute("INSERT INTO HealthFacilities (HFID,HFName,Office) VALUES (1,'Main facility','main'),(2,'Provincial facility','provincial')")
        db.execute("INSERT INTO Items (ItemID,ItemName,Unit,Office) VALUES (1,'Paper','pack','main'),(2,'Soap','bar','provincial')")
        db.execute("INSERT INTO StockReceipts (ReceiptID,GRNNumber,Office) VALUES (1,'GRN-M','main'),(2,'GRN-P','provincial')")
        db.execute("INSERT INTO StockReceiptDetails (ReceiptID,ItemID,Quantity) VALUES (1,1,1000),(2,2,1000)")
        db.execute("INSERT INTO StockReleases (ReleaseID,StockCardNo,HFID,Office) VALUES (1,'LEGACY',1,'main')")
        db.execute("INSERT INTO StockReleaseDetails (ReleaseID,ItemID,Quantity) VALUES (1,1,1)")

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
if ($req['page'] === 'helpers') {
    require __DIR__ . '/includes/functions.php';
    stockapp_migrate(db());
    stockapp_migrate(db());
    echo json_encode([
        last_release_subject('main'), last_release_subject('provincial'),
        last_release_subject(null), release_subject(null), release_subject('   '),
        release_subject('0'), release_subject('Custom')
    ]);
} else {
    require __DIR__ . '/' . $req['page'];
}
''')

    def request(page, get=None, post=None, files=None, user=1):
        req = dict(page=page, get=get or {}, post=post or {}, files=files or {}, user=user)
        if post is not None:
            req['method'] = 'POST'
            req['post']['csrf_token'] = 'test-token'
        result = subprocess.run([PHP, 'runner.php', json.dumps(req)], cwd=app,
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stderr + result.stdout
        assert 'Fatal error' not in result.stdout, result.stdout
        return result.stdout

    def subject(card):
        with sqlite3.connect(database) as db:
            row = db.execute('SELECT Subject FROM StockReleases WHERE StockCardNo=?', (card,)).fetchone()
        assert row, f'Missing card {card}'
        return row[0]

    def card_id(card):
        with sqlite3.connect(database) as db:
            return db.execute('SELECT ReleaseID FROM StockReleases WHERE StockCardNo=?', (card,)).fetchone()[0]

    def storekeeper(card):
        with sqlite3.connect(database) as db:
            return db.execute('SELECT StorekeeperName FROM StockReleases WHERE StockCardNo=?', (card,)).fetchone()[0]

    def save(card, text, office='main', existing=None, user=1):
        office_id = 1 if office == 'main' else 2
        return request('release_form.php',
                       get={'id': existing} if existing else {'office': office},
                       post=dict(StockCardNo=card, HFID=str(office_id), ReceiverName='Receiver',
                                 ReleaseDate='2026-09-20', StorekeeperName='Storekeeper', Subject=text,
                                 item_id=[office_id], quantity=[1]), user=user)

    helpers = json.loads(request('helpers'))
    assert helpers == [None, None, None, CLASSIC, CLASSIC, '0', 'Custom'], helpers
    assert subject('LEGACY') is None
    with sqlite3.connect(database) as db:
        cols = [r for r in db.execute('PRAGMA table_info(StockReleases)') if r[1] == 'Subject']
        assert len(cols) == 1 and cols[0][2] == 'TEXT'
    print('PASS: migration is idempotent, legacy values stay NULL, fallbacks are correct')

    assert f'value="{CLASSIC}"' in request('release_form.php', get={'office': 'main'})
    custom = 'September <supplies> & "hygiene"'
    escaped = 'September &lt;supplies&gt; &amp; &quot;hygiene&quot;'
    save('NEW-MAIN', custom)
    assert subject('NEW-MAIN') == custom
    assert f'value="{escaped}"' in request('release_form.php', get={'office': 'main'})
    assert escaped not in request('release_form.php', get={'office': 'provincial'})
    save('NEW-PROV', 'Provincial September', office='provincial', user=2)
    # Staff cannot select another office via the URL.
    staff = request('release_form.php', get={'office': 'main'}, user=2)
    assert 'value="Provincial September"' in staff and escaped not in staff
    no_office = request('release_form.php')
    assert f'value="{CLASSIC}"' in no_office and escaped not in no_office
    print('PASS: creation stores subjects, defaults are office-scoped')

    # Existing AND old cards: the printed subject is editable, the edit is
    # saved, and the details page + printed copy both follow it. The
    # storekeeper name stays protected (forward-only) on the same form.
    edits = {'NEW-MAIN': 'Corrected <September> supplies',
             'LEGACY': 'Legacy card now carries its own line'}
    for card, shown_before in [('NEW-MAIN', escaped), ('LEGACY', CLASSIC)]:
        rid = card_id(card)
        form = request('release_form.php', get={'id': rid})
        # Editable box pre-filled with what this card currently prints — an old
        # card with no stored Subject shows the classic line.
        assert 'name="Subject"' in form
        assert f'value="{shown_before}"' in form
        assert f'value="{shown_before}" disabled' not in form
        # The storekeeper box on an existing card stays read-only.
        assert 'name="StorekeeperName"' not in form
        keeper_before = storekeeper(card)
        save(card, edits[card], existing=rid)
        assert subject(card) == edits[card]
        # A posted StorekeeperName is still ignored when editing a card.
        assert storekeeper(card) == keeper_before
        shown_after = php_h(edits[card])
        assert shown_after in request('release_print.php', get={'id': rid})
        assert shown_after in request('release_view.php', get={'id': rid})
        assert shown_before not in request('release_print.php', get={'id': rid})
        assert shown_before not in request('release_view.php', get={'id': rid})
    print('PASS: existing and old cards edit their subject, view/print follow, storekeeper stays protected')

    # New card: validation redisplay keeps the typed text, blank falls back.
    invalid = save('', 'Retain <typed> subject')
    assert 'value="Retain &lt;typed&gt; subject"' in invalid
    save('BLANK', '   ')
    assert subject('BLANK') == CLASSIC
    print('PASS: new-card validation redisplay and blank-subject fallback')

    # Existing card: same two rules — blanking the box restores the classic
    # line, and a failed save shows the text just typed without writing it.
    legacy_id = card_id('LEGACY')
    save('LEGACY', '   ', existing=legacy_id)
    assert subject('LEGACY') == CLASSIC
    assert f'value="{CLASSIC}"' in request('release_form.php', get={'id': legacy_id})
    invalid_existing = request('release_form.php', get={'id': legacy_id},
                               post=dict(StockCardNo='', HFID='1', ReceiverName='Receiver',
                                         ReleaseDate='2026-09-20', StorekeeperName='Ignored',
                                         Subject='Retain <typed> subject', item_id=[1], quantity=[1]))
    assert 'value="Retain &lt;typed&gt; subject"' in invalid_existing
    assert subject('LEGACY') == CLASSIC
    print('PASS: existing-card validation redisplay and blank-subject fallback')

    # A client that posts no Subject at all (stale cached page from before the
    # box became editable) must not wipe the subject the card already has.
    no_field = dict(StockCardNo='LEGACY', HFID='1', ReceiverName='Receiver',
                    ReleaseDate='2026-09-20', StorekeeperName='Ignored', item_id=[1], quantity=[1])
    request('release_form.php', get={'id': legacy_id}, post=no_field)
    assert subject('LEGACY') == CLASSIC
    print('PASS: a post without the Subject field leaves the stored subject alone')

    csv = app / 'cards.csv'
    csv.write_text('StockCardNo,HFID,ItemID,Quantity\nNEW-MAIN,1,1,2\nLEGACY,1,1,2\nIMPORTED,1,1,2\n')
    # Snapshots taken AFTER the edits above: an import must leave both the
    # subjects and the storekeeper names of existing cards exactly as they are.
    subjects_before = {c: subject(c) for c in ('NEW-MAIN', 'LEGACY')}
    keepers_before = {c: storekeeper(c) for c in ('NEW-MAIN', 'LEGACY')}
    output = request('import.php', get={'type': 'card'},
                     post={'office': 'main', 'printed_subject': 'Imported subject'},
                     files={'import_file': {'name': 'cards.csv', 'tmp_name': str(csv),
                                            'error': 0, 'size': csv.stat().st_size}})
    assert '1 stock card(s) created, 2 updated' in output, output
    assert subjects_before == {c: subject(c) for c in ('NEW-MAIN', 'LEGACY')}, 'import rewrote an existing subject'
    assert keepers_before == {c: storekeeper(c) for c in ('NEW-MAIN', 'LEGACY')}
    assert subject('IMPORTED') == 'Imported subject'
    assert 'Printed subject (new cards only)' in output
    assert 'Printed subject (new cards only)' not in request('import.php', get={'type': 'grn'})
    print('PASS: mixed import sets Subject only on created cards; existing subjects untouched; GRN UI unchanged')

    error_log = app / 'logs/php-error.log'
    if error_log.exists():
        # PHP 8.4+ deprecates fgetcsv's omitted escape argument in existing import code.
        unexpected = [line for line in error_log.read_text().splitlines()
                      if 'Deprecated:' not in line]
        assert not unexpected, '\n'.join(unexpected)
    print('All printed-subject regression checks passed; bundled database untouched.')
