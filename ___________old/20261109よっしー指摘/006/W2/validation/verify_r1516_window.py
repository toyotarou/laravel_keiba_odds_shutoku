"""Check a change to the official race clock between role inspection and the locked commit."""
import argparse
from copy import deepcopy
from datetime import timedelta
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory

import json
import subprocess
import sys

PREVIOUS='517afc3a7939400397516c5c3facc942e72ca31bae687168ce3ede128c59e0f7'
PREVIOUS_MANIFEST='d716ce7a01ceb2cee55eda433748707e327bc3fcd17d7cf958498358066eda84'

def original_layout(controller, directory):
    if sha256(controller.read_bytes()).hexdigest()!=PREVIOUS or sha256((directory/'SHA256SUMS.txt').read_bytes()).hexdigest()!=PREVIOUS_MANIFEST:
        raise ValueError('Supply the original R15.15 W2 directory and its patched submitted controller.')
    for line in (directory/'SHA256SUMS.txt').read_text().splitlines():
        if sha256((directory/line[66:]).read_bytes()).hexdigest()!=line[:64]:
            raise ValueError('Unmatched R15.15 component: '+line[66:])

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('php');parser.add_argument('controller',type=Path)
    mode=parser.add_mutually_exclusive_group()
    mode.add_argument('--observe-r1515',type=Path,metavar='ORIGINAL_W2')
    mode.add_argument('--upgrade-from',type=Path,nargs=2,metavar=('ORIGINAL_CONTROLLER','ORIGINAL_W2'))
    args=parser.parse_args()
    if args.observe_r1515:
        original_layout(args.controller,args.observe_r1515)
        sys.path.insert(0,str(args.observe_r1515.resolve()/'validation'))

from verify_r1514_replays import OverlapTrial, ROLES
from verify_r1511_longshots import change_role
from verify_r157_receive import require

class WindowTrial(OverlapTrial):
    def official(self, value):
        self.db.execute('UPDATE t_horse_odds_finder_races SET start_time=?',(value,))

    def boundary(self, first, second):
        self.setup()
        self.close = self.sales-timedelta(minutes=2)  # official 14:58, sales close 14:57
        self.limit = self.close-timedelta(seconds=90)
        for role,target in zip(ROLES,(10,12)):
            change_role(self.role_rows[role],target)
            self.send(role)
        for role,delta in zip(ROLES,(first,second)):
            require(self.receive(role,self.limit+timedelta(microseconds=delta))['ok'],'original window accepted')
        self.now=self.limit+timedelta(seconds=5)

    def reference(self, close=None):
        packet=deepcopy(self.data)
        rows=[]
        for role in ROLES:
            row=deepcopy(self.role_rows[role])
            _,sent,received,complete=self.status[role]
            row['receipt'].update(sent_at=sent.isoformat(),received_at=received.isoformat(),complete=complete)
            rows.append(row)
        gone,count,_,state,response,publication=self.row()
        require(gone=='[]' and count>0,'active fixture')
        packet.update(roles=rows,now=self.now.isoformat(),send=False,cancelled=[])
        packet['live']=dict(race_id=packet['race_id'],snapshot_hash=packet['hash'],race_started=False,all_cancelled=False,
                            now=packet['now'],live_close_at=close.isoformat() if close else None,cancelled_horse_nos=[],
                            state_revision=state,response_revision=response,publication_revision=publication)
        packet['live']['role_journal']=self.replay('adopt',packet)['data']['role_journal']
        return packet,self.replay('open',packet)

    def fresh(self,deltas):
        packet,expected=self.reference(self.close)
        pub=self.publish_db()
        dto=self.get(clock=self.us(self.now))['body']
        if not any(d<=0 for d in deltas):
            require(expected.get('reason')=='W2_NO_VERIFIED_ROLE' and pub['code']=='NO_RESPONSE' and
                    pub['candidates']==[] and dto['publication'] is None,(expected.get('reason'),pub['code'],dto['state']))
            return
        require(expected['ok'],expected)
        canonical=expected['data']['result']
        require(self.replay('commit',dict(packet,selection=canonical,finished_at=packet['now']))['ok'],'W9 commit')
        picks=[h['horse_no'] for h in pub['candidates']]
        require(pub['code']=='PUBLISHED' and canonical['public_candidates']==picks==dto['live_state']['available_candidate_nos'],(pub['code'],picks))
        require([n for n in picks if n>=7]==[n for n,d in zip((10,12),deltas) if d<=0],picks)
        require(all(h['bonus']==canonical['agreement_bonus'][str(h['horse_no'])] for h in pub['candidates']) and not dto['buy_recommended'],'bonus and sale')
        require(self.publish_db()['publication_id']==pub['publication_id'],'repeat same publication')

    def during(self,deltas,workers=1,old=False):
        self.boundary(*deltas)
        packet,accepted=self.reference()
        require(accepted['ok'],accepted)
        base=accepted['data']['result']
        require(self.replay('commit',dict(packet,selection=base,finished_at=packet['now']))['ok'],'unchanged independent window')
        changed=deepcopy(packet);changed['live']['live_close_at']=self.close.isoformat()
        require(self.replay('commit',dict(changed,selection=base,finished_at=packet['now'])).get('reason')=='W8_PUBLICATION_CHANGED','W9 rejects changed window')
        before=self.unchanged()
        results=self.with_workers(workers,lambda:self.official('14:58'))
        dto=self.get(clock=self.us(self.now))['body']
        if old:
            require(results[0]['code']=='PUBLISHED' and dto['live_state']['available_candidate_nos']==[1,2,3,10,12],results[0]['code'])
            self.boundary(*deltas);self.official('14:58');self.fresh(deltas)
            print('R15.15 official 15:00 to 14:58 after inspection: SQL/GET 1,2,3,10,12 including one late role. Same change before inspection gives 1,2,4,10.',flush=True)
            return
        require(all(r['code']=='STALE' and not r['ok'] and r['publication_id'] is None and r['candidates']==[] for r in results),
                [(r['code'],r['publication_id'],len(r['candidates'])) for r in results])
        require(self.unchanged()==before and dto['publication'] is None,'no intent, publication, audit commit, extra provider call or visible list')
        self.fresh(deltas)

    def other_clocks(self):
        for new_time,code in [('15:00','PUBLISHED'),('15:05','PUBLISHED'),('14:57','DEADLINE_PASSED'),('XXX','RACE_STARTED'),('??','START_TIME_UNREADABLE')]:
            self.boundary(0,1)
            before=self.unchanged()
            out=self.with_workers(1,lambda:self.official(new_time))[0]
            require(out['code']==code,(new_time,out['code']))
            if code=='PUBLISHED':
                require([h['horse_no'] for h in out['candidates']]==[1,2,3,10,12],'effective cap unchanged')
            else:
                require(out['candidates']==[] and self.unchanged()==before,'rejected commit returned provisional rows or changed DB')
        self.boundary(0,1);self.official('14:58');self.update([],live_close_us=self.us(self.close))
        result=self.with_workers(1,lambda:self.official('15:05'))[0]
        require(result['code']=='PUBLISHED' and [h['horse_no'] for h in result['candidates']]==[1,2,4,10],result['code'])
        print('PASS frozen cap after postponement, earlier audited cap, expired publication, started race and unreadable official time.',flush=True)

    def transient(self):
        for role in ('2nd','1st'):
            self.boundary(*((0,1) if role=='2nd' else (1,0)))
            result=self.publish_db(transient_role=role)
            require(result['code']=='PUBLISHED' and [h['horse_no'] for h in result['candidates']]==[1,2,3,10,12],(role,result['code'],result['candidates']))
        print('PASS transient official earlier time observed only while inspecting either AI: one effective window for the whole calculation.',flush=True)

    def queue(self):
        for deltas,steps,second_late,code,rounds,outsiders in [
            ((0,1),['14:58'],False,'PUBLISHED',2,[10]),
            ((1,0),['14:58'],False,'PUBLISHED',2,[12]),
            ((1,1),['14:58'],False,'NO_RESPONSE',2,[]),
            ((0,1),['14:58','14:59'],False,'STALE',2,[]),
            ((0,1),['14:58'],True,'DEADLINE_PASSED',2,[]),
            ((0,1),['15:05'],False,'PUBLISHED',1,[10,12])]:
            self.boundary(*deltas)
            for role in ROLES:
                self.action('settle',role=role)
            calls_before=self.scalar('SELECT count(*) FROM provider_stub')
            clocks=[self.us(self.now)]
            if second_late:clocks.append(self.us(self.close-timedelta(seconds=60)+timedelta(microseconds=1)))
            result=self.action('when_ready',official_steps=steps,round_clocks=clocks)
            require(result['code']==code and result['test_rounds']==rounds,(code,result['code'],result['test_rounds']))
            if code=='PUBLISHED':self.check_public(result,outsiders)
            else:require(result['candidates']==[] and self.get(clock=self.us(self.now))['body']['publication'] is None,code)
            require(self.scalar('SELECT count(*) FROM provider_stub')==calls_before,'queue retry sent another paid request')
        print('PASS six normal queue cases: either single role, both late, bounded second clock change, fresh clock exhausted on retry and harmless postponement; no extra provider call.',flush=True)

    def reopened_wait(self):
        self.setup()
        self.close=self.sales-timedelta(minutes=2);limit=self.close-timedelta(seconds=90)
        for role,target in zip(ROLES,(10,12)):
            change_role(self.role_rows[role],target);self.send(role)
        require(self.receive('1st',limit)['ok'],'on-time first')
        self.now=limit+timedelta(seconds=5)
        self.action('settle',role='1st')
        self.official('14:58')
        # First pass can release the waiting role at the earlier cutoff. Before commit,
        # the source changes to 14:59 without a recorded earlier cap. The role is still SENT.
        runner=Path(__file__).resolve().parent/'r1512_publication.php'
        req=dict(cmd='when_ready',clock=self.us(self.now),official_steps=['14:59'])
        proc=subprocess.run([self.php,str(runner),str(self.path),'_',str(self.controller)],input=json.dumps(req),text=True,capture_output=True,check=True)
        messages=[json.loads(line)[0] for line in proc.stderr.splitlines()]
        require(messages==['[R122-WIRE] 応答期限を過ぎたので待たずに公開します'],messages)
        result=json.loads(proc.stdout)
        require(result['code']=='WAITING' and result['test_rounds']==2 and self.scalar('SELECT count(*) FROM baganriki_publications')==0,
                ('still waiting after revised window',result['code'],result['test_rounds']))
        self.now+=timedelta(seconds=1);require(self.receive('2nd',self.now)['ok'],'newly timely second role')
        self.action('settle',role='2nd');pub=self.action('when_ready',official_steps=[])
        require(pub['test_rounds']==1,'single fresh publication');self.check_public(pub,[10,12])
        print('PASS reopened response window: normal queue waits for the still-pending second AI, then publishes both certified outsiders with one ID.',flush=True)

    def corrected_receipts(self):
        for role in ROLES:
            for delta in (0,1):
                self.setup();target=12
                original=self.init_role(role,target,[(target,'evidence')])
                self.close=self.sales-timedelta(minutes=2);limit=self.close-timedelta(seconds=90)
                self.now=limit+timedelta(microseconds=delta)
                self.correction(role,original)
                self.now=limit+timedelta(seconds=5)
                stale=self.with_workers(1,lambda:self.official('14:58'))[0]
                require(stale['code']=='STALE' and stale['candidates']==[],'old correction not committed')
                result=self.publish_db()
                self.check_public(result,[target] if delta==0 else [],role,bonus_allowed=False)
        print('PASS four corrected-role boundaries: either AI can retain the rescued outsider at equality; a correction one microsecond late leaves verified original rows.',flush=True)

    def migration(self,controller,directory):
        original_layout(controller,directory)
        directory=directory.resolve()/'validation'
        current_publish=self.publish_db
        def previous_publish(**opt):
            request=dict(cmd='publish',clock=self.us(self.now),**opt)
            command=[self.php,str(directory/'r1512_publication.php'),str(self.path),'_',str(controller.resolve()),str(directory)]
            proc=subprocess.run(command,input=json.dumps(request),text=True,capture_output=True,check=True)
            require(not proc.stderr,proc.stderr)
            return json.loads(proc.stdout)
        for changed_during in (False,True):
            self.boundary(0,1)
            self.publish_db=previous_publish
            try:
                old=self.with_workers(1,lambda:self.official('14:58'))[0] if changed_during else self.publish_db()
            finally:self.publish_db=current_publish
            pid=old['publication_id'];require(old['code']=='PUBLISHED' and [h['horse_no'] for h in old['candidates']]==[1,2,3,10,12],'old publication')
            query='SELECT candidates FROM baganriki_publications WHERE publication_id=?'
            saved=self.scalar(query,pid);calls=self.scalar('SELECT count(*) FROM provider_stub')
            if not changed_during:
                retry=self.publish_db();require(retry['code']=='ALREADY' and retry['publication_id']==pid,'valid old ID')
                self.official('14:58')
            self.fresh((0,1))
            dto=self.get(clock=self.us(self.now))['body']
            require(dto['publication']['publication_id']!=pid and dto['live_state']['available_candidate_nos']==[1,2,4,10] and
                    self.scalar(query,pid)==saved and self.scalar('SELECT count(*) FROM provider_stub')==calls,'migrated results')
        print('PASS two real R15.15 to R15.16 database transitions: valid prior decision and reproduced prior race; fresh clock restores 1,2,4,10, preserves the prior bytes, zero extra provider calls.',flush=True)

    def history(self):
        self.boundary(0,1)
        original=self.publish_db();pid=original['publication_id'];rows=original['candidates']
        require(original['code']=='PUBLISHED','history setup')
        self.official('14:58')
        previous=self.get(clock=self.us(self.now))['body']['publication']
        require(previous['publication_id']==pid and previous['candidates']==rows,'history kept')
        self.fresh((0,1))
        require(self.scalar('SELECT count(*) FROM baganriki_publications')==2 and
                self.get(clock=self.us(self.now))['body']['publication']['publication_id']!=pid,'new decision new ID')
        print('PASS an already valid publication retains its prior ID and ordered rows; a fresh decision at the revised cutoff uses a new ID.',flush=True)

def smoke(php,controller):
    with TemporaryDirectory() as tmp:
        trial=WindowTrial(str(php),Path(controller).resolve(),Path(tmp))
        try:trial.during((0,1))
        finally:trial.db.close()
    print('PASS R15.16 standard: locked effective deadlines, exact receipt boundary and verified single-role refresh.',flush=True)

if __name__=='__main__':
    with TemporaryDirectory() as tmp:
        trial=WindowTrial(args.php,args.controller.resolve(),Path(tmp))
        try:
            if args.observe_r1515:trial.during((0,1),old=True)
            elif args.upgrade_from:trial.migration(*args.upgrade_from)
            else:
                for first in (-1,0,1):
                    for second in (-1,0,1):
                        trial.during((first,second),4 if (first,second)==(1,0) else 1)
                print('PASS 9 pairs of -1/0/+1 microsecond receipts, either independent outsider, both on time, both late, four concurrent writers and fresh retries.',flush=True)
                trial.other_clocks();trial.transient();trial.history();trial.queue();trial.reopened_wait();trial.corrected_receipts()
        finally:trial.db.close()
