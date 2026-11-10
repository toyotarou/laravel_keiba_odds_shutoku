"""Exercise real W2 publication retries and the persisted public response, including overlapping correction and withdrawal."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
from datetime import timedelta
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory
import json
import sys
import time
import subprocess

PREVIOUS='3f8e42b2f9b5bf12a25b99805b5410c9365cc2dacc8d681092b154257a2a6544'
if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php');parser.add_argument('controller',type=Path)
    scope=parser.add_mutually_exclusive_group()
    scope.add_argument('--upgrade-from',type=Path,nargs=2,metavar=('R15_13_CONTROLLER','R15_13_W2'))
    scope.add_argument('--observe-r1513',type=Path,metavar='R15_13_W2')
    args=parser.parse_args()
    if args.observe_r1513:
        if sha256(args.controller.read_bytes()).hexdigest()!=PREVIOUS:parser.error('R15.13 submitted controller required')
        sys.path.insert(0,str(args.observe_r1513.resolve()/'validation'))
from verify_r1513_corrected_publication import CorrectedTrial, ROLES
from verify_r157_receive import dump,require


class ReplayTrial(CorrectedTrial):
    def __init__(self,php,controller,directory):
        super().__init__(php,controller,directory)
        self.db.execute('CREATE UNIQUE INDEX r1514_intent ON baganriki_publication_intents(snapshot_id,intent_hash)')
        self.db.execute('CREATE UNIQUE INDEX r1514_pub ON baganriki_publications(publication_id)')
        for table in ('baganriki_publications','baganriki_publication_intents'):
            for col in ('selection_json','payload_sha256','publication_sha256'):
                self.db.execute('ALTER TABLE '+table+' ADD COLUMN '+col+' TEXT')

    def seeded(self):
        self.setup()
        for role in ROLES:self.put(role)

    def pin(self, first, selection):
        raw=dump(selection);digest=sha256(raw.encode()).hexdigest()
        seal=self.replay('seal_publication',dict(self.data,intent=first['intent_hash'],publication_id=first['publication_id'],payload_sha256=digest))
        require(seal['ok'],seal)
        self.db.execute('UPDATE baganriki_publications SET selection_json=?,payload_sha256=?,publication_sha256=? WHERE publication_id=?',
                        (raw,digest,seal['data']['sha256'],first['publication_id']))

    def smoke_test(self, old=False):
        self.seeded()
        expected=self.expected(ROLES);first=self.publish_db()
        second=self.publish_db();require(first['code']=='PUBLISHED' and second['code']=='ALREADY' and first['publication_id']==second['publication_id'],second)
        require(self.scalar('SELECT COUNT(*) FROM baganriki_publications')==1 and second['candidates']==first['candidates'],second)
        self.pin(first,expected)
        rows=deepcopy(first['candidates']);longshot=next(h for h in rows if h['horse_no']==12)
        require(longshot['bonus']==expected['agreement_bonus']['12']==0,'seeded 12 has no agreement')
        longshot['bonus']=5;longshot['bonus_proven']=True
        self.db.execute('UPDATE baganriki_publications SET candidates=?',(dump(rows),))
        dto=self.get()['body'];retried=self.publish_db()
        if old:
            require(dto['state']=='PUBLISHED' and next(h for h in dto['publication']['candidates'] if h['horse_no']==12)['bonus']==5 and retried['code']=='ALREADY',dto)
            print('R15.13 W9 recorded horse 12 bonus 0; GET returned 5 and repeat reported ALREADY.',flush=True)
        else:
            require(dto['state']=='UNAVAILABLE' and dto['publication'] is None and retried['code']=='PUBLICATION_DATA_INVALID' and not retried['candidates'],retried)
        self.db.execute('DELETE FROM baganriki_publications')
        out=self.publish_db();dto=self.get()['body']
        if old:
            require(out['code']=='ALREADY' and out['ok'] and out['publication_id']==first['publication_id'] and dto['state']=='UNAVAILABLE',out)
            print('R15.13 retry with zero publication rows still returned success, same ID and seven candidates; GET held the result.',flush=True)
        else:
            require(not out['ok'] and not out['publication_id'] and not out['candidates'] and out['code']=='PUBLICATION_REFERENCE_MISMATCH' and dto['state']=='UNAVAILABLE',out)
            print('PASS R15.14 standard: genuine retry preserves one ID; missing body and false sealed horse-12 bonus withheld by retry and public read.',flush=True)


def smoke(php,controller):
    with TemporaryDirectory() as tmp:
        t=ReplayTrial(str(php),Path(controller).resolve(),Path(tmp))
        try:t.smoke_test()
        finally:t.db.close()


class OverlapTrial(ReplayTrial):
    def db_copy(self):
        import sqlite3
        archived=sqlite3.connect(':memory:')
        self.db.backup(archived)
        return archived

    def unchanged(self):
        queries=('SELECT * FROM baganriki_live_states','SELECT * FROM baganriki_publication_intents ORDER BY id',
                 'SELECT * FROM baganriki_publications ORDER BY publication_id','SELECT * FROM baganriki_audit_events ORDER BY rowid',
                 'SELECT * FROM provider_stub ORDER BY rowid')
        return [self.db.execute(sql).fetchall() for sql in queries]

    def faulty(self):
        self.seeded();first=self.publish_db();pid=first['publication_id'];require(first['code']=='PUBLISHED',first)
        before=self.db_copy()
        cases={
            'no_publication':('DELETE FROM baganriki_publications',()),
            'no_intent':('DELETE FROM baganriki_publication_intents',()),
            'no_id':('UPDATE baganriki_publication_intents SET publication_id=NULL',()),
            'wrong_id':('UPDATE baganriki_publication_intents SET publication_id=?',('other-race',)),
            'uncommitted':("UPDATE baganriki_publication_intents SET state='PENDING'",()),
            'wrong_snapshot':('UPDATE baganriki_publications SET snapshot_id=2',()),
            'wrong_parent':('UPDATE baganriki_publications SET intent_id=123456',()),
            'wrong_input_revision':('UPDATE baganriki_publications SET used_response_revision=0',()),
            'wrong_publication_revision':('UPDATE baganriki_publication_intents SET read_publication_revision=9',()),
            'missing_latest':('UPDATE baganriki_live_states SET latest_publication_id=NULL',()),
            'other_latest':('UPDATE baganriki_live_states SET latest_publication_id=?',('not-in-this-race',)),
            'changed_intent':('UPDATE baganriki_publication_intents SET intent_hash=?',('b'*64,)),
            'display_order':('UPDATE baganriki_publications SET candidates=?',(dump(first['candidates'][::-1]),)),
            'incomplete_seal':('UPDATE baganriki_publications SET publication_sha256=?',('c'*64,)),
        }
        try:
            for name,(sql,args_) in cases.items():
                self.db.execute(sql,args_);state=self.unchanged();g=self.publish_db()
                require(not g['ok'] and not g['publication_id'] and not g['reused'] and not g['candidates'],(name,g['code'],g.get('reason')))
                dto=self.get()['body'];require(dto['state']=='UNAVAILABLE' and dto['publication'] is None and self.unchanged()==state,(name,dto['state']))
                before.backup(self.db)
            g=self.publish_db();require(g['code']=='ALREADY' and g['publication_id']==pid and g['used']==first['used'],g)
            require(all(g[k]==first[k] for k in ('candidates','quota','prediction_quota','actual_quota','shortfalls','fill','proof','role_issues','rank_source','rank_fallback','rank_notes')),g)
        finally:before.close()
        print('PASS 14 damaged references, committed intents, rows or orders: neither public retry nor GET certifies missing data; original ID and recorded result reusable after restoration.',flush=True)

    def proof_fields(self):
        self.seeded();selected=self.expected(ROLES);first=self.publish_db();self.pin(first,selected)
        rows=first['candidates'];cache=self.db_copy();cases=[
            (12,'bonus',5),(7,'bonus',0),(7,'bonus','5'),(12,'bonus_proven',True),(7,'bonus_proven',False),
            (7,'both_public',False),(7,'public_proof_hash',None),(12,'public_proof_hash',{'1st':'a'*64,'2nd':'b'*64}),
            (7,'public_proof_hash',{'1st':'a'*64,'2nd':'b'*64}),
            (7,'public_proof_hash',{'1st':rows[3]['public_proof_hash']['2nd'],'2nd':rows[3]['public_proof_hash']['1st']})]
        try:
            for number,field,value in cases:
                copy=deepcopy(rows);h=next(x for x in copy if x['horse_no']==number)
                require(value!=h[field],(number,field));h[field]=value
                self.db.execute('UPDATE baganriki_publications SET candidates=?',(dump(copy),))
                before=self.unchanged();out=self.publish_db();dto=self.get()['body']
                require(out['code']=='PUBLICATION_DATA_INVALID' and not out['ok'] and not out['candidates'] and dto['state']=='UNAVAILABLE' and self.unchanged()==before,(number,field,out['code'],dto['state']))
                cache.backup(self.db)
            compact=[{k:row[k] for k in ('horse_no','band')} for row in rows]
            self.db.execute('UPDATE baganriki_publications SET candidates=?',(dump(compact),))
            g=self.publish_db();dto=self.get()['body']
            require(g['code']=='ALREADY' and g['candidates']==compact and dto['publication']['candidates']==compact,(g,dto))
            cache.backup(self.db)
            g=self.publish_db();require(g['code']=='ALREADY' and g['candidates']==rows,g)
        finally:cache.close()
        print('PASS 10 sealed projection mismatches of bonus, proven status, nomination and per-role hashes held; historical compact projection and full saved candidates returned verbatim.',flush=True)

    def normal_proofs(self):
        self.seeded();first=self.publish_db();rows=first['candidates'];cache=self.db_copy()
        aggregate=json.loads(self.scalar('SELECT public_proof_hash FROM baganriki_publications'))
        good=next(h for h in rows if h['horse_no']==7)['public_proof_hash']
        try:
            for case in ('false_zero','opposite_roles','claim_without_source','damaged_aggregate','missing_role','wrong_bonus','malformed'):
                changed=deepcopy(rows);hashes=deepcopy(aggregate)
                h=next(h for h in changed if h['horse_no']==(12 if case=='false_zero' else 7))
                if case=='false_zero':h.update(bonus_proven=True,public_proof_hash={'1st':'1'*64,'2nd':'2'*64})
                if case=='opposite_roles':h['public_proof_hash']={'1st':good['2nd'],'2nd':good['1st']}
                if case=='claim_without_source':hashes=None
                if case=='damaged_aggregate':hashes['7']['1st']='a'*64
                if case=='missing_role':del hashes['7']['2nd']
                if case=='wrong_bonus':h['bonus']=0
                if case=='malformed':hashes='wrong type'
                self.db.execute('UPDATE baganriki_publications SET candidates=?,public_proof_hash=?',
                                (dump(changed),None if hashes is None else dump(hashes)))
                out=self.publish_db();dto=self.get()['body']
                require(out['code']=='PUBLICATION_DATA_INVALID' and not out['candidates'] and dto['state']=='UNAVAILABLE',(case,out['code'],dto['state']))
                cache.backup(self.db)
            compact=[{k:h[k] for k in ('horse_no','band','bonus')} for h in rows]
            self.db.execute('UPDATE baganriki_publications SET candidates=?,public_proof_hash=NULL',(dump(compact),))
            dto=self.get()['body'];g=self.publish_db()
            require(g['code']=='ALREADY' and g['candidates']==compact and dto['publication']['candidates']==compact,'original compact view')
        finally:cache.close()
        print('PASS 7 original-format proof and bonus mismatches held by both readers; original compact historical candidates retained.',flush=True)

    def upgrade_from(self,previous):
        ctrl,folder=(x.resolve() for x in previous)
        require(sha256(ctrl.read_bytes()).hexdigest()==PREVIOUS,'R15.13 controller required')
        self.setup();role='1st';raw=self.init_role(role,12,[(12,'evidence')]);self.init_role('2nd',None)
        self.correction(role,raw)
        calls=self.scalar('SELECT count(*) FROM provider_stub')
        cmd=[self.php,str(folder/'validation/r1512_publication.php'),str(self.path),'_',str(ctrl)]
        old=subprocess.run(cmd,input=dump(dict(cmd='publish',clock=self.us(self.now))),text=True,capture_output=True,check=True)
        require(not old.stderr,old.stderr)
        original=json.loads(old.stdout);self.check_public(original,[12],role)
        pid=original['publication_id'];saved=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',pid)
        again=self.publish_db();dto=self.get()['body']
        require(again['code']=='ALREADY' and again['publication_id']==dto['publication']['publication_id']==pid
                and again['candidates']==original['candidates']==dto['publication']['candidates'],'existing R15.13 ID and rows')
        require(self.update([12])['code']=='APPLIED','withdraw 12')
        new=self.publish_db();self.check_public(new,[],role)
        require(new['publication_id']!=pid and self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',pid)==saved
                and self.scalar('SELECT count(*) FROM provider_stub')==calls,'unchanged R15.13 body and no extra provider calls')
        print('PASS actual R15.13 publisher to R15.14 on one DB: corrected horse 12 and original ID preserved; later withdrawal creates a new ID without an extra AI call.',flush=True)

    def with_workers(self,count,interleave):
        with TemporaryDirectory(dir=self.directory) as path,ThreadPoolExecutor(count) as pool:
            path=Path(path);workers=[pool.submit(self.publish_db,rendezvous=str(path)) for _ in range(count)]
            try:
                deadline=time.monotonic()+40
                while len(list(path.glob('ready-*')))<count:
                    for work in workers:
                        if work.done():raise AssertionError(('publish stopped before rendezvous',work.result()))
                    require(time.monotonic()<deadline,'workers did not reach precommit')
                    time.sleep(.004)
                interleave()
            finally:(path/'continue').touch()
            return [work.result() for work in workers]

    def concurrent(self):
        self.seeded()
        current=self.with_workers(4,lambda:None);codes=sorted(x['code'] for x in current)
        require(codes==['ALREADY','ALREADY','ALREADY','PUBLISHED'] and len({x['publication_id'] for x in current})==1,codes)
        require(all(x['candidates']==current[0]['candidates'] and x['used']==current[0]['used'] for x in current),codes)
        require(self.scalar('SELECT count(*) FROM baganriki_publications')==1 and self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='PUBLISHED'")==1,'one committed publication')
        pid=current[0]['publication_id'];before=self.unchanged()
        for _ in range(3):require(self.publish_db()['publication_id']==pid,'sequential repeat')
        require(before==self.unchanged() and self.get()['body']['publication']['publication_id']==pid,'no retry writes or calls')
        print('PASS four competing writers and three sequential retries: one publication, one audit event, one ID and identical ordered results; zero extra AI calls.',flush=True)

    def changes(self,role,cancel_target,receive_first):
        self.setup();self.now=self.start+timedelta(seconds=8)
        other=ROLES[1-ROLES.index(role)];target=12 if role=='1st' else 16
        good=self.init_role(role,target,[(target,'evidence')]);self.init_role(other,None)
        token=self.correction(role,good,delayed=True)
        gone=target if cancel_target else 9
        def receive():
            r=self.worker('receive',self.now,token=token,raw=good,complete=True);require(r['ok'],r)
        def withdraw():
            require(self.update([gone])['code']=='APPLIED','official withdrawal')
        def events():
            for change in ((receive,withdraw) if receive_first else (withdraw,receive)):change()
        conflict,=self.with_workers(1,events)
        require(conflict['code']=='STALE' and self.scalar('SELECT count(*) FROM baganriki_publications')==0,conflict)
        fresh=self.publish_db();self.check_public(fresh,[] if cancel_target else [target],role)
        again=self.publish_db();require(again['code']=='ALREADY' and again['publication_id']==fresh['publication_id'] and again['candidates']==fresh['candidates'],again)

    def historical(self,advance,damage=False,decoy=False):
        self.seeded();expected=self.expected(ROLES);old=self.publish_db();self.pin(old,expected)
        first_id=old['publication_id'];old_rows=old['candidates'];new_ids=[]
        def events():
            require(self.update([12])['code']=='APPLIED','history switching')
            if advance:
                new=self.publish_db();require(new['code']=='PUBLISHED' and new['publication_id']!=first_id,new)
                new_ids.append(new['publication_id'])
            if damage:
                col='candidates' if not decoy else 'intent_id'
                value=dump(list(reversed(old_rows))) if not decoy else 9999
                self.db.execute('UPDATE baganriki_publications SET '+col+'=? WHERE publication_id=?',(value,first_id))
        again,=self.with_workers(1,events)
        if damage:
            require(not again['ok'] and not again['publication_id'] and not again['candidates'],again)
        else:
            require(again['code']=='ALREADY' and again['publication_id']==first_id and again['candidates']==old_rows and again['used']==old['used'],again)
        dto=self.get()['body']
        require(dto['publication']['publication_id']==(new_ids[0] if advance else first_id) and 12 not in dto['live_state']['available_candidate_nos'],dto)
        require(self.scalar('SELECT count(*) FROM baganriki_publications')==(2 if advance else 1),'no third publication')

    def earlier_damage(self):
        self.seeded();old=self.publish_db();pid=old['publication_id'];copied=self.db_copy()
        try:
            self.db.execute('DELETE FROM baganriki_publications')
            require(self.update([12])['code']=='APPLIED','apply next legitimate withdrawal')
            before=self.unchanged();g=self.publish_db();require(not g['ok'] and not g['candidates'] and self.unchanged()==before,'never build on missing committed head')
            copied.backup(self.db)
            require(self.update([12])['code']=='APPLIED','withdraw on restored head')
            fresh=self.publish_db();require(fresh['code']=='PUBLISHED' and fresh['publication_id']!=pid and 12 not in [h['horse_no'] for h in fresh['candidates']],fresh)
        finally:copied.close()
        print('PASS new result held on broken previous head; after restoring the genuine head, official withdrawal produces its own corrected ID.',flush=True)


def run(php,controller,old,previous=None):
    with TemporaryDirectory() as tmp:
        t=OverlapTrial(php,controller,Path(tmp))
        try:
            if previous:
                t.upgrade_from(previous);return
            t.smoke_test(old)
            if old:return
            t.faulty();t.proof_fields();t.normal_proofs();t.concurrent()
            for role in ROLES:
                for gone in (False,True):
                    for first in (True,False):t.changes(role,gone,first)
            print('PASS 8 overlapping correction and withdrawal sequences across both roles: stale computation held, repaired active outsider retained, withdrawn outsider excluded; DB, GET and retry agree.',flush=True)
            for advance,damage,decoy in [(False,False,False),(True,False,False),(True,True,False),(True,True,True)]:
                t.historical(advance,damage,decoy)
            print('PASS four replays spanning an official withdrawal and a newer publication: verified old ID stays historical, damaged old bodies refused, live screen stays on genuine latest state.',flush=True)
            t.earlier_damage()
        finally:t.db.close()


if __name__=='__main__':run(args.php,args.controller.resolve(),bool(args.observe_r1513),args.upgrade_from)
