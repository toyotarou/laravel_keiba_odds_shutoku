"""Run the PHP gate, publication and historical-read regressions for R15.5.
Arguments: PHP CLI executable (PDO SQLite and mbstring) and patched AiController.php.
"""
from pathlib import Path
from tempfile import TemporaryDirectory
import argparse
import json
import sqlite3
import subprocess
import zipfile

from verify_r157_receive import install_clock_fixtures

HERE = Path(__file__).resolve().parent
def run(php, controller):
    with TemporaryDirectory() as directory:
        base=Path(directory)
        samples=base/'normal'
        with zipfile.ZipFile(HERE/'synthetic_W2_examples.zip') as example:
            example.extractall(samples)
        other=base/'other'
        subprocess.run([php,'-n',str(HERE/'verify_w2.php'),'--examples',str(other),'--second-fixture'],
                       check=True,capture_output=True)
        subprocess.run([php,str(HERE/'r155_live.php'),str(controller),str(samples),str(other)],check=True)
        dbpath=base/'worker.sqlite'
        db=sqlite3.connect(dbpath,isolation_level=None)
        db.executescript("""
        CREATE TABLE baganriki_snapshots(id INTEGER PRIMARY KEY,race_id TEXT,sales_close_at TEXT,release_version TEXT,
          frozen_nums TEXT DEFAULT '[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16]');
        CREATE TABLE baganriki_live_states(snapshot_id INTEGER PRIMARY KEY,active_count INTEGER,live_close TEXT,cancellations TEXT DEFAULT '[]',
          state_revision INTEGER DEFAULT 0,response_revision INTEGER DEFAULT 0,publication_revision INTEGER DEFAULT 0,updated_at TEXT);
        CREATE TABLE baganriki_ai_attempts(snapshot_id INTEGER,role TEXT,attempt_no INTEGER,token TEXT UNIQUE,status TEXT,
          reserved_at TEXT,received_at TEXT,complete INTEGER,raw_response TEXT,response_hash TEXT,failure_reason TEXT,
          UNIQUE(snapshot_id,role,attempt_no));
        CREATE TABLE baganriki_audit_events(race_id TEXT,snapshot_id INTEGER,kind TEXT,happened_at TEXT,old_revision INTEGER,
          new_revision INTEGER,diagnostics TEXT,related_id TEXT);
        CREATE TABLE t_horse_odds_finder_races(date TEXT,kaisuu TEXT,basho TEXT,day TEXT,race INTEGER,start_time TEXT);
        CREATE TABLE provider_stub(token TEXT);
        """)
        version=json.loads((HERE.parent/'config/w2_contract.json').read_text())['prompt_version']
        for i in range(1,6):
            db.execute('INSERT INTO baganriki_snapshots(id,race_id,sales_close_at,release_version) VALUES(?,?,?,?)',
                       (i,f'20261104-4-05-07-{i:02d}','2026-11-04 15:59:00.000000',version))
            db.execute('INSERT INTO baganriki_live_states(snapshot_id,active_count) VALUES(?,16)',(i,))
            db.execute("INSERT INTO t_horse_odds_finder_races VALUES('2026-11-04','4','05','07',?,'16:00')",(i,))
        install_clock_fixtures(db)
        def call(cmd,**fields):
            command=[php,str(HERE/'r154_worker.php'),str(dbpath),json.dumps(dict(cmd=cmd,**fields)),str(controller)]
            return json.loads(subprocess.run(command,text=True,check=True,capture_output=True).stdout)
        def state(i,value):
            db.execute('UPDATE t_horse_odds_finder_races SET start_time=? WHERE race=?',(value,i))
        def value(sql,*args): return db.execute(sql,args).fetchone()[0]
        checks=0
        def ck(result,label):
            nonlocal checks
            if not result: raise AssertionError(label)
            checks+=1
        def reserve(i):
            result=call('reserve',race=i,role='1st',slot=0)
            ck(result['code']=='OK','reserve pre-start')
            return result['token']
        state(1,'XXX')
        ck(call('reserve',race=1,role='1st',slot=0)['code']=='RACE_STARTED','already started')
        ck(value('SELECT count(*) FROM baganriki_ai_attempts WHERE snapshot_id=1')==0,'no paid slot')
        token=reserve(2);state(2,'XXX')
        ck(call('send',token=token)['code']=='RACE_STARTED','started between reserve and send')
        ck(value('SELECT count(*) FROM provider_stub WHERE token=?',token)==0,'no invocation after start')
        ck(value('SELECT status FROM baganriki_ai_attempts WHERE token=?',token)=='RESERVED','send state did not advance')
        token=reserve(3)
        ck(call('send',token=token)['ok'],'authorized once before start');state(3,'XXX')
        ck(call('receive',token=token,raw='late',complete=True)['code']=='RACE_STARTED','started during external call')
        ck(value('SELECT response_hash FROM baganriki_ai_attempts WHERE token=?',token) is None,'no late adoption')
        ck(call('resume',race=3,offset=650000000)['unknown']==['1st#0'],'cleanup remains possible')
        ck(value('SELECT count(*) FROM provider_stub WHERE token=?',token)==1,'cleanup or late receipt did not resend')
        token=reserve(4);ck(call('send',token=token)['ok'],'sent for historical case')
        ck(call('receive',token=token,raw='saved result',complete=True)['ok'],'saved before start')
        state(4,'XXX')
        ck(call('receive',token=token,raw='saved result',complete=True)['code']=='IDEMPOTENT','already saved receipt')
        ck(call('receive',token=token,raw='other',complete=True)['code']=='RESPONSE_MISMATCH','no overwrite')
        ck(value('SELECT count(*) FROM baganriki_audit_events WHERE snapshot_id=4 AND kind=?','QUEUE_RECEIVED')==1,'one receipt')
        db.execute('DELETE FROM t_horse_odds_finder_races WHERE race=5')
        ck(call('reserve',race=5,role='2nd',slot=0)['code']=='RACE_STATUS_UNAVAILABLE','official row unavailable')
        ck(value('SELECT count(*) FROM provider_stub')==2,'external provider stub final count')
        db.close()
        print(f'PASS {checks} persistent SQLite/PHP gate and replay checks with status changes between operations; external AI calls 0.')

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php');parser.add_argument('patched_controller',type=Path)
    args=parser.parse_args();run(args.php,args.patched_controller.resolve())
