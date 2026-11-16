"""Exercise the normal read endpoint against committed publications and concurrent official withdrawals."""
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import time

from verify_r159_live_writer import LiveTrial
from verify_r157_receive import dump,require,microseconds
HERE=Path(__file__).resolve().parent

class ReadTrial(LiveTrial):
    def __init__(self, php, controller, directory):
        super().__init__(php,controller,directory)
        self.db.execute('ALTER TABLE baganriki_snapshots ADD COLUMN input_invalid_code TEXT')
    def setup(self):
        self.reset()
        self.db.execute('DELETE FROM baganriki_publication_intents')
        self.db.execute('DELETE FROM baganriki_publications')
        first=json.loads(self.rows['1st']['input'])
        self.db.execute('UPDATE baganriki_snapshots SET input_json_1st=?,input_json_2nd=?,snapshot_hash=?,common_json=?,allocation_mode=?,quota_1st=?',
                        (self.rows['1st']['input'],self.rows['2nd']['input'],self.data['hash'],self.data['common'],first['allocation_mode'],dump(first['quota'])))
        now=self.start+timedelta(seconds=6);self.clock=microseconds(now-now.fromtimestamp(0,now.tzinfo))
    def publish(self):
        pub=self.call('publish',clock=self.clock)
        require(pub['code']=='PUBLISHED',pub)
        return pub['publication_id']
    def get(self,**opts):
        params=dict(clock=self.clock,race=self.data['race_id'])
        params.update(opts)
        result=subprocess.run([self.php,str(HERE/'r1510_read.php'),str(self.path),str(self.controller)],input=dump(params),text=True,capture_output=True,check=True)
        return json.loads(result.stdout)
    def effective(self,out):
        body=out.get('body',{});live=body.get('live_state',{})
        return [out['status'],body.get('state'),(body.get('publication') or {}).get('publication_id'),live.get('available_candidate_count'),live.get('available_candidate_nos')]
    def demonstrate(self,old):
        self.setup();ref=self.publish();base=self.get()
        require(base['status']==200 and base['body']['state']=='PUBLISHED',('baseline',base))
        history=base['body']['publication']['candidates'];expected=[x['horse_no'] for x in history]
        require(len(expected)>=2 and len(expected)==len(set(expected)),expected)
        self.update([expected[0],expected[1]])
        good=self.get()
        require(good['body']['publication']['candidates']==history and good['body']['live_state']['available_candidate_count']==len(expected)-2,('valid history',good))
        # Keep the official withdrawal audit and remaining headcount, lose just one member of the stored list.
        self.db.execute('UPDATE baganriki_live_states SET cancellations=?',(dump([expected[1]]),))
        conflict=self.get()
        print(('R15.9' if old else 'R15.10'),'partial withdrawal',expected[:2],'history',expected,'inconsistent live read',self.effective(conflict),flush=True)
        if old:require(conflict['body']['state']=='PUBLISHED' and conflict['body']['live_state']['available_candidate_count']==len(expected)-1,'old lost withdrawal counted')
        else:require(conflict['body']['state']=='UNAVAILABLE' and conflict['body']['publication'] is None and self.effective(conflict)[3:]==[None,None],'hold unreadable overlay')
        self.db.execute('UPDATE baganriki_live_states SET cancellations=?',(dump([expected[0],expected[2]]),))
        swapped=self.get()
        if old:
            require(self.effective(swapped)[3]==len(expected)-2 and swapped['body']['live_state']['cancellations']!=sorted(expected[:2]),'wrong horse with matching headcount')
        else:self.held(swapped)
        print('same-count withdrawal swapped; response',swapped['status'],swapped['body']['state'],flush=True)
        before=self.counts()
        retry=self.call('publish',clock=self.clock)
        next_change=self.update([5])
        if old:
            require(retry['code']=='PUBLISHED' and expected[1] in [x['horse_no'] for x in retry['candidates']] and next_change['code']=='APPLIED','old writer accepted wrong member')
        else:
            require(retry['code']=='LIVE_STATE_UNAVAILABLE' and next_change['code']=='LIVE_STATE_UNAVAILABLE' and before==self.counts(),('write gate',retry['code'],next_change['code']))
            require(self.call('roster')['code']=='LIVE_STATE_UNAVAILABLE','send guard blocked')
        print('same-count discrepancy; new publish / next official change',retry['code'],next_change['code'],flush=True)
        self.db.execute("UPDATE baganriki_live_states SET cancellations=?",(dump('unreadable'),))
        typed=self.get()
        print('scalar cancellation response',typed['status'],typed.get('body',{}).get('state'),flush=True)
        require((old and typed['status']==500) or (not old and typed['status']==200 and typed['body']['state']=='UNAVAILABLE'),('shape',typed))
        self.setup();first=self.publish()
        self.update([expected[0]]);second=self.publish()
        same=self.get()
        print('same publication time, expected latest',second,'returned',self.effective(same)[2],flush=True)
        require(first!=second and self.scalar('SELECT COUNT(DISTINCT published_at) FROM baganriki_publications')==1,'duplicate-timestamp control')
        if old:require(self.effective(same)[2]==first and same['body']['live_state']['latest_publication_id']==second,'old unbound timestamp read')
        else:require(self.effective(same)[2]==second,'use current committed pointer')
    def variant(self, sql, params, check):
        # A separate read-only HTTP connection must see this committed fault. Restore the whole affected row afterwards.
        target=sql.split()[1]
        states=self.db.execute('SELECT * FROM '+target).fetchall()
        self.db.execute(sql,params)
        try:check(self.get())
        finally:
            self.db.execute('DELETE FROM '+target)
            if states:self.db.executemany('INSERT INTO '+target+' VALUES('+','.join('?' for _ in states[0])+')',states)
    def held(self, out):
        require(self.effective(out)==[200,'UNAVAILABLE',None,None,None],('unsafe response',out))
        b=out['body'];require(not b['buy_recommended'] and b['live_state']['actual_quota'] is None and b['diagnostics'],b)
    def counts(self):
        return tuple(self.scalar('SELECT count(*) FROM '+table) for table in
                     ('baganriki_publications','baganriki_publication_intents','baganriki_ai_attempts','baganriki_audit_events','provider_stub','queued_changes'))
    def check_display(self,out,history,withdrawn,ref):
        remain=[c for c in history if c['horse_no'] not in withdrawn]
        available=[c['horse_no'] for c in remain]
        expect='CANCELLED' if set(withdrawn)==set(self.nums) else 'PUBLISHED'
        require(self.effective(out)==[200,expect,ref,len(available),available],('display',out['body']['state'],self.effective(out),available))
        live=out['body']['live_state']
        counts=Counter(c['band'] for c in remain)
        require(live['cancellations']==sorted(withdrawn) and int(live['active_count'])==len(self.nums)-len(withdrawn),live)
        require(live['actual_quota']=={b:counts[b] for b in 'ABD'} and live['latest_publication_id']==ref and
                out['body']['buy_recommended'] is False and out['body']['publication']['candidates']==history,('history',live))
    def displays(self):
        from itertools import combinations
        self.setup();ref=self.publish();history=self.get()['body']['publication']['candidates']
        saved=self.counts();cases=0
        for total in (0,1,2):
            for gone in combinations(self.nums,total):
                # Every possible isolated input state on the same committed history (no artificial republication).
                self.db.execute('UPDATE baganriki_live_states SET cancellations=?,active_count=?,state_revision=?',(dump(gone),len(self.nums)-total,int(bool(gone))))
                if gone:
                    delta=dump({'cancellations':{'前':[],'後':list(gone)}})
                    self.db.execute("INSERT INTO baganriki_audit_events(race_id,snapshot_id,kind,old_revision,new_revision,diagnostics) VALUES(?,1,'R122_LIVE_APPLIED',0,1,?)",(self.data['race_id'],delta))
                self.check_display(self.get(),history,gone,ref);cases+=1
                self.db.execute("DELETE FROM baganriki_audit_events WHERE kind='R122_LIVE_APPLIED'")
        require(self.counts()==saved,'GET changed history or scheduled work')
        self.setup();ref=self.publish();history=self.get()['body']['publication']['candidates'];gone=[]
        for n in self.nums:
            require(self.update([n])['code']=='APPLIED','official apply')
            gone.append(n);before=self.counts()
            self.check_display(self.get(),history,gone,ref)
            require(self.counts()==before,'GET wrote SQL')
        print(f'PASS {cases} empty/single/pair withdrawal sets; {len(gone)} cumulative official updates, through all withdrawn; history, available numbers, counts, per-band quotas and zero read writes.',flush=True)
    def faults(self):
        self.setup();ref=self.publish();b=self.get()['body'];rows=b['publication']['candidates']
        live='baganriki_live_states';pub='baganriki_publications';intent='baganriki_publication_intents';snap='baganriki_snapshots'
        mutations={live:{'cancellations':[None,'','[','{}','false','2','"bad"','[1]','[2,2]','[0]','[17]','[1.3]','[true]','["1x"]'],
                          'active_count':[None,-1,0,15,17,'x'],'latest_publication_id':[None,'missing',' ',ref+' '],
                          'publication_revision':[None,0,-1,2,'1x','18446744073709551616'],'state_revision':[None,-1,'x'],'response_revision':[None,-1,'x'],'live_close':['bad']},
                   intent:{'state':['FAILED','PENDING'],'publication_id':[None,'elsewhere'],'snapshot_id':[2],'read_publication_revision':[None,1,'02'],
                           'read_state_revision':[1],'read_response_revision':[1],'intent_hash':['0'*64,'damaged']},
                   pub:{'snapshot_id':[2],'intent_id':[None,100],'used_state_revision':[1],'used_response_revision':[1],
                        'publication_quota':[None,'{}','false','{"A":0,"B":0,"D":0}'],'shortfalls':['2','{}','bad'],
                        'candidates':[None,'','{}','false',dump(rows[::-1]),dump(rows+[rows[0]]),dump([dict(rows[0],horse_no=17)]+rows[1:]),
                                      dump([dict(rows[0],band='D' if rows[0]['band']!='D' else 'B')]+rows[1:]),
                                      dump([dict(rows[0],bonus=99)]+rows[1:]),dump([dict(rows[0],horse_no=True)]+rows[1:]),dump(['1']),dump([{}])]},
                   snap:{'common_json':['{}',None],'snapshot_hash':['1'*64],'frozen_nums':[dump(self.nums[:-1]+[17]),'{}']}}
        cases=0
        for table,fields in mutations.items():
            for field,values in fields.items():
                for v in values:
                    try:self.variant('UPDATE '+table+' SET '+field+'=?',(v,),self.held)
                    except AssertionError as error:raise AssertionError((table,field,v,str(error)[:620])) from error
                    cases+=1
        for table in (live,intent,pub):
            self.variant('UPDATE '+table+' SET snapshot_id=2',(),self.held);cases+=1
        self.update([rows[0]['horse_no']]);new=self.publish()
        self.variant('UPDATE '+live+' SET latest_publication_id=?',(ref,),self.held);cases+=1
        require(self.effective(self.get())[2]==new,'repair restored correct current row')
        for field,value in [('kind','unrelated'),('old_revision',9),('new_revision',2),('race_id','unrelated'),('diagnostics','{}'),
                            ('diagnostics',dump({'cancellations':{'前':[],'後':[rows[1]['horse_no']]}}))]:
            self.variant("UPDATE baganriki_audit_events SET "+field+"=? WHERE kind='R122_LIVE_APPLIED'",(value,),self.held);cases+=1
        print(f'PASS {cases} corrupted roster, frozen input, clock, revision, publication, committed intent or pointer combinations: HTTP 200 held; genuine superseded ID rejected.',flush=True)
    def sealed(self):
        from hashlib import sha256
        self.setup()
        checked,p=self.packet(self.start+timedelta(seconds=6));require(checked['ok'],checked)
        sel=checked['selection'];raw=dump(sel);digest=sha256(raw.encode()).hexdigest()
        ref=self.publish();new_hash=sha256(b'fixture committed W9 intent').hexdigest()
        receipt=self.replay('seal_publication',dict(p,intent=new_hash,publication_id=ref,payload_sha256=digest))
        require(receipt['ok'],receipt);seal=receipt['data']['sha256']
        for table in ('baganriki_publications','baganriki_publication_intents'):
            for field in ('selection_json','payload_sha256','publication_sha256'):
                self.db.execute('ALTER TABLE '+table+' ADD COLUMN '+field+' TEXT')
        self.db.execute('UPDATE baganriki_publication_intents SET intent_hash=?',(new_hash,))
        bands={x['horse_no']:x['band'] for x in json.loads(self.data['common'])['runners']}
        rows=[dict(horse_no=n,band=bands[n],bonus=sel['agreement_bonus'][str(n)]) for n in sel['public_candidates']]
        self.db.execute('UPDATE baganriki_publications SET candidates=?,selection_json=?,payload_sha256=?,publication_sha256=?',(dump(rows),raw,digest,seal))
        self.check_display(self.get(),rows,[],ref)
        self.update([rows[0]['horse_no']])
        self.check_display(self.get(),rows,[rows[0]['horse_no']],ref)
        for sql,v in [('UPDATE baganriki_publications SET candidates=?',dump(rows[::-1])),
                      ('UPDATE baganriki_publications SET selection_json=?',raw+' '),('UPDATE baganriki_publications SET payload_sha256=?',None),
                      ('UPDATE baganriki_publications SET publication_sha256=?','0'*64),
                      ('UPDATE baganriki_publication_intents SET selection_json=?','{}')]:
            self.variant(sql,(v,),self.held)
        self.db.execute('UPDATE baganriki_publication_intents SET payload_sha256=?,publication_sha256=?',(digest,seal))
        self.db.execute('UPDATE baganriki_publications SET payload_sha256=NULL,publication_sha256=NULL')
        self.check_display(self.get(),rows,[rows[0]['horse_no']],ref)
        self.db.execute('UPDATE baganriki_publication_intents SET selection_json=?',(raw,))
        self.db.execute('UPDATE baganriki_publications SET selection_json=NULL')
        self.check_display(self.get(),rows,[rows[0]['horse_no']],ref)
        self.variant('UPDATE baganriki_publication_intents SET selection_json=NULL,payload_sha256=NULL,publication_sha256=NULL',(),self.held)
        print('PASS W9 seals on publication, intent, and split rows; corrupted/partial/conflicting seals, scope, downgrade and reordered display rejected.',flush=True)
    def overlap(self):
        self.setup();old=self.publish();history=self.get()['body']['publication']['candidates'];n=history[0]['horse_no']
        barrier=self.directory/'rendezvous';barrier.mkdir()
        with ThreadPoolExecutor(1) as pool:
            inflight=pool.submit(self.get,barrier=str(barrier))
            try:
                end=time.monotonic()+10
                while not (barrier/'read').exists():
                    require(time.monotonic()<end,'read did not reach live row');time.sleep(.01)
                require(self.update([n])['code']=='APPLIED','concurrent official withdrawal');new=self.publish()
            finally:(barrier/'resume').touch()
            before=inflight.result()
        self.check_display(before,history,[],old)
        after=self.get();pub=after['body']['publication']
        self.check_display(after,pub['candidates'],[n],new)
        require(new!=old and n not in after['body']['live_state']['available_candidate_nos'],'old/new separation')
        for data in (before,after):
            require(all(in_tx for tbl,in_tx in data['read_tables'] if tbl.startswith('baganriki_')),'fragmented read')
        print('PASS simultaneous read, official withdrawal and publication on independent SQLite WAL connections: earlier request coherent, fresh GET presents new ID and roster.',flush=True)
    def clocks_gates(self):
        from datetime import datetime
        self.setup();base=self.get();end=datetime.fromisoformat(base['body']['live_state']['effective_publish_deadline'])
        cap=microseconds(end-end.fromtimestamp(0,end.tzinfo))
        for tick,state in [(cap-1,'PENDING'),(cap,'PENDING'),(cap+1,'UNAVAILABLE')]:
            out=self.get(clock=tick);require(self.effective(out)==[200,state,None,None,None],('deadline',tick,out))
        self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='14:58'")
        early=self.get();require(early['body']['live_state']['effective_publish_deadline']<base['body']['live_state']['effective_publish_deadline'],'earlier official start')
        self.db.execute("UPDATE t_horse_odds_finder_races SET start_time='XXX'")
        require(self.get()['body']['state']=='UNAVAILABLE','started without publication')
        self.setup();ref=self.publish();history=self.get()['body']['publication']['candidates']
        early_close=microseconds(self.sales-self.sales.fromtimestamp(0,self.sales.tzinfo))-1000000
        require(self.update([],live_close_us=early_close)['code']=='APPLIED','official live close')
        self.check_display(self.get(),history,[],ref)
        self.variant('UPDATE baganriki_live_states SET live_close=NULL',(),self.held)
        self.variant("UPDATE t_horse_odds_finder_races SET start_time=?",('XXX',),lambda out:self.check_display(out,history,[],ref))
        self.variant("UPDATE t_horse_odds_finder_races SET start_time=?",('bad',),self.held)
        self.variant('UPDATE baganriki_snapshots SET input_invalid_code=?',('ENTRY_SET_MISMATCH',),self.held)
        originally=self.counts()
        for kw in [dict(mode='off'),dict(mode='key'),dict(mode='key',header='wrong'),dict(mode='off',header='local-test-only')]:
            out=self.get(**kw);require(out['body']['state']=='UNAVAILABLE' and not out['read_tables'] and out['headers']['Cache-Control']=='private, no-store',out)
        for kw in [dict(mode='open'),dict(mode='key',header='local-test-only'),dict(mode='key',query='local-test-only')]:
            out=self.get(**kw);self.check_display(out,history,[],ref);require(out['headers']['Cache-Control']=='private, no-store',out['headers'])
        for race,code in [('bad',400),('20261017-4-05-07-99',404)]:
            out=self.get(race=race);require(out['status']==code and out['headers']['Cache-Control']=='private, no-store',out)
        require(self.counts()==originally,'refresh scheduled writes')
        self.db.execute("UPDATE baganriki_snapshots SET release_version='historical-release'")
        old=self.get();require(old['body']['state']=='PUBLISHED' and 'available_candidate_nos' not in old['body']['live_state'],'prior API contract')
        print('PASS actual gate, zero queries when denied, noncache responses, generation/legacy compatibility, deadline equality and +1 microsecond, advanced/unreadable/started official time.',flush=True)

    def edge_states(self):
        from hashlib import sha256
        # A reservation can already exist when the saved official withdrawal set is damaged.
        for ingress in ('reserve','send','receive'):
            self.setup();at=self.start+timedelta(seconds=3)
            if ingress in ('send','receive'):
                reserved=self.worker('reserve',self.start,race=1,role='1st',slot=0)
                require(reserved['code']=='OK',reserved);token=reserved['token']
                if ingress=='receive':
                    sent=self.worker('send',self.start+timedelta(seconds=1),token=token)
                    require(sent['code']=='OK',sent)
            self.update([1,2]);self.db.execute("UPDATE baganriki_live_states SET cancellations='[1,3]'")
            permits=self.scalar('SELECT COUNT(*) FROM provider_stub')
            if ingress=='reserve':res=self.worker('reserve',at,race=1,role='2nd',slot=0)
            elif ingress=='send':res=self.worker('send',at,token=token)
            else:res=self.worker('receive',at,token=token,raw=self.rows['1st']['original'],complete=True)
            require(res['code']=='LIVE_STATE_UNAVAILABLE' and self.scalar('SELECT COUNT(*) FROM provider_stub')==permits and
                    self.scalar("SELECT COUNT(*) FROM baganriki_ai_attempts WHERE status='RECEIVED'")==0,(ingress,res))
        print('PASS same-count wrong official horse: actual reservation, permit and pending provider receipt all stopped; no additional paid-call permit.',flush=True)
        self.setup()
        checked,p=self.packet(self.start+timedelta(seconds=6));require(checked['ok'],checked)
        for role in p['roles']:
            role['receipt']['original_approved']=False
        p['live']['role_journal']=self.replay('adopt',p)['data']['role_journal']
        refused=self.replay('open',p)
        require(not refused['ok'] and refused['reason']=='W2_NO_VERIFIED_ROLE',refused)
        # Complete, independently verified responses may legitimately find no eligible horses.
        for role in p['roles']:
            begin,original=role['original'].split('\n',1);original,end=original.rsplit('\n',1)
            reply=json.loads(original);reply['public_candidates']=[]
            for horse in reply['horses']:
                horse.update(eligibility='UNVERIFIED',tier='UNVERIFIED',rank_basis='NOT_RANKED',evidence=[],evidence_rank_evidence_ids=[],
                             public_candidate=False,nonselect_reason={'code':'UNVERIFIED','reason':'合成試験では比較資料不足'})
                for family in horse['family_review']:
                    horse['family_review'][family]={'status':'DATA_MISSING','reason':'合成試験では比較資料不足','source_refs':[],'evidence_ids':[]}
            for name in ('band_ranks','evidence_band_ranks','selection_ranks'):
                reply[name]={b:[] for b in 'ABD'}
            reply['shortfalls']=[{'band':b,'requested':n,'eligible':0,'missing':n,'reason':'適格馬なし'} for b,n in reply['role_quota'].items() if n]
            reply['expected_reviews']={b:{'incumbent':None,'challenger':None,'decision':'NO_BOUNDARY'} for b in 'ABD'}
            raw=begin+'\n'+dump(reply)+'\n'+end
            role['original']=raw;role['receipt']['original_approved']=True
            role['receipt']['response_hash']=sha256(raw.encode()).hexdigest();role['proof_horses']=[]
        p['live']['role_journal']=self.replay('adopt',p)['data']['role_journal']
        opened=self.replay('open',p);require(opened['ok'],opened);sel=opened['data']['result']
        require(sel['selection_state']=='VERIFIED_NO_CANDIDATES' and sel['public_candidates']==[],sel)
        require(self.replay('commit',dict(p,selection=sel,finished_at=p['now']))['ok'],'verified empty commit')
        raw=dump(sel);digest=sha256(raw.encode()).hexdigest();intent=sha256(b'fixture verified empty').hexdigest()
        ref=self.publish();seal=self.replay('seal_publication',dict(p,intent=intent,publication_id=ref,payload_sha256=digest))['data']['sha256']
        for name in ('selection_json','payload_sha256','publication_sha256'):
            self.db.execute('ALTER TABLE baganriki_publications ADD COLUMN '+name+' TEXT')
        # Install a complete empty W9 result, including its separately stored display summary.
        evidence=json.loads(self.scalar('SELECT evidence FROM baganriki_publications'))
        evidence.update({key:sel[key] for key in ('actual_quota','shortfalls','fill')})
        self.db.execute('UPDATE baganriki_publications SET candidates=?,selection_json=?,payload_sha256=?,publication_sha256=?,shortfalls=?,evidence=?',
                        ('[]',raw,digest,seal,dump(sel['shortfalls']),dump(evidence)))
        self.db.execute('UPDATE baganriki_publication_intents SET intent_hash=?',(intent,))
        self.check_display(self.get(),[],[],ref)
        require(self.scalar('SELECT active_count FROM baganriki_live_states')==len(self.nums),'active field remains')
        print('PASS genuine W2 VERIFIED_NO_CANDIDATES selection and proof with all 16 still active: HTTP 200 PUBLISHED, zero current candidates and an empty ordered list.',flush=True)

def run(php,controller,old=False,edges=False):
    with TemporaryDirectory() as tmp:
        t=ReadTrial(str(php),Path(controller).resolve(),Path(tmp))
        try:
            if edges:
                t.edge_states();return
            t.demonstrate(old)
            if not old:
                t.displays();t.faults();t.overlap();t.clocks_gates();t.sealed()
        finally:t.db.close()
if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('php');p.add_argument('controller',type=Path);group=p.add_mutually_exclusive_group();group.add_argument('--observe-r159',action='store_true');group.add_argument('--edges',action='store_true',help='Run the worker gates and a verified empty publication')
    a=p.parse_args();run(a.php,a.controller,a.observe_r159,a.edges)
