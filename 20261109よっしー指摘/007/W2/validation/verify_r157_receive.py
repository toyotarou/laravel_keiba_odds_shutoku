"""Exercise the submitted queue and publication code across post-send changes using a shared SQLite database."""
import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
from datetime import datetime, timedelta
from hashlib import sha256
import json
from pathlib import Path
import sqlite3
import subprocess
from tempfile import TemporaryDirectory

HERE = Path(__file__).resolve().parent
ROLES = ('1st','2nd')
WORKER_CLOCK = datetime.fromisoformat('2026-11-04T15:50:00+09:00')
SCHEMA = """
CREATE TABLE baganriki_snapshots(id INTEGER PRIMARY KEY,race_id TEXT,release_version TEXT,
    sales_close_at TEXT,frozen_nums TEXT,target_count INTEGER,quota_1st TEXT,common_json TEXT,snapshot_hash TEXT);
CREATE TABLE baganriki_live_states(snapshot_id INTEGER PRIMARY KEY,active_count INTEGER,cancellations TEXT,
    live_close TEXT,state_revision INTEGER DEFAULT 0,response_revision INTEGER DEFAULT 0,
    publication_revision INTEGER DEFAULT 0,updated_at TEXT);
CREATE TABLE t_horse_odds_finder_races(date TEXT,kaisuu TEXT,basho TEXT,day TEXT,race INTEGER,start_time TEXT);
CREATE TABLE baganriki_ai_attempts(snapshot_id INTEGER,role TEXT,attempt_no INTEGER,token TEXT UNIQUE,status TEXT,
    reserved_at TEXT,received_at TEXT,complete INTEGER,raw_response TEXT,response_hash TEXT,failure_reason TEXT,
    UNIQUE(snapshot_id,role,attempt_no));
CREATE TABLE baganriki_audit_events(race_id TEXT,snapshot_id INTEGER,kind TEXT,happened_at TEXT,old_revision INTEGER,
    new_revision INTEGER,diagnostics TEXT,related_id TEXT);
CREATE TABLE provider_stub(token TEXT);
"""

def dump(obj):
    return json.dumps(obj,ensure_ascii=False,separators=(',',':'))

def require(value,label):
    if not value:
        raise AssertionError(label)

def db_time(t):
    return t.isoformat(sep=' ',timespec='microseconds')[:-6]

def microseconds(delta):
    return delta // timedelta(microseconds=1)

def install_clock_fixtures(db):
    # Minimal clock-only inputs for the isolated persistent worker tests.
    for name in ('common_json','snapshot_hash'):
        db.execute('ALTER TABLE baganriki_snapshots ADD COLUMN '+name+' TEXT')
    for sid,race,version,clock in db.execute('SELECT id,race_id,release_version,sales_close_at FROM baganriki_snapshots').fetchall():
        if version:
            raw=dump({'race_id':race,'prompt_version':version,'time_contract':{'sales_close_at':clock.replace(' ','T')+'+09:00'}})
            db.execute('UPDATE baganriki_snapshots SET common_json=?,snapshot_hash=? WHERE id=?',(raw,sha256(raw.encode()).hexdigest(),sid))

def record_live_change(db, snapshot_id, delta):
    race=db.execute('SELECT race_id FROM baganriki_snapshots WHERE id=?',(snapshot_id,)).fetchone()[0]
    rev=db.execute('SELECT state_revision FROM baganriki_live_states WHERE snapshot_id=?',(snapshot_id,)).fetchone()[0]
    db.execute("INSERT INTO baganriki_audit_events(race_id,snapshot_id,kind,old_revision,new_revision,diagnostics) VALUES(?,?,'R122_LIVE_APPLIED',?,?,?)",
               (race,snapshot_id,rev,rev+1,dump(delta)))

class Trial:
    def __init__(self, php, controller, directory, fixture_kind='--sdk-fixture'):
        self.php = php
        self.controller = controller
        self.directory = directory
        self.path = directory/'queue.sqlite'
        self.db = sqlite3.connect(self.path,isolation_level=None)
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.executescript(SCHEMA)
        self.bootstrap = directory/'bootstrap.php'
        self.bootstrap.write_text("<?php namespace App\\Http\\Controllers { class Controller {} } "
                                  "namespace { function env($key,$default=null){return $default;} require $argv[4]; }")
        subprocess.run([php,str(HERE/'verify_w2.php'),'--examples',str(directory),fixture_kind],
                       check=True,capture_output=True)
        self.data = self.replay('export')['data']
        frozen = json.loads(self.data['roles'][0]['input'])
        self.start = datetime.fromisoformat(frozen['input_freeze_at'])
        self.sales = datetime.fromisoformat(frozen['time_contract']['sales_close_at'])
        self.release = frozen['prompt_version']
        self.nums = self.data['numbers']
        self.rows = {r['role']:r for r in self.data['roles']}

    def replay(self, phase, data=None):
        result = subprocess.run([self.php,str(HERE/'r7_storage_probe.php'),str(self.bootstrap),phase,str(self.directory),str(self.controller)],
                                input=dump(data) if data is not None else None,text=True,capture_output=True,check=True)
        return json.loads(result.stdout)

    def reset(self):
        for table in ['baganriki_snapshots','baganriki_live_states','t_horse_odds_finder_races',
                      'baganriki_ai_attempts','baganriki_audit_events','provider_stub']:
            self.db.execute('DELETE FROM '+table)
        self.db.execute('INSERT INTO baganriki_snapshots(id,race_id,release_version,sales_close_at,frozen_nums,target_count,quota_1st,common_json,snapshot_hash) VALUES(1,?,?,?,?,?,?,?,?)',
                        (self.data['race_id'],self.release,db_time(self.sales),dump(self.nums),len(self.nums),'{}',self.data['common'],self.data['hash']))
        self.db.execute("INSERT INTO baganriki_live_states(snapshot_id,active_count,cancellations) VALUES(1,?,'[]')",
                        (len(self.nums),))
        date,meeting,venue,day,race=self.data['race_id'].split('-')
        self.db.execute('INSERT INTO t_horse_odds_finder_races VALUES(?,?,?,?,?,?)',
                        (self.start.date().isoformat(),meeting,venue,day,int(race),'15:00'))

    def worker(self, cmd, at, **values):
        offset=microseconds(at-WORKER_CLOCK)-{'send':10,'receive':20,'fail':30,'resume':40}.get(cmd,0)
        params=dump(dict(cmd=cmd,offset=offset,**values))
        res=subprocess.run([self.php,str(HERE/'r154_worker.php'),str(self.path),params,str(self.controller)],
                           text=True,capture_output=True,check=True)
        return json.loads(res.stdout)

    def scalar(self,sql,*args):
        return self.db.execute(sql,args).fetchone()[0]

    def allowed(self,role,slot):
        at=self.start+timedelta(seconds=1+slot*2)
        reservation=self.worker('reserve',at,race=1,role=role,slot=slot)
        require(reservation['code']=='OK',('reserve',role,slot,reservation))
        token=reservation['token']
        require(self.worker('send',at,token=token)['code']=='OK',('send',role,slot))
        return token

    def initial(self):
        for role in ROLES:
            token=self.allowed(role,0)
            result=self.worker('receive',self.start+timedelta(seconds=2),token=token,raw=self.rows[role]['original'],complete=True)
            require(result['code']=='OK','accepted initial for correction')

    def withdraw(self,ns):
        before=json.loads(self.scalar('SELECT cancellations FROM baganriki_live_states'))
        if before==ns:return
        record_live_change(self.db,1,{'cancellations':{'前':before,'後':ns}})
        self.db.execute('UPDATE baganriki_live_states SET active_count=?,cancellations=?,state_revision=state_revision+1',
                        (len(self.nums)-len(ns),dump(ns)))

    def set_close(self,close):
        delta=microseconds(close-close.fromtimestamp(0,close.tzinfo))
        previous=self.scalar('SELECT live_close FROM baganriki_live_states')
        if previous is not None:
            prev=datetime.fromisoformat(previous).replace(tzinfo=close.tzinfo)
            previous=microseconds(prev-prev.fromtimestamp(0,prev.tzinfo))
        record_live_change(self.db,1,{'live_close':{'前':previous,'後':delta}})
        self.db.execute('UPDATE baganriki_live_states SET live_close=?,state_revision=state_revision+1',(db_time(close),))

    def apply(self,case,at,slot):
        db=self.db
        if case=='partial':
            self.withdraw([self.nums[0]])
        elif case=='all':
            self.withdraw(self.nums)
        elif case=='missing_live':
            db.execute('DELETE FROM baganriki_live_states')
        elif case=='bad_count':
            db.execute('UPDATE baganriki_live_states SET active_count=NULL')
        elif case=='disagrees':
            db.execute('UPDATE baganriki_live_states SET cancellations=?',(dump([self.nums[0]]),))
        elif case=='foreign':
            db.execute('UPDATE baganriki_live_states SET cancellations=?',(dump([19]),))
        elif case=='started':
            db.execute("UPDATE t_horse_odds_finder_races SET start_time='XXX'")
        elif case=='missing_race':
            db.execute('DELETE FROM t_horse_odds_finder_races')
        elif case=='invalid_slot':
            db.execute("UPDATE baganriki_ai_attempts SET status='UNRECOGNIZED' WHERE attempt_no=?",(slot,))
        elif case.startswith('cutoff'):
            adjustment={'cutoff_before':1,'cutoff_exact':0,'cutoff_after':-1}[case]
            close=at+timedelta(seconds=90,microseconds=adjustment)
            self.set_close(close)

    def matrix(self):
        cases={'normal':'OK','incomplete':'OK','partial':'OK','cutoff_before':'OK','cutoff_exact':'OK',
               'all':'ALL_CANCELLED','missing_live':'LIVE_STATE_UNAVAILABLE','bad_count':'LIVE_STATE_UNAVAILABLE',
               'disagrees':'LIVE_STATE_UNAVAILABLE','foreign':'LIVE_STATE_UNAVAILABLE','started':'RACE_STARTED',
               'missing_race':'RACE_STATUS_UNAVAILABLE','cutoff_after':'DEADLINE_PASSED','invalid_slot':'INVALID_STATUS'}
        totals=Counter()
        for slot in [0,1]:
            for case,expected in cases.items():
                self.reset()
                if slot:
                    self.initial()
                tokens={role:self.allowed(role,slot) for role in ROLES}
                at=self.start+timedelta(seconds=2+2*slot)
                self.apply(case,at,slot)
                before=self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='QUEUE_RECEIVED'")
                rev=self.db.execute('SELECT response_revision FROM baganriki_live_states').fetchone()
                for role,token in tokens.items():
                    raw=self.rows[role]['original'];complete=case!='incomplete'
                    args=dict(token=token,raw=raw,complete=complete)
                    read_slot='SELECT status,raw_response,response_hash,complete FROM baganriki_ai_attempts WHERE token=?'
                    prior=self.db.execute(read_slot,(token,)).fetchone()
                    receipts=[self.worker('receive',at,**args)['code'] for _ in range(2)]
                    label=(role,slot,case)
                    require(receipts==[expected,'IDEMPOTENT' if expected=='OK' else expected],(label,receipts))
                    recorded=self.db.execute(read_slot,(token,)).fetchone()
                    if expected=='OK':
                        require(recorded==('RECEIVED',raw,sha256(raw.encode()).hexdigest(),int(complete)),(label,'saved payload'))
                        totals['accepted']+=1
                    else:
                        require(recorded==prior and recorded[1:3]==(None,None),(label,'mutated rejected slot',recorded))
                        totals['stopped']+=1
                    require(self.scalar('SELECT count(*) FROM provider_stub WHERE token=?',token)==1,(label,'provider count'))
                delta=2*int(expected=='OK')
                require(self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='QUEUE_RECEIVED'")==before+delta,(case,'duplicate receipt event'))
                latest=self.db.execute('SELECT response_revision FROM baganriki_live_states').fetchone()
                require(latest==((rev[0]+delta,) if rev is not None else None),(case,'unexpected revision'))
        print(f"PASS {sum(totals.values())} post-send receipt scenarios: {totals['accepted']} stored once, "
              f"{totals['stopped']} refused twice without any receipt or new provider call.")

    def concurrent(self):
        counts=Counter()
        with ThreadPoolExecutor(max_workers=4) as pool:
            for slot in [0,1]:
                at=self.start+timedelta(seconds=1+2*slot)
                for role in ROLES:
                    self.reset()
                    if slot:self.initial()
                    def simultaneous(cmd,at,**kw):
                        return [f.result() for f in [pool.submit(self.worker,cmd,at,**kw) for _ in range(4)]]
                    reserve=simultaneous('reserve',at,race=1,role=role,slot=slot)
                    winners=[r for r in reserve if r['ok']]
                    require(len(winners)==1 and all(r['code'] in ('OK','SLOT_TAKEN') for r in reserve),(role,slot,'reserve',reserve))
                    token=winners[0]['token']
                    codes=Counter(r['code'] for r in simultaneous('send',at,token=token))
                    require(codes==Counter(OK=1,ALREADY_SENT=3),(role,slot,'send',codes))
                    raw=self.rows[role]['original']
                    calls=simultaneous('receive',at+timedelta(seconds=1),token=token,raw=raw,complete=True)
                    require(Counter(r['code'] for r in calls)==Counter(OK=1,IDEMPOTENT=3),(role,slot,'receive'))
                    self.withdraw(self.nums)
                    self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='XXX'")
                    for response,complete,code in [(raw,True,'IDEMPOTENT'),(raw+' ',True,'RESPONSE_MISMATCH'),(raw,False,'RESPONSE_MISMATCH')]:
                        require(self.worker('receive',at+timedelta(hours=1),token=token,raw=response,complete=complete)['code']==code,
                                (role,slot,'historical replay',code))
                    require(self.worker('send',at+timedelta(hours=1),token=token)['code']=='TERMINAL','terminal must not resend')
                    require(self.scalar('SELECT count(*) FROM provider_stub WHERE token=?',token)==1,'exactly one permit')
                    require(self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='QUEUE_RECEIVED' AND related_id=?",role+'#'+str(slot))==1,'exactly one receipt')
                    counts['groups']+=1
        print(f"PASS {counts['groups']} concurrent role/attempt groups: four independent PHP processes per reservation, "
              "send and receipt; one permit and one receipt each, historical replay preserved.")

    def publication(self):
        self.reset()
        tokens={role:self.allowed(role,0) for role in ROLES}
        received_at=self.start+timedelta(seconds=2)
        def accept(role,at):
            require(self.worker('receive',at,token=tokens[role],raw=self.rows[role]['original'],complete=True)['ok'],'worker return')
        p=deepcopy(self.data)
        def fresh(now):
            # Join recorded response bytes/time to the sealed request; exclude all unreceived roles.
            p['roles']=[]
            for sample in self.data['roles']:
                result=self.db.execute("SELECT raw_response,response_hash,received_at,complete,status FROM baganriki_ai_attempts WHERE token=? AND status='RECEIVED'",
                                       (tokens[sample['role']],)).fetchone()
                if result is None:
                    continue
                row=deepcopy(sample)
                row['original']=result[0];row['receipt'].update(response_hash=result[1],received_at=result[2].replace(' ','T')+'+09:00',
                                                             complete=result[3],status=result[4])
                p['roles'].append(row)
            p['now']=now.isoformat(timespec='microseconds')
            rev,rr,pr,withdrawn,remaining=self.db.execute('SELECT state_revision,response_revision,publication_revision,cancellations,active_count FROM baganriki_live_states').fetchone()
            p.update(send=False,cancelled=json.loads(withdrawn),live=dict(state_revision=rev,response_revision=rr,publication_revision=pr,
                     cancelled_horse_nos=json.loads(withdrawn),race_id=p['race_id'],snapshot_hash=p['hash'],now=p['now'],
                     live_close_at=None,all_cancelled=remaining==0,race_started=False))
            stamp=self.replay('adopt',p);require(stamp['ok'],'journal')
            p['live'].update(stamp['data'])
            return self.replay('open',p)['data']['result']
        def commit(s):
            return self.replay('commit',dict(p,selection=s,finished_at=p['now']))
        accept('1st',received_at)
        original=fresh(received_at)
        require(sum(original['agreement_bonus'].values())==0 and commit(original)['ok'],'first role only')
        removed=original['public_candidates'][0]
        self.withdraw([removed]);second_at=received_at+timedelta(seconds=1)
        first_only=fresh(second_at)
        require(removed not in first_only['public_candidates'] and commit(first_only)['ok'],'withdrawn before second returns')
        accept('2nd',second_at);partial=fresh(second_at)
        require(len(p['roles'])==2 and partial['role_issues']==[],'both received on different live revisions')
        require(not commit(first_only)['ok'],'old single-role selection after second receipt')
        require(commit(original).get('reason')=='W8_PUBLICATION_CHANGED','a stale candidate remained committable')
        require(partial['public_candidates'] and removed not in partial['public_candidates'] and commit(partial)['ok'],'partial cancellation')
        intent=sha256(b'R15.7 synthetic publication').hexdigest()
        payload=dump(partial);payload_hash=sha256(payload.encode()).hexdigest();publication_id='17'
        seal=self.replay('seal_publication',dict(p,intent=intent,publication_id=publication_id,payload_sha256=payload_hash))['data']['sha256']
        stored=dict(race_id=p['race_id'],snapshot_hash=p['hash'],intent_hash=intent,publication_id=publication_id,
                    selection_json=payload,payload_sha256=payload_hash,publication_sha256=seal)
        self.withdraw(self.nums);all_out=fresh(second_at+timedelta(seconds=1))
        require(all_out['public_candidates']==[] and commit(all_out)['reason']=='W8_NOT_PUBLISHABLE','fully withdrawn publication')
        require(not commit(partial)['ok'],'older candidate after all withdrawn')
        for _ in range(3):
            read=self.replay('replay',dict(p,intent=intent,stored=stored))
            require(read.get('data',{}).get('publication_id')==publication_id,('historical replay',read))
        require(self.scalar('SELECT count(*) FROM provider_stub')==2,'readback triggered another provider call')
        print('PASS queue-to-publication replay: one withdrawal between the two callbacks, both exact receipts retained, withdrawn candidate removed, '
              'refused stale and fully withdrawn selections, returned the same prior publication ID on three reads.')

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php');parser.add_argument('controller',type=Path)
    args=parser.parse_args()
    with TemporaryDirectory() as temp:
        trial=Trial(args.php,args.controller.resolve(),Path(temp))
        try:
            trial.matrix();trial.concurrent();trial.publication()
        finally:
            trial.db.close()

if __name__=='__main__':
    main()
