"""Run the R15.6 sender and single-role regressions against the fully patched controller."""
from pathlib import Path
from tempfile import TemporaryDirectory
import argparse
import json
import sqlite3
import subprocess

from verify_r157_receive import record_live_change,install_clock_fixtures

HERE = Path(__file__).resolve().parent

def roster_gates(php, controller, tmp, observe=False):
    db_path = Path(tmp) / 'roster.sqlite'
    db = sqlite3.connect(db_path, isolation_level=None)
    db.executescript("""
        CREATE TABLE baganriki_snapshots(id INTEGER PRIMARY KEY,race_id TEXT,release_version TEXT,
          sales_close_at TEXT,frozen_nums TEXT,target_count INTEGER,quota_1st TEXT);
        CREATE TABLE baganriki_live_states(snapshot_id INTEGER PRIMARY KEY,cancellations TEXT,active_count INTEGER,
          live_target_count INTEGER,live_quota TEXT,live_close TEXT,state_revision INTEGER DEFAULT 0,
          response_revision INTEGER DEFAULT 0,publication_revision INTEGER DEFAULT 0,latest_publication_id INTEGER,updated_at TEXT);
        CREATE TABLE t_horse_odds_finder_races(date TEXT,kaisuu TEXT,basho TEXT,day TEXT,race INTEGER,start_time TEXT);
        CREATE TABLE baganriki_ai_attempts(snapshot_id INTEGER,role TEXT,attempt_no INTEGER,token TEXT UNIQUE,status TEXT,
          reserved_at TEXT,received_at TEXT,complete INTEGER,raw_response TEXT,response_hash TEXT,failure_reason TEXT,
          UNIQUE(snapshot_id,role,attempt_no));
        CREATE TABLE baganriki_audit_events(race_id TEXT,snapshot_id INTEGER,kind TEXT,happened_at TEXT,old_revision INTEGER,
          new_revision INTEGER,diagnostics TEXT,related_id TEXT);
        CREATE TABLE provider_stub(token TEXT);
    """)
    version = json.loads((HERE.parent/'config/w2_contract.json').read_text())['prompt_version']
    count = 0

    def assert_equal(actual, expected, label):
        nonlocal count
        if actual != expected:
            raise ValueError(f'{label}: {actual!r} != {expected!r}')
        count += 1

    def invoke(cmd, **params):
        wire = json.dumps({'cmd':cmd, **params})
        result = subprocess.run([php,str(HERE/'r154_worker.php'),str(db_path),wire,controller],
                                check=True,text=True,capture_output=True)
        return json.loads(result.stdout)

    def nrows(table, clause='', args=()):
        return db.execute(f'SELECT count(*) FROM {table} '+clause,args).fetchone()[0]

    for race in range(1,7):
        db.execute('INSERT INTO baganriki_snapshots VALUES(?,?,?,?,?,?,?)',
                   (race,f'20261104-4-05-07-{race:02d}',None if race==6 else version,
                    '2026-11-04 15:59:00.000000','[1,2,3]',3,'{}'))
        db.execute("INSERT INTO t_horse_odds_finder_races VALUES('2026-11-04','4','05','07',?,'16:00')",(race,))
        if race not in (1,6):
            db.execute("INSERT INTO baganriki_live_states(snapshot_id,cancellations,active_count) VALUES(?,'[]',3)",(race,))
    install_clock_fixtures(db)
    for role in ['1st','2nd']:
        missing = invoke('reserve',race=1,role=role,slot=0)
        if observe:
            print(f'old reservation with no live row: {role} {missing["code"]}, '
                  f'live rows {nrows("baganriki_live_states","WHERE snapshot_id=1")}')
            continue
        assert_equal(missing['code'],'LIVE_STATE_UNAVAILABLE',role+' missing live row')
    if observe:
        db.close()
        return
    assert_equal(nrows('baganriki_live_states','WHERE snapshot_id=1'),0,'unavailable row must stay missing')
    assert_equal(nrows('baganriki_ai_attempts','WHERE snapshot_id=1'),0,'no reservation from missing data')
    record_live_change(db,2,{'cancellations':{'前':[],'後':[1,2,3]}})
    db.execute("UPDATE baganriki_live_states SET state_revision=1,cancellations='[1,2,3]',active_count=0 WHERE snapshot_id=2")
    for role in ['1st','2nd']:
        assert_equal(invoke('reserve',race=2,role=role,slot=0)['code'],'ALL_CANCELLED',role+' cancelled before reservation')
    for race,change,code in [
        (3,'DELETE FROM baganriki_live_states WHERE snapshot_id=3','LIVE_STATE_UNAVAILABLE'),
        (4,"UPDATE baganriki_live_states SET cancellations='[1,1]' WHERE snapshot_id=4",'LIVE_STATE_UNAVAILABLE'),
        (5,"UPDATE baganriki_live_states SET cancellations='[2]',active_count=2 WHERE snapshot_id=5",'OK')]:
        tokens=[invoke('reserve',race=race,role=role,slot=0)['token'] for role in ('1st','2nd')]
        if race==5:
            record_live_change(db,5,{'cancellations':{'前':[],'後':[2]}})
            db.execute('UPDATE baganriki_live_states SET state_revision=1 WHERE snapshot_id=5')
        db.execute(change)
        for token in tokens:
            assert_equal(invoke('send',token=token)['code'],code,'change following reservation')
            assert_equal(nrows('provider_stub','WHERE token=?',(token,)),int(code=='OK'),'permit counter')
    assert_equal(invoke('reserve',race=6,role='1st',slot=0)['code'],'OK','historical initialization')
    assert_equal(nrows('baganriki_live_states','WHERE snapshot_id=6'),1,'historical live row initialized')
    db.close()
    print(f'PASS {count} persistent SQLite roster checks, including missing W2 rows, changes after reservation and older release initialization.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php', help='PHP CLI with mbstring')
    parser.add_argument('controller', type=Path, help='fully patched AiController.php')
    parser.add_argument('--observe-old-reservation',action='store_true',help='record the pre-fix missing-state behavior')
    args = parser.parse_args()
    controller = str(args.controller.resolve())
    with TemporaryDirectory() as tmp:
        roster_gates(args.php,controller,tmp,args.observe_old_reservation)
        if args.observe_old_reservation:
            return
        subprocess.run([args.php, str(HERE/'verify_w2.php'), '--examples', tmp, '--sdk-fixture'],
                       stdout=subprocess.DEVNULL, check=True)
        for script in ['r156_sdk.php','r156_roles.php']:
            subprocess.run([args.php, str(HERE/script), controller, tmp], check=True)

if __name__ == '__main__':
    main()
