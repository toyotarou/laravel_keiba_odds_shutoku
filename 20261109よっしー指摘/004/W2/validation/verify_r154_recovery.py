"""Verify queue idempotence against parallel PHP workers and a shared local SQLite database.
Supply the PHP executable and the submitted controller with both distribution patches applied.
"""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from tempfile import TemporaryDirectory
import json
import sqlite3
import subprocess
import sys
import time

root=Path(__file__).resolve().parent
php=sys.argv[1]
controller=Path(sys.argv[2]).resolve()
if not controller.is_file():
    sys.exit("Pass the controller with both distribution patches applied as the second argument.")
with TemporaryDirectory() as td:
    dbpath=Path(td)/'worker.sqlite'
    db=sqlite3.connect(dbpath,isolation_level=None)
    db.executescript("""
    PRAGMA journal_mode=WAL;
    CREATE TABLE baganriki_snapshots(id INTEGER PRIMARY KEY,race_id TEXT,sales_close_at TEXT);
    CREATE TABLE baganriki_live_states(snapshot_id INTEGER PRIMARY KEY,active_count INTEGER,live_close TEXT,
      state_revision INTEGER DEFAULT 0,response_revision INTEGER DEFAULT 0,publication_revision INTEGER DEFAULT 0,updated_at TEXT);
    CREATE TABLE baganriki_ai_attempts(snapshot_id INTEGER,role TEXT,attempt_no INTEGER,token TEXT UNIQUE,status TEXT,
      reserved_at TEXT,received_at TEXT,complete INTEGER,raw_response TEXT,response_hash TEXT,failure_reason TEXT,
      UNIQUE(snapshot_id,role,attempt_no));
    CREATE TABLE baganriki_audit_events(race_id TEXT,snapshot_id INTEGER,kind TEXT,happened_at TEXT,old_revision INTEGER,
      new_revision INTEGER,diagnostics TEXT,related_id TEXT);
    CREATE TABLE t_horse_odds_finder_races(date TEXT,kaisuu TEXT,basho TEXT,day TEXT,race INTEGER,start_time TEXT);
    CREATE TABLE provider_stub(token TEXT);
    """)
    for i in range(1,8):
        db.execute("INSERT INTO baganriki_snapshots VALUES(?,?,?)",(i,f'20261104-4-05-07-{i:02d}','2026-11-04 15:58:00.000000'))
        db.execute("INSERT INTO baganriki_live_states(snapshot_id,active_count) VALUES(?,15)",(i,))
        db.execute("INSERT INTO t_horse_odds_finder_races VALUES('2026-11-04','4','05','07',?,'16:00')",(i,))

    def call(cmd,**fields):
        reply=subprocess.run([str(php),str(root/'r154_worker.php'),str(dbpath),json.dumps(dict(cmd=cmd,**fields)),str(controller)],
                             text=True,capture_output=True)
        if reply.returncode: raise RuntimeError(reply.stderr+reply.stdout)
        return json.loads(reply.stdout)
    def together(cmd,n=2,**fields):
        start=time.time()+0.18
        with ThreadPoolExecutor(n) as pool:
            futures=[pool.submit(call,cmd,start=start,**fields) for _ in range(n)]
            return [f.result() for f in futures]
    checks=0
    def ck(label,condition):
        global checks
        if not condition: raise AssertionError(label)
        checks+=1
    def codes(replies): return sorted(r['code'] for r in replies)
    def provider_count(token):
        return db.execute('SELECT count(*) FROM provider_stub WHERE token=?',(token,)).fetchone()[0]
    def revision(race):
        return db.execute('SELECT response_revision FROM baganriki_live_states WHERE snapshot_id=?',(race,)).fetchone()[0]
    def reserve(race,role,slot,offset=0):
        rs=together('reserve',race=race,role=role,slot=slot,offset=offset)
        ck('only one reservation',codes(rs)==['OK','SLOT_TAKEN'])
        return next(r['token'] for r in rs if r['ok'])
    for role in ['1st','2nd']:
        one=reserve(1,role,0)
        ck('no premature correction',call('fix',race=1,role=role)['code']=='FIRST_NOT_RECEIVED')
        ck('receive before send refused',call('receive',token=one,raw='before',complete=True)['code']=='NOT_SENT')
        kept=call('resume',race=1)
        ck('restart before provider keeps original reservation',kept['reserved_kept']==[role+'#0'])
        ck('concurrent sends have just one winner',codes(together('send',3,token=one))==['ALREADY_SENT','ALREADY_SENT','OK'])
        before=revision(1)
        received=together('receive',3,token=one,raw=role+' original',complete=True)
        ck('response stored once across workers',codes(received)==['IDEMPOTENT','IDEMPOTENT','OK'] and revision(1)==before+1)
        ck('different bytes cannot overwrite',call('receive',token=one,raw='changed',complete=True)['code']=='RESPONSE_MISMATCH')
        ck('changed completeness cannot overwrite',call('receive',token=one,raw=role+' original',complete=False)['code']=='RESPONSE_MISMATCH')
        ck('failed/late send cannot overwrite receipt',call('fail',token=one,unknown=True)['code']=='TERMINAL' and call('send',token=one)['code']=='TERMINAL')
        ck('one provider call per original',provider_count(one)==1)
        ck('genuine correction allowed',call('fix',race=1,role=role)['ok'])
        fix=reserve(1,role,1,offset=100)
        ck('one authorized correction',codes(together('send',token=fix,offset=100))==['ALREADY_SENT','OK'])
        ck('correction stored once',codes(together('receive',token=fix,raw=role+' fix',complete=True,offset=100))==['IDEMPOTENT','OK'])
        ck('cannot re-open closed correction',call('fix',race=1,role=role)['code']=='SLOT_TAKEN' and call('send',token=fix)['code']=='TERMINAL' and provider_count(fix)==1)
        ck('no third slot',call('reserve',race=1,role=role,slot=2)['code']=='INVALID_ATTEMPT')
    for i,role,terminal in [(2,'1st','UNKNOWN_RESULT'),(3,'2nd','FAILED')]:
        token=reserve(i,role,0)
        ck('provider authorized once before stop',call('send',token=token)['ok'])
        before=revision(i)
        if i==2:
            ck('restart closes uncertain sends',call('resume',race=i)['unknown']==[role+'#0'])
        else:
            ck('explicit failure closes sent slot',call('fail',token=token,unknown=False)['status']==terminal)
        for _ in range(2):
            rs=call('resume',race=i)
            ck('repeated restart keeps terminal result',not rs['unknown'] and rs['slots'][0]['status']==terminal)
        ck('terminal rejects late receipt and send',call('send',token=token)['code']=='TERMINAL' and
           call('receive',token=token,raw='late',complete=True)['code']=='TERMINAL' and call('fix',race=i,role=role)['code']=='FIRST_FAILED')
        ck('terminal replay has no revision or call side effect',revision(i)==before+1 and provider_count(token)==1)
    ck('one mock invocation per sent token',db.execute('SELECT COUNT(*),COUNT(DISTINCT token) FROM provider_stub').fetchone()==(6,6))
    ck('accepted responses once per slot',db.execute("SELECT count(*) FROM baganriki_audit_events WHERE kind='QUEUE_RECEIVED'").fetchone()[0]==4)
    print(f'PASS {checks} worker checks, 2 roles, 3 fixture races, 6 distinct provider authorizations, 4 distinct successful receipts, fresh processes per action, local SQLite; external AI calls 0')
    token=reserve(4,'1st',0)
    ck('one in-flight request',call('send',token=token)['ok'])
    before=revision(4)
    workers=together('resume',race=4,offset=650000000,gate=td)
    unknowns=[w['unknown'] for w in workers]
    events=db.execute("SELECT count(*) FROM baganriki_audit_events WHERE snapshot_id=4 AND kind='QUEUE_UNKNOWN_RESULT'").fetchone()[0]
    print('simultaneous deadline sweeps: newly recovered per worker',unknowns,'DB event count',events,'revision delta',revision(4)-before,'mock calls',provider_count(token))
    ck('only the sweep that performed the transition claims it',sorted(unknowns)==[[],['1st#0']])
    ck('single mutation and authorization',events==1 and before+1==revision(4) and provider_count(token)==1)
    for race,cmd in [(5,'receive'),(6,'fail')]:
        token=reserve(race,'1st',0)
        ck('in flight before recovery scan',call('send',token=token)['ok'])
        gate=Path(td)/str(race)
        gate.mkdir()
        with ThreadPoolExecutor(1) as pool:
            scan=pool.submit(call,'resume',race=race,offset=650000000,gate=str(gate))
            limit=time.monotonic()+5
            while not list(gate.glob('read-*')):
                if time.monotonic()>limit: raise RuntimeError('scan did not read pending attempt')
                time.sleep(0.01)
            if cmd=='receive':
                other=call('receive',token=token,raw='already returned',complete=True)
                status='RECEIVED'
            else:
                other=call('fail',token=token,unknown=False)
                status='FAILED'
            (gate/'read-release').touch()
            result=scan.result()
        ck('the other transaction completed',other['ok'])
        ck('a settled request is not claimed as newly unknown',result['unknown']==[] and result['slots'][0]['status']==status)
        ck('settled request never resent',provider_count(token)==1 and call('send',token=token)['code']=='TERMINAL')
    gate=Path(td)/'two-roles'
    gate.mkdir()
    for role in ['1st','2nd']:
        token=reserve(7,role,0)
        ck('both roles in flight',call('send',token=token)['ok'])
    scans=together('resume',race=7,offset=650000000,gate=str(gate))
    labels=sorted(label for scan in scans for label in scan['unknown'])
    ck('two distinct requests reported once each across both workers',labels==['1st#0','2nd#0'])
    ck('two distinct unknown events',db.execute("SELECT count(*) FROM baganriki_audit_events WHERE snapshot_id=7 AND kind='QUEUE_UNKNOWN_RESULT'").fetchone()[0]==2)
    print(f'PASS {checks} worker checks overall across seven simulated races, including concurrent deadline recovery')
