"""Local SQLite round-trip with actual PHP freeze, compression, receipt and publication entry points."""
from datetime import datetime, timedelta
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory
from copy import deepcopy
import json
import sqlite3
import subprocess

def run(php,bootstrap,examples):
    script=str(Path(__file__).with_name('r7_storage_probe.php'))
    def worker(phase,payload=None,directory=examples):
        raw=json.dumps(payload,ensure_ascii=False,separators=(',',':')) if payload is not None else None
        result=subprocess.run([php,script,str(bootstrap),phase,str(directory)],input=raw,text=True,capture_output=True,check=True)
        return json.loads(result.stdout)
    result=worker('export');assert result['ok'];source=result['data'];digest=lambda x:sha256(x).hexdigest()
    checks=0
    def check(condition,message):
        nonlocal checks
        if not condition:raise AssertionError(message)
        checks+=1
    with TemporaryDirectory() as t:
        otherdir=Path(t)/'other';subprocess.run([php,'-n',str(Path(__file__).with_name('verify_w2.php')),'--examples',str(otherdir),'--second-fixture'],check=True,capture_output=True)
        other=worker('export',directory=otherdir)['data'];check(other['race_id']!=source['race_id'] and other['hash']!=source['hash'],'second independent race')
        db=Path(t)/'handoff.sqlite';writer=sqlite3.connect(db,isolation_level=None);reader=sqlite3.connect(db,isolation_level=None)
        try:
            writer.executescript("""
                PRAGMA journal_mode=WAL;
                CREATE TABLE freeze(id INTEGER PRIMARY KEY, race TEXT, common BLOB, common_sha TEXT, witness BLOB, witness_sha TEXT);
                CREATE TABLE role_input(freeze_id INTEGER, role TEXT, input BLOB, original BLOB, receipt TEXT, proof_horses TEXT, PRIMARY KEY(freeze_id,role));
                CREATE TABLE live(id INTEGER PRIMARY KEY, revision INTEGER, cancelled TEXT, response_rev INTEGER, public_rev INTEGER, close_at TEXT, journal TEXT);
                CREATE TABLE publication(id INTEGER PRIMARY KEY, race TEXT, snapshot TEXT, intent TEXT, revision INTEGER, payload BLOB, payload_sha TEXT, receipt_sha TEXT, UNIQUE(race,snapshot,intent));
            """)
            def insert_freeze(data=source,key=1):
                writer.execute('INSERT INTO freeze VALUES(?,?,?,?,?,?)',(key,data['race_id'],data['common'].encode(),data['hash'],
                                  data['witness']['json'].encode(),data['witness']['sha256']))
            writer.execute('BEGIN IMMEDIATE');insert_freeze()
            check(reader.execute('SELECT COUNT(*) FROM freeze').fetchone()[0]==0,'reader saw half-written freeze')
            writer.rollback();check(writer.execute('SELECT COUNT(*) FROM freeze').fetchone()[0]==0,'partial freeze left by rollback')
            writer.execute('BEGIN IMMEDIATE')
            for key,data in [(1,source),(2,other)]:
                insert_freeze(data,key)
                for row in data['roles']:
                    writer.execute('INSERT INTO role_input VALUES(?,?,?,?,?,?)',(key,row['role'],row['input'].encode(),row['original'].encode(),
                                   json.dumps(row['receipt']),json.dumps(row['proof_horses'])))
                writer.execute("INSERT INTO live VALUES(?,0,'[]',0,0,NULL,?)",(key,json.dumps(data['role_journal'])))
            writer.commit()
            def load(send=False,key=1):
                reader.execute('BEGIN')
                try:
                    race,common,h,witness,wh=reader.execute('SELECT race,common,common_sha,witness,witness_sha FROM freeze WHERE id=?',(key,)).fetchone()
                    rev,cs,rr,pr,close,journal=reader.execute('SELECT revision,cancelled,response_rev,public_rev,close_at,journal FROM live WHERE id=?',(key,)).fetchone();cancelled=json.loads(cs)
                    roles=[{'role':r,'input':i.decode(),'original':o.decode(),'receipt':json.loads(a),'proof_horses':json.loads(v)}
                           for r,i,o,a,v in reader.execute('SELECT role,input,original,receipt,proof_horses FROM role_input WHERE freeze_id=? ORDER BY role',(key,))]
                    reader.commit()
                except Exception:
                    reader.rollback();raise
                return rev,{'key':key,'common':common.decode(),'hash':h,'witness':{'json':witness.decode(),'sha256':wh},'roles':roles,'cancelled':cancelled,'send':send,
                      'live':{'role_journal':json.loads(journal),'state_revision':rev,'response_revision':rr,'publication_revision':pr,'cancelled_horse_nos':cancelled,
                              'race_id':race,'snapshot_hash':h,'now':source['now'],'live_close_at':close,'race_started':False,'all_cancelled':set(cancelled)==set(source['numbers'])}}
            rev,p=load(True)
            check(p['common']==source['common'] and digest(p['common'].encode())==source['hash'],'common bytes lost')
            check(p['witness']==source['witness'] and digest(p['witness']['json'].encode())==p['witness']['sha256'],'proof bytes lost')
            check(p['roles']==source['roles'],'saved role input/attempt bytes changed')
            before=worker('open',p);check(before['ok'],'fresh PHP failed to re-open saved data')
            answer=before['data']['result'];check(answer['selection_state']=='CANDIDATES_READY' and answer['role_issues']==[],'clean publication')
            check(all(fmt[0]=='SOURCE_TABLE_V1' for fmt in before['data']['formats'].values()) and len(before['data']['formats'])==2,'full fallback masked a transport problem')
            picks=answer['public_candidates'];check(sum(answer['actual_quota'].values())==len(picks),'persisted quota mismatch')
            # Use the same PHP commit guard under a write lock; the host supplies its three live revisions.
            def stored_record(row):
                return dict(publication_id=str(row[0]),race_id=row[1],snapshot_hash=row[2],intent_hash=row[3],selection_json=row[4].decode(),payload_sha256=row[5],publication_sha256=row[6])
            # Worker arguments can be stale even when all three currently read revision numbers agree.
            for mutate,role in [('omit','2nd'),('approval','1st'),('vote','1st'),('other-receipt','2nd'),('outbound','1st')]:
                copy=deepcopy(p);rows=copy['roles']
                if mutate=='omit':rows.pop()
                elif mutate=='approval':rows[0]['receipt']['original_approved']=False
                elif mutate=='vote':rows[0]['proof_horses']=[]
                elif mutate=='other-receipt':rows[1]['receipt']=other['roles'][1]['receipt']
                else:rows[0]['receipt']['sent_request']['user_json']+=' '
                answer_bad=worker('open',copy);check(not answer_bad['ok'] and answer_bad['reason']=='W9_JOURNAL_MISMATCH_'+role,'torn read '+mutate)
            def commit_if_fresh(intent,snapshot,result,clock=None,finished_at=None):
                writer.execute('BEGIN IMMEDIATE')
                try:
                    token=digest(intent.encode());race=snapshot['live']['race_id'];h=snapshot['hash'];key=snapshot['key']
                    old=writer.execute('SELECT id,race,snapshot,intent,payload,payload_sha,receipt_sha FROM publication WHERE race=? AND snapshot=? AND intent=?',(race,h,token)).fetchone()
                    if old:
                        checked=worker('replay',dict(snapshot,intent=token,stored=stored_record(old)))
                        if not checked['ok']:writer.rollback();return checked['reason']
                        writer.commit();return 'REPLAY'
                    state,cs,rr,pr,close,journal=writer.execute('SELECT revision,cancelled,response_rev,public_rev,close_at,journal FROM live WHERE id=?',(key,)).fetchone()
                    withdrawals=json.loads(cs);live=dict(snapshot['live'],state_revision=state,response_revision=rr,publication_revision=pr,role_journal=json.loads(journal),
                        cancelled_horse_nos=withdrawals,all_cancelled=set(withdrawals)==set(source['numbers']),live_close_at=close,now=clock or source['now'])
                    checked=worker('commit',dict(snapshot,live=live,selection=result,finished_at=finished_at or source['now']))
                    if not checked['ok']:
                        writer.rollback();return checked['reason']
                    body=json.dumps(result,separators=(',',':')).encode()
                    payload_hash=digest(body)
                    inserted=writer.execute('INSERT INTO publication(race,snapshot,intent,revision,payload,payload_sha) VALUES (?,?,?,?,?,?)',(race,h,token,state,body,payload_hash)).lastrowid
                    sealed=worker('seal_publication',dict(snapshot,intent=token,publication_id=str(inserted),payload_sha256=payload_hash))['data']['sha256']
                    independently=digest(json.dumps(['W9_PUBLISHED_V1',race,h,token,str(inserted),payload_hash],ensure_ascii=False,separators=(',',':')).encode())
                    if sealed!=independently:raise AssertionError('published id seal disagrees')
                    writer.execute('UPDATE publication SET receipt_sha=? WHERE id=?',(sealed,inserted))
                    writer.execute('UPDATE live SET public_rev=public_rev+1 WHERE id=?',(key,));writer.commit();return 'SAVED'
                except Exception:
                    writer.rollback();raise
            writer.execute('UPDATE live SET response_rev=1 WHERE id=1')
            check(commit_if_fresh('response',p,answer)=='W8_PUBLICATION_CHANGED','response-only change missed')
            writer.execute('UPDATE live SET public_rev=1 WHERE id=1')
            check(commit_if_fresh('publish',p,answer)=='W8_PUBLICATION_CHANGED','publication revision change missed')
            _,p=load();before=worker('open',p);answer=before['data']['result']
            prior=(datetime.fromisoformat(source['now'])-timedelta(microseconds=1)).isoformat()
            late=(datetime.fromisoformat(source['publish_deadline'])+timedelta(microseconds=1)).isoformat()
            check(commit_if_fresh('slow',p,answer,late)=='W8_COMMIT_WINDOW','new write after close')
            check(commit_if_fresh('backward',p,answer,prior)=='W8_COMMIT_WINDOW','backward commit clock')
            check(commit_if_fresh('validation',p,answer,finished_at=prior)=='W8_COMMIT_WINDOW','completion preceded start')
            check(commit_if_fresh('changed-bytes',p,dict(answer,public_candidates=[1]))=='W8_PUBLICATION_BYTES','result changed in transit')
            writer.execute('UPDATE live SET close_at=? WHERE id=1',(source['publish_deadline'],))
            check(commit_if_fresh('shorter-close',p,answer)=='W8_PUBLICATION_CHANGED','earlier official closing time missed')
            writer.execute('UPDATE live SET close_at=NULL WHERE id=1')
            writer.execute('UPDATE live SET revision=1,cancelled=? WHERE id=1',(json.dumps([picks[0]]),))
            check(commit_if_fresh('old',p,answer)=='W8_PUBLICATION_CHANGED' and reader.execute('SELECT COUNT(*) FROM publication').fetchone()[0]==0,'stale publication committed')
            rev,p=load();latest=worker('open',p);check(latest['ok'] and picks[0] not in latest['data']['result']['public_candidates'],'cancelled horse survived PHP replay')
            ans=latest['data']['result'];check(sum(ans['actual_quota'].values())==len(ans['public_candidates']),'live total')
            check(commit_if_fresh('one',p,ans)=='SAVED' and commit_if_fresh('one',p,ans)=='REPLAY','same intent wrote twice')
            check(commit_if_fresh('another-job',p,ans)=='W8_PUBLICATION_CHANGED','competing intent used old publication revision')
            check(commit_if_fresh('one',p,dict(ans,public_candidates=[]))=='REPLAY','retry replaced its stored public payload')
            frozen_pub=reader.execute('SELECT payload FROM publication WHERE race=?',(source['race_id'],)).fetchone()[0]
            writer.execute('UPDATE live SET revision=2,cancelled=? WHERE id=1',(json.dumps([picks[0],ans['public_candidates'][0]]),))
            check(reader.execute('SELECT payload FROM publication WHERE race=?',(source['race_id'],)).fetchone()[0]==frozen_pub,'read-time cancellation rewrote saved publication')
            otherrev,p2=load(key=2);res2=worker('open',p2);check(res2['ok'],'second race independently reopened')
            ans2=res2['data']['result']
            check(commit_if_fresh('one',p2,ans2)=='SAVED' and commit_if_fresh('one',p2,ans2)=='REPLAY','same intent label collided across races')
            publications=reader.execute('SELECT id,race,snapshot,intent,payload,payload_sha,receipt_sha FROM publication ORDER BY id').fetchall()
            check(len(publications)==2 and publications[0][0]!=publications[1][0] and publications[0][3]==publications[1][3],'independent historical ids')
            token=digest(b'one');foreign=stored_record(publications[0])
            denied=worker('replay',dict(p2,intent=token,stored=foreign));check(not denied['ok'] and denied['reason']=='W9_REPLAY_ID','wrong-race early replay')
            disguised=dict(foreign,race_id=p2['live']['race_id'],snapshot_hash=p2['hash'])
            denied=worker('replay',dict(p2,intent=token,stored=disguised));check(not denied['ok'] and denied['reason']=='W9_REPLAY_ID','foreign selection under refashioned header')
            changed=stored_record(publications[1]);changed['publication_id']=foreign['publication_id']
            denied=worker('replay',dict(p2,intent=token,stored=changed));check(not denied['ok'] and denied['reason']=='W9_REPLAY_RECORD','wrong assigned publication id')
            changed=stored_record(publications[1]);changed['intent_hash']=digest(b'other-intent')
            denied=worker('replay',dict(p2,intent=changed['intent_hash'],stored=changed));check(not denied['ok'] and denied['reason']=='W9_REPLAY_RECORD','swapped intent seal')
            row=stored_record(publications[1]);row['selection_json']+=' '
            denied=worker('replay',dict(p2,intent=token,stored=row));check(not denied['ok'] and denied['reason']=='W9_REPLAY_BYTES','damaged published payload')
            _,fresh2=load(key=2);stamp=deepcopy(fresh2['live']['role_journal']);stamp['2nd']=None
            writer.execute('UPDATE live SET journal=? WHERE id=2',(json.dumps(stamp),))
            second_answer=worker('open',fresh2)['data']['result']
            check(commit_if_fresh('late-journal',fresh2,second_answer)=='W9_JOURNAL_CHANGED','independent locked journal recheck')
            writer.execute("UPDATE role_input SET original=? WHERE freeze_id=1 AND role='2nd'",(b'incomplete receipt',))
            _,p=load();one=worker('open',p);check(not one['ok'] and one['reason']=='W9_JOURNAL_MISMATCH_2nd','disk corruption silently suppressed accepted role')
            # A confirmed terminal failure is a different committed journal state. Preserve the other role.
            writer.execute('BEGIN IMMEDIATE')
            receipt=json.loads(writer.execute("SELECT receipt FROM role_input WHERE freeze_id=1 AND role='2nd'").fetchone()[0])
            receipt['status']='FAILED'
            writer.execute("UPDATE role_input SET receipt=? WHERE freeze_id=1 AND role='2nd'",(json.dumps(receipt),))
            p['roles'][1]['receipt']=receipt
            newstamp=worker('adopt',p)['data']['role_journal']
            writer.execute('UPDATE live SET journal=?,response_rev=response_rev+1 WHERE id=1',(json.dumps(newstamp),));writer.commit()
            _,p=load();one=worker('open',p);check(one['ok'] and '2nd' in one['data']['result']['role_issues'] and max(one['data']['result']['agreement_bonus'].values())==0,'failed role influenced valid partner')
            writer.execute("UPDATE freeze SET witness=? WHERE id=1",((source['witness']['json']+' ').encode(),))
            _,p=load(True);denied=worker('open',p);check(not denied['ok'] and denied['reason']=='W6_E_PROOF_HASH','damaged storage witness permitted a send')
            check(reader.execute('SELECT COUNT(*) FROM publication').fetchone()[0]==2,'rechecks created publications')
        finally:reader.close();writer.close()
    print(f'PASS {checks} local SQLite/PHP storage and replay checks using two DB connections, two distinct races, saved dispatches, both role journals and the PHP commit guard; no deployed MySQL, external AI call or Flutter client.')
