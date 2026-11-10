"""Exercise the normal publication queue while independent workers reserve, send, receive, settle, or withdraw."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
from hashlib import sha256
from itertools import permutations
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import time

HERE=Path(__file__).resolve().parent
PREVIOUS='307de288f521ac1573b37131ff081ef22e35ed3c8ff13c4cf441d8f9fe847d1d'
MANIFEST='23b6f95ad467c07be459a1c0f9c50c048ae7d7a7edecbb637639c45a7c937336'
def previous(controller,folder):
    if sha256(controller.read_bytes()).hexdigest()!=PREVIOUS or sha256((folder/'SHA256SUMS.txt').read_bytes()).hexdigest()!=MANIFEST:
        raise ValueError('Original R15.16 controller and W2 directory required')
    for line in (folder/'SHA256SUMS.txt').read_text().splitlines():
        if sha256((folder/line[66:]).read_bytes()).hexdigest()!=line[:64]:raise ValueError(line[66:])

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('php');parser.add_argument('controller',type=Path)
    group=parser.add_mutually_exclusive_group();group.add_argument('--observe-r1516',type=Path,metavar='ORIGINAL_W2')
    group.add_argument('--upgrade-from',type=Path,nargs=2,metavar=('ORIGINAL_CONTROLLER','ORIGINAL_W2'))
    group.add_argument('--deadline-edges',action='store_true')
    args=parser.parse_args()
    if args.observe_r1516:
        previous(args.controller,args.observe_r1516);sys.path.insert(0,str(args.observe_r1516.resolve()/'validation'))
from verify_r1516_window import WindowTrial,ROLES,change_role,require
from verify_r157_receive import dump

TARGET={'1st':10,'2nd':12}

class QueueTrial(WindowTrial):
    def committed(self):
        return tuple(self.scalar('SELECT count(*) FROM '+table) for table in (
            'baganriki_publications','baganriki_publication_intents','provider_stub'))+(self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='PUBLISHED'"),)

    def request(self,cmd,**opt):
        directory=getattr(self,'layout',HERE)
        payload=dict(cmd=cmd,clock=self.us(self.now),server_clock=True,**opt)
        run=subprocess.run([self.php,str(HERE/'r1512_publication.php'),str(self.path),'_',str(self.controller),str(directory)],
                           input=json.dumps(payload),text=True,capture_output=True,check=True)
        for line in run.stderr.splitlines():
            require(json.loads(line)[0]=='[R122-WIRE] 応答期限を過ぎたので待たずに公開します',line)
        return json.loads(run.stdout)

    def barrier(self,event,workers=1,phase='handoff_barrier'):
        with TemporaryDirectory(dir=self.directory) as tmp,ThreadPoolExecutor(workers) as pool:
            gate=Path(tmp);started=self.now;resume=started+timedelta(microseconds=5)
            jobs=[pool.submit(self.request,'when_ready',**{phase:str(gate)},resume_clock=self.us(resume)) for _ in range(workers)]
            try:
                expiry=time.monotonic()+40
                while len(list(gate.glob('ready-*')))<workers:
                    for job in jobs:
                        if job.done():raise AssertionError(('early return',job.result()['code']))
                    require(time.monotonic()<expiry,'publication worker did not reach barrier')
                    time.sleep(.004)
                event(started)
            finally:(gate/'continue').touch()
            result=[job.result() for job in jobs]
        self.now=resume
        return result

    def begin_fix(self,role,at):
        self.now=at
        out=self.worker('reserve',at,race=1,role=role,slot=1)
        require(out['ok'],(role,out));return out['token']
    def send_fix(self,token,at):
        self.now=at
        require(self.worker('send',at,token=token)['ok'],'correction send')
        self.db.execute('UPDATE baganriki_ai_attempts SET id=rowid,sent_at=? WHERE token=?',(at.isoformat(timespec='microseconds'),token))
    def get_fix(self,token,raw,at):
        self.now=at
        out=self.worker('receive',at,token=token,raw=raw,complete=True);require(out['ok'],out)
    def settle(self,role):
        require(self.action('settle',role=role)['marked'],role)

    def prepare(self,broken):
        self.setup();self.now=self.start+timedelta(seconds=4);healthy={}
        for role in ROLES:
            target=TARGET[role];healthy[role]=self.init_role(role,target,[(target,'evidence')] if role in broken else [])
            self.settle(role)
        self.now=self.start+timedelta(seconds=8)
        return healthy

    def change_at_gate(self,role,stage='sent',withdraw=None,workers=1,history=False,old=False,phase='handoff_barrier'):
        healthy=self.prepare((role,));other=ROLES[1-ROLES.index(role)]
        historical=self.request('when_ready') if history else None
        prior=self.committed();tokens=[];after_event=[]
        def change(at):
            token=self.begin_fix(role,at+timedelta(microseconds=1));tokens.append(token)
            if stage!='reserved':self.send_fix(token,at+timedelta(microseconds=2))
            if stage in ('received','settled'):self.get_fix(token,healthy[role],at+timedelta(microseconds=3))
            if stage=='settled':self.settle(role)
            if withdraw is not None:require(self.update([withdraw])['code']=='APPLIED','official cancellation')
            after_event.append(self.committed())
        outcomes=self.barrier(change,workers,phase)
        dto=self.get(clock=self.us(self.now))['body']
        if old:
            require(outcomes[0]['code']=='PUBLISHED' and [h['horse_no'] for h in outcomes[0]['candidates']]==[1,2,3,10] and
                    dto['live_state']['available_candidate_nos']==[1,2,3,10] and self.request('when_ready')['code']=='WAITING','old ordinary queue wrote before the correction')
            print('R15.16 pending correction at normal queue handoff: SQL and GET 1,2,3,10; next identical job WAITING; corrected final list 1,2,3,10,12.',flush=True)
        elif stage=='settled' and phase=='handoff_barrier':
            require(sorted(r['code'] for r in outcomes)==['ALREADY']*(workers-1)+['PUBLISHED'] and
                    len({r['publication_id'] for r in outcomes})==1 and all(r['candidates']==outcomes[0]['candidates'] for r in outcomes),'settled at entry')
            count=self.committed()
            require(count==(prior[0]+1,prior[1]+1,after_event[0][2],prior[3]+1),'one publication, intent and audit event without another provider call')
        else:
            required='WAITING' if phase=='handoff_barrier' else 'STALE'
            require(all(r['code']==required and not r['ok'] and not r.get('candidates') and not r.get('publication_id') for r in outcomes),
                    (role,stage,phase,[r['code'] for r in outcomes]))
            require(self.committed()==after_event[0] and after_event[0][:2]==prior[:2] and after_event[0][3]==prior[3],
                    ('new publication/intent/event/call while still waiting',prior,after_event,self.committed()))
            if historical:
                require(dto['publication']['publication_id']==historical['publication_id'] and dto['publication']['candidates']==historical['candidates'],'keep prior confirmed publication')
                require(dto['live_state']['available_candidate_nos']==[h['horse_no'] for h in historical['candidates'] if h['horse_no']!=withdraw],dto['state'])
            else:require(dto['publication'] is None and dto['state']=='PENDING',dto['state'])
        self.now+=timedelta(seconds=1);token=tokens[0]
        if stage=='reserved':self.send_fix(token,self.now);self.now+=timedelta(microseconds=1)
        if stage in ('reserved','sent'):self.get_fix(token,healthy[role],self.now)
        if stage!='settled':self.settle(role)
        final=self.request('when_ready');fresh=self.get(clock=self.us(self.now))['body']
        picks=[h['horse_no'] for h in final['candidates']]
        require(final['code'] in ('PUBLISHED','ALREADY') and
                [n for n in picks if n>=7]==[n for n in (10,12) if n!=withdraw] and fresh['live_state']['available_candidate_nos']==picks and not fresh['buy_recommended'],(final['code'],picks))
        require(final['candidates']==fresh['publication']['candidates'] and all(h['bonus']==0 for h in final['candidates'] if h['horse_no']>=7),'actual saved rows and independent outsiders')
        require(self.request('when_ready')['publication_id']==final['publication_id'],'idempotent settled queue')
        if historical:
            stored=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',historical['publication_id'])
            require(json.loads(stored)==historical['candidates'],'saved historical bytes')
        return final

    def clock_gap(self,pending,cap=False,old=False):
        self.setup();on_time=ROLES[1-ROLES.index(pending)];close=self.sales-timedelta(minutes=2);limit=close-timedelta(seconds=90)
        for role in ROLES:
            change_role(self.role_rows[role],TARGET[role]);self.send(role)
        require(self.receive(on_time,limit)['ok'],'on-time first receipt');self.now=limit+timedelta(seconds=5);self.settle(on_time)
        self.official('14:58')
        if cap:require(self.update([],live_close_us=self.us(close))['code']=='APPLIED','audited window')
        before=self.committed()
        out=self.barrier(lambda at:self.official('14:59'))[0]
        if old or cap:
            self.check_public(out,[TARGET[on_time]])
            if old:
                require(self.request('when_ready')['code']=='WAITING','old queue waited only on a fresh call')
                print('R15.16 official 14:58 to 14:59 at initial queue handoff: single role was published while the second could still arrive.',flush=True)
        else:
            require(out['code']=='WAITING' and self.committed()==before and self.get(clock=self.us(self.now))['body']['publication'] is None,'wait while window is open')
            self.now+=timedelta(seconds=1);require(self.receive(pending,self.now)['ok'],'timely second role');self.settle(pending)
            self.check_public(self.request('when_ready'),[10,12])
    def permutations(self,order,targets,withdraw):
        self.setup();self.now=self.start+timedelta(seconds=4)
        grants={};healthy={};tokens={};history={}
        self.db.execute('CREATE TABLE IF NOT EXISTS r1513_fix_dispatch(role TEXT PRIMARY KEY,sent TEXT)')
        for role in ROLES:
            target=targets[role]
            healthy[role]=self.init_role(role,target,[(target,'evidence')]);grants[role]=self.inspect(role,with_grant=True);self.settle(role)
            row=self.role_rows[role];first=self.scalar('SELECT raw_response FROM baganriki_ai_attempts WHERE role=? AND attempt_no=0',role)
            dispatch=self.replay('record_correction_fixture',dict(common=self.data['common'],input=row['input'],hash=self.data['hash'],witness=self.data['witness'],
                role=role,original=first,format=row['receipt']['sent_request']['format'],scope=grants[role]['scope'],diagnostics=grants[role]['diagnostics']))
            require(dispatch['ok'],dispatch)
            self.db.execute('INSERT OR REPLACE INTO r1513_fix_dispatch(role,sent) VALUES(?,?)',(role,dump(dispatch['data'])))
        first=self.request('when_ready');self.check_public(first,[])
        latest=first['publication_id'];history[latest]=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',latest)
        for i,role in enumerate(ROLES):
            at=self.start+timedelta(seconds=5,microseconds=2*i)
            tokens[role]=self.begin_fix(role,at);self.send_fix(tokens[role],at+timedelta(microseconds=1))
        complete=set();gone=set();calls=self.scalar('SELECT count(*) FROM provider_stub')
        for i,event in enumerate(order):
            self.now=self.start+timedelta(seconds=7+i)
            if event=='withdraw':
                require(self.update([withdraw])['code']=='APPLIED' and self.update([withdraw])['code']=='NO_CHANGE','official withdrawal and duplicate')
                gone.add(withdraw)
            else:
                self.get_fix(tokens[event],healthy[event],self.now);self.settle(event);complete.add(event)
            prev=json.loads(history[latest]);before=self.committed();view=self.get(clock=self.us(self.now))['body']
            require(view['publication']['publication_id']==latest and view['publication']['candidates']==prev and
                    view['live_state']['available_candidate_nos']==[h['horse_no'] for h in prev if h['horse_no'] not in gone],'historical vs active roster')
            new=self.request('when_ready')
            if len(complete)<2:
                require(new['code']=='WAITING' and before==self.committed(),'unsettled corrections do not replace prior publication')
                continue
            reference=self.from_journal(grants)
            picks=self.check_public(new,sorted(set(targets.values())-gone))
            require(picks==reference['public_candidates'] and new['actual_quota']==reference['actual_quota'] and new['shortfalls']==reference['shortfalls'],'independent replay by saved receipts')
            for h in new['candidates']:
                n=str(h['horse_no']);proof=reference['public_proof_hashes']
                require(h['bonus']==reference['agreement_bonus'][n] and h['public_proof_hash']==(proof.get(n) if isinstance(proof,dict) else None),'exact proof and agreement')
                if h['horse_no'] in targets.values():
                    require(h['bonus']==(5 if len(set(targets.values()))==1 else 0),'independent vs common nomination')
            latest=new['publication_id'];require(new['code']=='PUBLISHED' and latest not in history,'new decision and ID')
            history[latest]=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',latest)
            repeated=self.request('when_ready');require(repeated['code']=='ALREADY' and repeated['publication_id']==latest,'existing confirmed ID')
        require(self.scalar('SELECT COUNT(*) FROM provider_stub')==calls and self.scalar('SELECT COUNT(*) FROM baganriki_publications')==len(history),'no extra calls or publications')
        for ident,raw in history.items():
            require(self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',ident)==raw,'previous publication row changed')

    def matrix(self):
        for targets in ({'1st':10,'2nd':12},{'1st':12,'2nd':12}):
            for gone in (2,12):
                for order in permutations(('1st','2nd','withdraw')):
                    self.permutations(order,targets,gone)
        print('PASS 24 event sequences: six orders of two settled corrections and an official withdrawal for separate or common outsiders and cancellation of a favourite or the outsider; SQL, W9 proofs/bonuses and active GET all agree.',flush=True)

    def boundaries(self):
        for role in ROLES:
            for stage in ('reserved','sent','received','settled'):
                self.change_at_gate(role,stage=stage,workers=4 if stage=='sent' else 1)
            self.change_at_gate(role,stage='sent',withdraw=TARGET[role],history=True)
            self.change_at_gate(role,stage='sent',withdraw=2,history=True)
            self.change_at_gate(role,stage='sent',phase='rendezvous')
            self.clock_gap(role);self.clock_gap(role,cap=True)
        print('PASS both roles through reserve/send/receive-unsettled/adopt at the queue handoff; four parallel queues, existing history, official cancellations, post-selection conflicts, either reopened window and the audited earlier cap.',flush=True)

    def limits(self):
        for active in ROLES:
            pending=ROLES[1-ROLES.index(active)]
            for delta in (-1,0,1):
                self.setup()
                for role in ROLES:
                    change_role(self.role_rows[role],TARGET[role]);self.send(role)
                require(self.receive(active)['ok'],'initial timely receipt');self.settle(active)
                self.now=self.sales-timedelta(seconds=90)+timedelta(microseconds=delta)
                before=self.committed();out=self.request('when_ready')
                if delta<0:
                    require(out['code']=='WAITING' and pending in ''.join(out['waiting']) and self.committed()==before and self.get(clock=self.us(self.now))['body']['publication'] is None,'one microsecond still available to pending role')
                else:self.check_public(out,[TARGET[active]],bonus_allowed=False)
        self.setup()
        for role in ROLES:self.send(role)
        require(self.update(self.nums)['code']=='APPLIED','all runners cancelled')
        before=self.committed();out=self.request('when_ready');dto=self.get()['body']
        require(out['code']=='ALL_CANCELLED' and dto['state']=='CANCELLED' and dto['publication'] is None and self.committed()==before,'all withdrawn while waiting')
        for role in ROLES:self.change_at_gate(role,stage='settled',workers=4)
        print('PASS four concurrent normal queue jobs after either role settles: one publication, one event, one ID; three idempotent replies and no extra send.',flush=True)
        print('PASS six pending-role cutoff edges (-1/0/+1 microsecond for either role) plus all runners withdrawn: hold early, publish the timely sole outsider at equality or later, cancel without new writes.',flush=True)

    def migration(self,controller,folder):
        previous(controller,folder)
        current=self.controller;self.controller=controller;self.layout=folder.resolve()/'validation'
        healthy=self.prepare(('2nd',));tokens=[]
        def pending(at):
            token=self.begin_fix('2nd',at+timedelta(microseconds=1));tokens.append(token);self.send_fix(token,at+timedelta(microseconds=2))
        old=self.barrier(pending)[0];pid=old['publication_id'];require(old['code']=='PUBLISHED' and [h['horse_no'] for h in old['candidates']]==[1,2,3,10],'genuine prior-version body')
        query='SELECT candidates FROM baganriki_publications WHERE publication_id=?';original=self.scalar(query,pid);before=self.committed()
        self.controller=current;self.layout=HERE
        held=self.request('when_ready');require(held['code']=='WAITING' and self.committed()==before and self.get()['body']['publication']['publication_id']==pid,'keep existing recorded history while pending')
        self.now+=timedelta(seconds=1);self.get_fix(tokens[0],healthy['2nd'],self.now);self.settle('2nd')
        fresh=self.request('when_ready');self.check_public(fresh,[10,12])
        require(fresh['publication_id']!=pid and self.scalar(query,pid)==original and self.scalar('SELECT count(*) FROM provider_stub')==before[2],'history, new ID, zero new sends')
        print('PASS genuine R15.16 to R15.17 on one database: existing ID kept in history, still-open correction waits, fresh confirmed ID contains 10 and 12, no further provider call.',flush=True)

    def basic(self,old=False):
        self.change_at_gate('2nd',old=old);self.clock_gap('2nd',old=old)
        if not old:print('PASS R15.17 standard: no early public ID on a pending correction or a reopened reply window; waiting outsiders reach SQL and GET.',flush=True)

def smoke(php,controller):
    with TemporaryDirectory() as folder:
        t=QueueTrial(str(php),Path(controller).resolve(),Path(folder))
        try:t.basic()
        finally:t.db.close()


if __name__=='__main__':
    with TemporaryDirectory() as tmp:
        t=QueueTrial(args.php,args.controller.resolve(),Path(tmp))
        try:
            if args.observe_r1516:t.layout=args.observe_r1516.resolve()/'validation';t.basic(old=True)
            elif args.deadline_edges:t.limits()
            elif args.upgrade_from:t.migration(args.upgrade_from[0].resolve(),args.upgrade_from[1].resolve())
            else:t.basic();t.boundaries();t.matrix()
        finally:t.db.close()
