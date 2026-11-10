"""Replay cumulative withdrawals and clock changes through the submitted live writer and worker."""
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
from datetime import timedelta
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import time

from verify_r157_receive import Trial, db_time, dump, require, microseconds

HERE = Path(__file__).resolve().parent

class LiveTrial(Trial):
    def __init__(self, php, controller, directory):
        super().__init__(php, controller, directory, fixture_kind='--live-writer-fixture')
        self.db.execute('ALTER TABLE baganriki_snapshots ADD COLUMN generation INTEGER DEFAULT 0')
        self.db.execute('ALTER TABLE baganriki_audit_events ADD COLUMN id INTEGER')
        self.db.execute('CREATE TABLE queued_changes(snapshot_id INTEGER,change_json TEXT)')
        for key in ['input_json_1st','input_json_2nd','allocation_mode']:
            self.db.execute('ALTER TABLE baganriki_snapshots ADD COLUMN '+key+' TEXT')
        self.db.execute('ALTER TABLE baganriki_live_states ADD COLUMN latest_publication_id TEXT')
        self.db.execute('CREATE TABLE baganriki_publication_intents(id INTEGER PRIMARY KEY,snapshot_id,job_id,intent_hash,read_state_revision,read_response_revision,read_publication_revision,state,publication_id,created_at)')
        self.db.execute('CREATE TABLE baganriki_publications(publication_id,snapshot_id,intent_id,published_at,prediction_target_count,prediction_quota,publication_target_count,publication_quota,active_count,candidates,evidence,shortfalls,used_state_revision,used_response_revision,public_proof_hash)')
    def call(self,cmd,**kw):
        result=subprocess.run([self.php,str(HERE/'r159_live_writer.php'),str(self.path),'_',str(self.controller)],
                              input=dump(dict(cmd=cmd,**kw)),text=True,capture_output=True,check=True)
        return json.loads(result.stdout)
    def row(self):
        return self.db.execute('SELECT cancellations,active_count,live_close,state_revision,response_revision,publication_revision FROM baganriki_live_states').fetchone()
    def update(self,nums,**extra):
        return self.call('update',change=dict(cancellations=nums,**extra))
    def packet(self, now, tokens=None):
        prior=self.row()
        roster=self.call('roster')['code']
        if roster not in ['OPEN','ALL_CANCELLED']:
            return {'ok':False,'reason':roster},None
        p=deepcopy(self.data)
        if tokens is not None:
            rows=[]
            for r in p['roles']:
                got=self.db.execute("SELECT raw_response,response_hash,received_at,complete,status FROM baganriki_ai_attempts WHERE token=? AND status='RECEIVED'",(tokens[r['role']],)).fetchone()
                if got:
                    r['original']=got[0];r['receipt'].update(response_hash=got[1],received_at=got[2].replace(' ','T')+'+09:00',complete=got[3],status=got[4]);rows.append(r)
            p['roles']=rows
        p['now']=now.isoformat(timespec='microseconds');gone=json.loads(prior[0]);close=prior[2]
        p.update(send=False,cancelled=gone,live=dict(state_revision=prior[3],response_revision=prior[4],publication_revision=prior[5],cancelled_horse_nos=gone,
                 race_id=p['race_id'],snapshot_hash=p['hash'],now=p['now'],live_close_at=None if close is None else close.replace(' ','T')+'+09:00',all_cancelled=prior[1]==0,race_started=False))
        p['live']['role_journal']=self.replay('adopt',p)['data']['role_journal']
        opened=self.replay('open',p)
        if not opened['ok']:return opened,p
        selection=opened['data']['result'];commit=self.replay('commit',dict(p,roles=self.data['roles'],selection=selection,finished_at=p['now']))
        return dict(commit,candidates=selection['public_candidates'],selection=selection),p
    def reproduce(self,old):
        self.reset()
        require(self.update([1,2])['code']=='APPLIED','seed withdrawals')
        self.db.execute("UPDATE baganriki_live_states SET cancellations='[2]'")
        require(self.call('roster')['code']=='LIVE_STATE_UNAVAILABLE','precondition')
        before=self.row()
        raw=dump([dict(num=5,tan='取消')])
        result=self.call('odds',raw=raw)
        state=self.call('roster')['code']
        pub,_=self.packet(self.start+timedelta(seconds=10))
        print('R15.8' if old else 'R15.9', 'previous=[1,2] stored=[2] active=14 further=[5]', 'code='+str(result['code']),
              'after='+self.row()[0], 'roster='+state, 'committable='+str(pub['ok']), 'candidates='+str(pub.get('candidates',[])))
        if old:require(result['code']=='APPLIED' and state=='OPEN' and pub['ok'] and 1 in pub['candidates'],'old failure reproduced')
        else:require(result['code']=='LIVE_STATE_UNAVAILABLE' and state=='LIVE_STATE_UNAVAILABLE' and not pub['ok'] and self.row()==before,'fixed outcome')
        self.reset()
        fake=self.update(['1x']);print('invalid horse "1x":',fake['code'],'stored='+self.row()[0])
        if old:require(fake['code']=='APPLIED' and self.row()[0]=='[1]','old coerces malformed horse')
        else:require(fake['code']=='INVALID_LIVE_CHANGE' and self.row()[0]=='[]','no coerced horse')
    def direct_commit(self,old):
        counter=0
        def setup():
            self.reset()
            self.db.execute('DELETE FROM baganriki_publication_intents')
            self.db.execute('DELETE FROM baganriki_publications')
            first=json.loads(self.rows['1st']['input'])
            self.db.execute('UPDATE baganriki_snapshots SET input_json_1st=?,input_json_2nd=?,snapshot_hash=?,allocation_mode=?,quota_1st=?',
                            (self.rows['1st']['input'],self.rows['2nd']['input'],self.data['hash'],first['allocation_mode'],dump(first['quota'])))
            require(self.update([2])['code']=='APPLIED','baseline withdrawal')
        clock=self.start+timedelta(seconds=5)
        us=microseconds(clock-clock.fromtimestamp(0,clock.tzinfo))
        for update,expected in [(None,'PUBLISHED'),({'active_count':None},'LIVE_STATE_UNAVAILABLE'),
                                ({'cancellations':'[2]','active_count':14},'LIVE_STATE_UNAVAILABLE'),
                                ({'cancellations':'[1]','active_count':15},'LIVE_STATE_UNAVAILABLE'),
                                ({'cancellations':dump(self.nums),'active_count':0},'LIVE_STATE_UNAVAILABLE')]:
            setup()
            args={} if update is None else {'overlay':update}
            pub=self.call('publish',clock=us,**args)
            count=self.scalar('SELECT count(*) FROM baganriki_publications')
            actual=self.db.execute('SELECT candidates FROM baganriki_publications').fetchone()
            if old:
                require(pub['code']=='PUBLISHED' and count==1,('old actual write',update,pub))
                if update and update.get('cancellations')=='[1]':
                    nums=[h['horse_no'] for h in json.loads(actual[0])]
                    require(1 in nums,('old actual cancelled pick',nums))
                    print('R15.8 locked write: same count, cancellation 2 changed to 1 without a revision. Stored candidate numbers',nums)
            else:
                require(pub['code']==expected and count==int(expected=='PUBLISHED')
                        and self.scalar('SELECT count(*) FROM baganriki_publication_intents')==count
                        and self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='PUBLISHED'")==count,('locked write',expected,pub['code'],update))
            counter+=1
        setup();valid=self.call('publish',clock=us);require(valid['code']=='PUBLISHED','existing good publication')
        replay=self.call('publish',clock=us,overlay={'active_count':None})
        require(replay['code']=='ALREADY' and replay['publication_id']==valid['publication_id']
                and self.scalar('SELECT count(*) FROM baganriki_publications')==1,'immutable publication history')
        print(f'PASS {counter} submitted publishPrediction SQL writes or stops, plus immutable completed-intent replay (stored rows verified).')
    def queued(self,old):
        for path in ('JOB','SYNC'):
            self.reset()
            self.db.execute('DELETE FROM queued_changes')
            self.db.execute("INSERT INTO baganriki_audit_events(snapshot_id,kind,diagnostics,related_id) VALUES(1,'R122_PATH_FIXED',?,?)",(dump({'path':path}),path))
            tokens={role:self.allowed(role,0) for role in ['1st','2nd']}
            now=self.start+timedelta(seconds=4)
            for role in tokens:
                require(self.worker('receive',now,token=tokens[role],raw=self.rows[role]['original'],complete=True)['code']=='OK','stored role')
            before,p=self.packet(now,tokens);require(before['ok'] and 1 in before['candidates'],'patient candidate')
            odds=self.call('odds',raw=dump([{'num':1,'tan':'除外'}]),jobs=True)
            fresh,p=self.packet(now,tokens)
            waiting=self.scalar('SELECT count(*) FROM queued_changes')
            stale=self.replay('commit',dict(p,roles=self.data['roles'],selection=before['selection'],finished_at=p['now']))
            print('queue',path,odds['code'],'jobs='+str(waiting),'withdrawn_1_in_candidates='+str(1 in fresh['candidates']),'old_commit='+str(stale['ok']))
            if old and path=='JOB':
                require(odds['code']=='JOB_DISPATCHED' and waiting==1 and fresh['ok'] and 1 in fresh['candidates'] and stale['ok'],'R15.8 pending-job gap')
                pending=json.loads(self.scalar('SELECT change_json FROM queued_changes'))
                require(self.call('update',change=pending)['code']=='APPLIED','eventual async apply')
            else:
                require(odds['code']=='APPLIED' and waiting==0 and fresh['ok'] and 1 not in fresh['candidates'] and not stale['ok'],'cancellation visible before return')
            repeat=self.call('odds',raw=dump([{'num':1,'tan':'取消'}]),jobs=True)
            require(repeat['code']=='NO_CHANGE' and self.row()[3]==1 and self.scalar('SELECT count(*) FROM provider_stub')==2,'no duplicate work')
        if not old:
            self.reset();self.db.execute('DELETE FROM queued_changes')
            self.db.execute("UPDATE baganriki_snapshots SET release_version='legacy'")
            self.db.execute("INSERT INTO baganriki_audit_events(snapshot_id,kind,diagnostics,related_id) VALUES(1,'R122_PATH_FIXED','{}','JOB')")
            old_queue=self.call('odds',raw=dump([{'num':3,'tan':'取消'}]),jobs=True)
            require(old_queue['code']=='JOB_DISPATCHED' and self.scalar('SELECT count(*) FROM queued_changes')==1,'legacy queue compatible')
        print('PASS JOB/SYNC odds ingestion and same-race publication before queued work, repeated notifications and provider count.')
    def saved_matrix(self):
        cases = [('cancellations',v) for v in [None,'null','[','{}','{"0":1}','"1"','[1,1]','[true]','[1.0]','["1x"]','[17]','[0]','[2]']]
        cases += [('active_count',v) for v in [None,-1,0,13,15,17,'14x']]
        cases += [('frozen_nums',v) for v in [None,'null','{}','[1,1,2]','[true,2]','[1,2,17,19]','[1,2]']]
        total=0
        for field,value in cases:
            self.reset();require(self.update([1,2])['code']=='APPLIED','seed')
            table='baganriki_snapshots' if field=='frozen_nums' else 'baganriki_live_states'
            self.db.execute('UPDATE '+table+' SET '+field+'=?',(value,))
            saved=self.row();events=self.scalar('select count(*) from baganriki_audit_events')
            require(self.call('roster')['code']=='LIVE_STATE_UNAVAILABLE',(field,value))
            for cmd,args in [('update',dict(change={'cancellations':[]})),('update',dict(change={'cancellations':[5],'live_close_us':self.earlier})),
                             ('odds',dict(raw=dump([{'num':2,'tan':'取消'}]))),('odds',dict(raw=dump([{'num':5,'tan':'除外'}])))]:
                result=self.call(cmd,**args)
                require(result['code']=='LIVE_STATE_UNAVAILABLE' and before_equal(self,saved,events),(field,value,cmd,result))
                total+=1
        print(f'PASS {total} inconsistent stored-roster/withdrawal updates: exact row and audit preserved, acquisition and locked write paths.')
    def incoming_matrix(self):
        bad=['1x','01','+1',' 1','1.0','1e0',0,-1,17,19,1.5,True,False,None,[],{'n':1}]
        changes=[{'cancellations':x,'live_close_us':self.earlier} for x in (None,True,5,'5',{'x':2})]
        changes += [{'cancellations':arr,'live_close_us':self.earlier} for x in bad for arr in ([x],[5,x],[x,5])]
        changes += [{'cancellations':[5],'live_close_us':x} for x in (0,-1,'03','+1234',' 1234','1x','1e6','4.0',False,True,[],{'v':1},1.5,'1000000000000000000000000')]
        self.reset();self.update([1,2]);saved=self.row();events=self.scalar('select count(*) from baganriki_audit_events')
        for change in changes:
            result=self.call('update',change=change)
            require(result['code']=='INVALID_LIVE_CHANGE' and before_equal(self,saved,events),(change,result))
        clock_cases=0
        for column,value in [('live_close','2026-02-30 09:00:00'),('live_close','bad'),('live_close',''),
                             ('sales_close_at',None),('sales_close_at','2026-10-17 25:00:00')]:
            self.reset()
            table='baganriki_snapshots' if column=='sales_close_at' else 'baganriki_live_states'
            self.db.execute('UPDATE '+table+' SET '+column+'=?',(value,))
            saved=self.row();events=self.scalar('select count(*) from baganriki_audit_events')
            for ns in ([],[5]):
                result=self.update(ns,live_close_us=self.earlier)
                require(result['code']=='TIME_UNCOMPUTABLE' and before_equal(self,saved,events),(column,value,ns,result))
                clock_cases+=1
        print(f'PASS {len(changes)} malformed deltas and {clock_cases} unreadable saved clocks: no partial write, revision, or event.')
    def sequences(self):
        total=0
        for order in [self.nums,self.nums[::-1],self.nums[::2]+self.nums[1::2]]:
            self.reset();seen=set();rev=0
            for n in order:
                for vals in ([str(n),n],[str(n)],[]):
                    newly=not set(map(int,vals)).issubset(seen);rev+=int(newly);seen.update(map(int,vals))
                    res=self.update(vals);live=self.row();events=self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='R122_LIVE_APPLIED'")
                    require(res['code']==('APPLIED' if newly else 'NO_CHANGE') and json.loads(live[0])==sorted(seen)
                            and live[1]==16-len(seen) and live[3]==events==rev,(vals,live,rev))
                    total+=1
            require(self.call('roster')['code']=='ALL_CANCELLED','terminal withdrawals')
        self.reset()
        for n in (12,3,12):
            res=self.call('odds',raw=dump([{'num':n,'tan':'除外'},{'num':1,'tan':7.6}]))
            require(res['code']==('NO_CHANGE' if n==12 and self.row()[3]==2 else 'APPLIED'),('odds replay',n,res))
        require(self.row()[:2]==('[3,12]',14),'odds accumulation')
        self.reset()
        for delta,want in [({},'NO_CHANGE'),({'live_close_us':str(self.earlier)},'APPLIED'),({'live_close_us':self.earlier+1},'NO_CHANGE'),
                           ({'live_close_us':self.earlier-1},'APPLIED'),({'live_close_us':None,'cancellations':['3',3]},'APPLIED')]:
            require(self.call('update',change=delta)['code']==want,('clock sequencing',delta,want))
        print(f'PASS {total} cumulative, duplicate and reordered withdrawal updates across three 16-horse races; 3 odds imports and 5 clock updates.')
    def races(self):
        groups=0
        for rep in range(6):
            self.reset();start=time.time()+.10
            deltas=[{'cancellations':[1,3],'live_close_us':self.earlier+i} for i in range(2)]+[{'cancellations':[2,3],'live_close_us':self.earlier+2},{'cancellations':[13,16],'live_close_us':self.earlier-1}]
            with ThreadPoolExecutor(max_workers=4) as pool:
                results=[f.result() for f in [pool.submit(self.call,'update',start=start,change=d) for d in deltas]]
            live=self.row();accepted=sum(v['code']=='APPLIED' for v in results)
            require(all(v['code'] in ['APPLIED','NO_CHANGE'] for v in results) and live[0]=='[1,2,3,13,16]' and live[1]==11 and live[3]==accepted==self.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='R122_LIVE_APPLIED'"),('concurrent',results,live))
            expected=db_time(self.sales-timedelta(seconds=5,microseconds=1));require(live[2]==expected,'earliest clock')
            groups+=1
        print(f'PASS {groups} simultaneous four-process update groups: cumulative withdrawals, earliest deadline and revisions matched.')
    def delayed(self):
        groups=0
        for first in ['1st','2nd']:
            second='2nd' if first=='1st' else '1st'
            for delay in [-1,0,1]:
                self.reset();t0=self.start+timedelta(seconds=2)
                ts={r:self.allowed(r,0) for r in [first,second]}
                def reply(role,at):
                    return self.worker('receive',at,token=ts[role],raw=self.rows[role]['original'],complete=True)
                require(reply(first,t0)['code']=='OK','first callback')
                before,p=self.packet(t0+timedelta(seconds=1),ts);require(before['ok'],'initial candidate set')
                first_pick=before['candidates'][0];second_pick=before['candidates'][-1]
                require(self.update([first_pick])['code']=='APPLIED','first withdrawal')
                reply_at=t0+timedelta(seconds=9)
                close=reply_at+timedelta(seconds=90)
                stamp=microseconds(close-close.fromtimestamp(0,close.tzinfo))
                require(self.update([second_pick,first_pick],live_close_us=stamp)['code']=='APPLIED','second withdrawal and earlier clock')
                outcome=reply(second,reply_at+timedelta(microseconds=delay))
                require(outcome['code']==('OK' if delay<=0 else 'DEADLINE_PASSED'),('callback',first,delay,outcome))
                now=reply_at+timedelta(microseconds=2)
                final,p=self.packet(now,ts)
                require(final['ok'] and not {first_pick,second_pick}.intersection(final['candidates'])
                        and self.call('view',publication={'public_candidates':final['candidates']})['buy_recommended'] is False,('fresh candidates',first,delay,final.keys()))
                stored=self.scalar("SELECT count(*) FROM baganriki_ai_attempts WHERE status='RECEIVED'")
                require(stored==(2 if delay<=0 else 1) and (delay<=0 or not any(dict(final['selection']['agreement_bonus']).values())),'no late votes')
                stale=self.replay('commit',dict(p,roles=self.data['roles'],selection=before['selection'],finished_at=p['now']))
                require(not stale['ok'] and stale['reason']=='W8_PUBLICATION_CHANGED','stale commit')
                require(self.update(self.nums)['code']=='APPLIED','fully withdrawn')
                terminal,p=self.packet(now,ts)
                require(not terminal['ok'] and terminal['candidates']==[] and self.scalar('SELECT count(*) FROM provider_stub')==2,'terminal publication and send count')
                require(reply(first,now)['code']=='IDEMPOTENT','already saved reply remains idempotent')
                groups+=1
        print(f'PASS {groups} queue-to-publication races with two successive withdrawals, shortened reply deadline -1/0/+1 microsecond, stale commit and full cancellation.')
    def verify_legacy(self):
        self.reset();self.db.execute("UPDATE baganriki_snapshots SET release_version='history'")
        res=self.update(['1x']);require(res['code']=='APPLIED' and self.row()[0]=='[1]','unchanged historical adapter')
        print('PASS historical-release update compatibility.')
    @property
    def earlier(self):
        t=self.sales-timedelta(seconds=5)
        return microseconds(t-t.fromtimestamp(0,t.tzinfo))

def before_equal(trial,row,events):
    return trial.row()==row and trial.scalar('select count(*) from baganriki_audit_events')==events

def run(php,controller,old=False):
    with TemporaryDirectory() as temp:
        t=LiveTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            t.reproduce(old);t.queued(old);t.direct_commit(old)
            if not old:
                t.saved_matrix();t.incoming_matrix();t.sequences();t.races();t.delayed();t.verify_legacy()
        finally:t.db.close()

def main():
    import argparse
    opt=argparse.ArgumentParser(description=__doc__);opt.add_argument('php');opt.add_argument('controller',type=Path);opt.add_argument('--observe-r158',action='store_true')
    args=opt.parse_args()
    run(args.php,args.controller,args.observe_r158)
if __name__=='__main__':main()
