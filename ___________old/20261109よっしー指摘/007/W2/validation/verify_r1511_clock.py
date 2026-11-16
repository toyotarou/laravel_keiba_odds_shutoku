"""Compare frozen and live cutoff clocks at the submitted application's read, queued and publish entry points."""
import argparse
from datetime import datetime, timedelta
from hashlib import sha256
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import sys

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php')
    parser.add_argument('controller')
    parser.add_argument('--observe-r1510', type=Path, metavar='OLD_W2_DIRECTORY')
    args = parser.parse_args()
    if args.observe_r1510:
        archived = args.observe_r1510.resolve()
        if sha256((archived/'php/PublishedReadW2.php').read_bytes()).hexdigest() != '88025165334aa5ee9765d02cb2b8103646b83e7b76c6d6f4f618672806d77a13':
            parser.error('Provide the W2 directory from the R15.10 engineer archive.')
        if sha256(Path(args.controller).read_bytes()).hexdigest() != '3e85872492422ae4e0a6d2b05e6ffad7a035ff0bcc9cd2314360c0eb37304e2e':
            parser.error('Provide the submitted controller with the R15.10 patches applied.')
        sys.path.insert(0,str(archived/'validation'))


from verify_r1510_read import ReadTrial
from verify_r157_receive import db_time, dump, microseconds, require

def timestamp(instant):
    return microseconds(instant-instant.fromtimestamp(0,instant.tzinfo))

class ClockTrial(ReadTrial):
    def earlier_close(self):
        return self.start+timedelta(seconds=96)

    def apply_cutoff(self,dt,withdrawn=()):
        outcome=self.update(list(withdrawn),live_close_us=timestamp(dt))
        require(outcome['code']=='APPLIED',outcome)

    def attempt(self,action):
        if action not in ('send','receive'):return None
        r=self.worker('reserve',self.start,race=1,role='1st',slot=0)
        require(r['code']=='OK',('baseline reservation',r))
        if action=='receive':
            require(self.worker('send',self.start+timedelta(seconds=1),token=r['token'])['code']=='OK','prior paid-call permit')
        return r['token']

    def act(self,action,at,token=None):
        if action=='get':return self.get(clock=timestamp(at))
        if action=='reserve':return self.worker('reserve',at,race=1,role='2nd',slot=0)
        if action=='send':return self.worker('send',at,token=token)
        if action=='receive':return self.worker('receive',at,token=token,raw=self.rows['1st']['original'],complete=True)
        if action=='publish':return self.call('publish',clock=timestamp(at))
        if action=='change':return self.update([2],live_close_us=timestamp(self.earlier_close()+timedelta(minutes=1)))
        if action=='acquire':return self.call('odds',raw=dump([{'num':2,'tan':'取消'}]))
        raise ValueError(action)

    def state(self):
        return (self.row(),self.scalar('SELECT COUNT(*) FROM baganriki_publications'),
                self.scalar('SELECT COUNT(*) FROM baganriki_publication_intents'),self.scalar('SELECT COUNT(*) FROM provider_stub'),
                self.scalar("SELECT COUNT(*) FROM baganriki_ai_attempts WHERE status='RECEIVED'"),
                self.scalar("SELECT COUNT(*) FROM baganriki_audit_events WHERE kind='R122_LIVE_APPLIED'"))

    def demonstrate_clocks(self,old):
        for kind in ('lost_live','moved_frozen'):
            for action in ('send','receive','publish','change'):
                self.setup();token=self.attempt(action)
                if kind=='lost_live':self.apply_cutoff(self.earlier_close())
                self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='15:05'")
                good=self.get()['body']['live_state'];key='effective_publish_deadline' if action=='publish' else 'effective_reply_deadline'
                at=datetime.fromisoformat(good[key])+timedelta(microseconds=1)
                if kind=='lost_live':self.db.execute('UPDATE baganriki_live_states SET live_close=NULL')
                else:self.db.execute('UPDATE baganriki_snapshots SET sales_close_at=?',(db_time(self.sales+timedelta(minutes=5)),))
                before=self.state();view=self.get(clock=timestamp(at));out=self.act(action,at,token)
                if old:
                    required={'send':'OK','receive':'OK','publish':'PUBLISHED','change':'APPLIED'}[action]
                    require(out['code']==required,(kind,action,out))
                    if kind=='lost_live':self.held(view)
                    else:require(view['body']['live_state'][key]>good[key],('extended frozen deadline',view))
                else:
                    self.held(view);require(out['code']=='TIME_UNCOMPUTABLE' and self.state()==before,(kind,action,out))
                print(('R15.10' if old else 'R15.11'),kind,action,good[key],out['code'],flush=True)

    def corrupt(self,case):
        live=self.earlier_close()
        if case=='lost':self.db.execute('UPDATE baganriki_live_states SET live_close=NULL');return
        if case in ('live+1','live-1','unlogged','ill_typed'):
            val={'live+1':db_time(live+timedelta(microseconds=1)),'live-1':db_time(live-timedelta(microseconds=1)),
                 'unlogged':db_time(live),'ill_typed':'2026-02-30 10:10:00'}[case]
            self.db.execute('UPDATE baganriki_live_states SET live_close=?',(val,));return
        if case in ('frozen+1','frozen-1','no_frozen','later_frozen'):
            val={'frozen+1':db_time(self.sales+timedelta(microseconds=1)),'frozen-1':db_time(self.sales-timedelta(microseconds=1)),
                 'no_frozen':None,'later_frozen':db_time(self.sales+timedelta(minutes=5))}[case]
            self.db.execute('UPDATE baganriki_snapshots SET sales_close_at=?',(val,));return
        if case in ('other_scope','non_string','no_timezone','wrong_role_version'):
            inp=json.loads(self.data['common'])
            if case=='other_scope':inp['race_id']='20261017-4-05-07-02'
            elif case=='wrong_role_version':inp['prompt_version']='archive'
            else:inp['time_contract']['sales_close_at']=123 if case=='non_string' else db_time(self.sales)
            raw=dump(inp)
            self.db.execute('UPDATE baganriki_snapshots SET common_json=?,snapshot_hash=?',(raw,sha256(raw.encode()).hexdigest()));return
        if case in ('missing_bytes','missing_hash','hash_mismatch'):
            q={'missing_bytes':'common_json=NULL','missing_hash':'snapshot_hash=NULL','hash_mismatch':"snapshot_hash='not-the-frozen-digest'"}[case]
            self.db.execute('UPDATE baganriki_snapshots SET '+q);return
        if case in ('delta_gap','later_than_frozen','duplicate_clock','null_delta'):
            original=self.scalar("SELECT diagnostics FROM baganriki_audit_events WHERE kind='R122_LIVE_APPLIED'")
            change=json.loads(original)
            if case=='delta_gap':change['live_close']['前']=timestamp(live)+1000;raw=dump(change)
            elif case=='later_than_frozen':
                change['live_close']['後']=timestamp(self.sales)+1;raw=dump(change)
                self.db.execute('UPDATE baganriki_live_states SET live_close=?',(db_time(self.sales+timedelta(microseconds=1)),))
            elif case=='null_delta':
                change['live_close']=None;raw=dump(change);self.db.execute('UPDATE baganriki_live_states SET live_close=NULL')
            else:raw='{"live_close":null,'+original[1:]
            self.db.execute("UPDATE baganriki_audit_events SET diagnostics=? WHERE kind='R122_LIVE_APPLIED'",(raw,));return
        raise ValueError(case)

    def fault_matrix(self):
        cases=('lost','live+1','live-1','unlogged','ill_typed','frozen+1','frozen-1','no_frozen','later_frozen',
               'other_scope','non_string','no_timezone','wrong_role_version','missing_bytes','missing_hash','hash_mismatch',
               'delta_gap','later_than_frozen','duplicate_clock','null_delta')
        n=0
        for case in cases:
            for action in ('get','reserve','send','receive','publish','change','acquire'):
                self.setup();token=self.attempt(action)
                if case!='unlogged':self.apply_cutoff(self.earlier_close(),(1,))
                self.corrupt(case);before=self.state()
                out=self.act(action,self.start+timedelta(seconds=4),token)
                if action=='get':self.held(out)
                else:require(out['code'] in ('LIVE_STATE_UNAVAILABLE','TIME_UNCOMPUTABLE'),(case,action,out))
                require(self.state()==before,('side effect',case,action));n+=1
        print(f'PASS {n} clock fault and actual entry-point pairs ({len(cases)} faults x 7 routes); no new publication, permit, accepted response or applied update.',flush=True)

    def normal_boundaries(self):
        n=0
        for origin in ('frozen','official_advance','live','live_then_postponed'):
            for action in ('send','receive','publish'):
                for delta in (-1,0,1):
                    self.setup();token=self.attempt(action);close=self.sales
                    if origin=='official_advance':
                        self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='14:58'")
                        close=self.sales-timedelta(minutes=2)
                    elif origin=='frozen':
                        self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='15:05'")
                    else:
                        close=self.earlier_close()+timedelta(microseconds=123456)
                        self.apply_cutoff(close+timedelta(seconds=20),(1,));self.apply_cutoff(close,(3,))
                        require(self.update([1],live_close_us=timestamp(close+timedelta(microseconds=1)))['code']=='NO_CHANGE','cannot extend')
                        if origin=='live_then_postponed':self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='15:05'")
                    limit=close-timedelta(seconds=60 if action=='publish' else 90)
                    key='effective_publish_deadline' if action=='publish' else 'effective_reply_deadline'
                    got=self.get()['body']['live_state'][key];require(datetime.fromisoformat(got)==limit,(origin,action,got,limit))
                    out=self.act(action,limit+timedelta(microseconds=delta),token)
                    expected='DEADLINE_PASSED' if delta>0 else ('PUBLISHED' if action=='publish' else 'OK')
                    require(out['code']==expected,(origin,action,delta,out['code'],expected));n+=1
        print(f'PASS {n} cutoff boundaries: frozen cap after postponement, earlier official post, cumulative live advance and later postponement; -1/0/+1 microsecond for send, receipt and new publication.',flush=True)

    def recovery(self):
        self.setup();ref=self.publish();initial=self.get()['body']['publication']['candidates']
        close=self.earlier_close()+timedelta(microseconds=17);self.apply_cutoff(close,(initial[0]['horse_no'],))
        healthy=self.get();before=self.state();self.check_display(healthy,initial,[initial[0]['horse_no']],ref)
        self.db.execute('UPDATE baganriki_live_states SET live_close=NULL');self.held(self.get())
        self.db.execute('UPDATE baganriki_live_states SET live_close=?',(db_time(close),))
        require(self.state()==before and self.get()['body']==healthy['body'],'exact restoration without regeneration')
        self.db.execute("UPDATE baganriki_snapshots SET release_version='historical-release',common_json=NULL,snapshot_hash=NULL")
        require(self.get()['body']['state']=='PUBLISHED','historical format')
        print('PASS restored official cutoff and original publication ID/rows with zero regeneration; historical-release compatibility.',flush=True)

def run(php,controller,old=False):
    with TemporaryDirectory() as temp:
        trial=ClockTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            trial.demonstrate_clocks(old)
            if not old:
                trial.fault_matrix();trial.normal_boundaries();trial.recovery()
        finally:trial.db.close()

if __name__=='__main__':
    run(args.php,args.controller,bool(args.observe_r1510))
