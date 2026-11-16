"""Exercise current versus published field allocations through the submitted SQL writer and customer GET."""
import argparse
from copy import deepcopy
from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
from itertools import product
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory

HERE=Path(__file__).resolve().parent
PREVIOUS='21042fcabb5ec85be470e2e2eda34ed36df3f2a756a974d719e83b892c8a812e'
BANDS=('A','B','D')
BASE=json.loads((HERE.parent/'config/w2_contract.json').read_text())['base_quota']
if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('php');parser.add_argument('controller',type=Path)
    scope=parser.add_mutually_exclusive_group()
    scope.add_argument('--observe-r1514',type=Path)
    scope.add_argument('--legacy-from',type=Path,nargs=2,metavar=('R15_11_CONTROLLER','R15_11_W2'))
    scope.add_argument('--upgrade-from',type=Path,nargs=2,metavar=('R15_14_CONTROLLER','R15_14_W2'))
    args=parser.parse_args()
    if args.observe_r1514:
        if sha256(args.controller.read_bytes()).hexdigest()!=PREVIOUS:parser.error('R15.14 submitted controller required')
        sys.path.insert(0,str(args.observe_r1514.resolve()/'validation'))
from verify_r1514_replays import OverlapTrial,ROLES
from verify_r157_receive import dump,require


def oracle(mode,counts):
    total=sum(counts);target=min(total,4) if total<=8 else (5 if total<=13 else 6 if total<=15 else 7)
    if not total or mode is None:return target,None
    quota=dict(zip(BANDS,[target,0,0] if target<=3 else BASE[str(target)][mode]))
    alive=dict(zip(BANDS,counts))
    for src,destinations in [('D','BA'),('B','DA'),('A','BD')]:
        for dest in destinations:
            while quota[src]>alive[src] and quota[dest]<alive[dest]:
                quota[src]-=1;quota[dest]+=1
    return target,quota


class AllocationTrial(OverlapTrial):
    def __init__(self,php,controller,folder):
        super().__init__(php,controller,folder)
        self.db.execute('ALTER TABLE baganriki_live_states ADD COLUMN live_target_count INTEGER')
        self.db.execute('ALTER TABLE baganriki_live_states ADD COLUMN live_quota TEXT')

    def baseline(self):
        self.seeded();self.frozen=json.loads(self.data['common'])
        self.db.execute('UPDATE baganriki_live_states SET live_target_count=?,live_quota=?',(self.frozen['target_count'],dump(self.frozen['quota'])))

    def cache(self):
        target,raw=self.db.execute('SELECT live_target_count,live_quota FROM baganriki_live_states').fetchone()
        return target,json.loads(raw) if raw is not None else None

    def expected_state(self,withdrawn):
        active=[h['band'] for h in self.frozen['runners'] if h['horse_no'] not in withdrawn]
        return oracle(self.frozen['allocation_mode'],[active.count(b) for b in BANDS])

    def get_checked(self,withdrawn,cache=True):
        target,quota=self.expected_state(withdrawn);before=self.unchanged()
        body=self.get()['body'];live=body['live_state']
        require(live['active_count']==len(self.nums)-len(withdrawn) and (live['live_target_count'],live['live_quota'])==(target,quota),body)
        if cache:require(self.cache()==(target,quota),'derived DB fields')
        require(not body['buy_recommended'] and self.unchanged()==before,'GET must be read-only')
        return body

    def smoke(self,old=False):
        self.baseline();pub=self.publish_db();pid=pub['publication_id']
        self.db.execute('UPDATE baganriki_publications SET active_count=99')
        dto=self.get()['body'];again=self.publish_db()
        if old:
            require(dto['publication']['active_count']==99 and again['code']=='ALREADY',(dto['state'],again['code']))
            print('R15.14 returned publication headcount 99 from a confirmed 16-runner race; the repeat still reported ALREADY.',flush=True)
        else:
            require(dto['state']=='UNAVAILABLE' and dto['publication'] is None and not again['ok'] and not again['candidates'],(dto,again))
        self.db.execute('UPDATE baganriki_publications SET active_count=16')
        gone=[11,12,13,14,15,16];require(self.update(gone)['code']=='APPLIED','normal official withdrawals')
        dto=self.get()['body'];fresh=self.publish_db();require(fresh['code']=='PUBLISHED' and fresh['quota']=={'A':3,'B':2,'D':0},fresh)
        if old:
            require(int(dto['live_state']['live_target_count'])==7 and dto['live_state']['live_quota']=={'A':3,'B':3,'D':1} and int(dto['live_state']['active_count'])==10,dto)
            print('R15.14 normal 16-to-10 withdrawal: GET and database retained target 7 / A3 B3 D1; new publication correctly requested 5 / A3 B2 D0.',flush=True)
        else:
            require(dto['publication']['publication_id']==pid and int(dto['publication']['publication_target_count'])==7,dto)
            self.get_checked(gone)
            print('PASS R15.15 standard: verified 7 historical and 5 current positions after normal withdrawals; an invented published headcount is held on read and retry.',flush=True)

    def single_withdrawal(self,old=False):
        self.baseline();first=self.publish_db();require(first['code']=='PUBLISHED',first['code'])
        require(self.update([16])['code']=='APPLIED','withdraw one')
        dto=self.get()['body'];current=dto['live_state']
        new=self.publish_db()
        require(int(dto['publication']['publication_target_count'])==7 and dto['publication']['publication_id']==first['publication_id']
                and int(current['active_count'])==15 and new['code']=='PUBLISHED' and new['quota']=={'A':3,'B':2,'D':1},new)
        expected=7 if old else 6
        require(int(current['live_target_count'])==expected and self.cache()[0]==expected,(old,current))
        if old:
            print('R15.14 one normal withdrawal: 16 to 15 runners, fresh publication used 6 slots but GET and cache continued to report 7.',flush=True)
        else:
            require(current['live_quota']==new['quota'] and dto['live_state']['available_candidate_count']==7,current)
            print('PASS one normal withdrawal at 16 to 15: current target 6, earlier target 7 and seven still-available old candidates; next publication uses 6.',flush=True)

    def ladders(self):
        for order in [self.nums,list(reversed(self.nums)),self.nums[::2]+self.nums[1::2]]:
            self.baseline();original=self.publish_db();pid=original['publication_id'];rows=original['candidates'];withdrawn=[]
            for n in [None]+order:
                if n is not None:
                    withdrawn.append(n);require(self.update([n])['code']=='APPLIED',n)
                dto=self.get_checked(withdrawn);current=dto['live_state'];pub=dto['publication'];hist=pub['candidates']
                expect=[h['horse_no'] for h in rows if h['horse_no'] not in withdrawn]
                require(hist==rows and pub['publication_id']==pid and pub['active_count']==16 and int(pub['publication_target_count'])==7,'frozen history')
                require(current['available_candidate_nos']==expect and current['available_candidate_count']==len(expect),'current effective order/count')
                require(current['actual_quota']=={b:sum(h['band']==b and h['horse_no'] not in withdrawn for h in rows) for b in BANDS},'effective actual quotas')
                require(dto['state']==('CANCELLED' if len(withdrawn)==16 else 'PUBLISHED'),dto['state'])
            require(self.publish_db()['code']=='ALL_CANCELLED','all withdrawn')
        print('PASS 51 SQL/GET field states in three withdrawal orders from 16 to zero: target, per-band request, original 7 positions, current candidates, actual counts and cancellation agree.',flush=True)

    def defects(self,sealed):
        self.baseline();selection=self.expected(ROLES) if sealed else None;pub=self.publish_db()
        if sealed:self.pin(pub,selection)
        self.get_checked([]);require(self.publish_db()['code']=='ALREADY','control before mutation')
        evidence=json.loads(self.scalar('SELECT evidence FROM baganriki_publications'))
        old=self.db_copy()
        faults={'active_count':99,'prediction_target_count':99,'publication_target_count':99,'prediction_quota':dump({'A':0,'B':0,'D':99}),
                'publication_quota':dump({'A':2,'B':4,'D':1}),'shortfalls':dump([{'band':'D','requested':1,'filled':0,'missing':1}])}
        mutations=[(k,v) for k,v in faults.items()]
        for field,val in [('actual_quota',{'A':3,'B':2,'D':2}),('cancelled',[12]),('fill',[{'horse_no':12,'requested':'A','actual':'B'}]),
                          ('shortfalls',[{'band':'A','missing':1}])]:
            record=deepcopy(evidence);record[field]=val;mutations.append(('evidence',dump(record)))
        mutations += [('prediction_quota','{"A":3,"A":3,"B":3,"D":1}'),('prediction_quota',dump({'A':'3','B':3,'D':1})),
                      ('publication_target_count','07x'),('shortfalls','{"bad":1}'),('evidence','garbled')]
        try:
            for key,value in mutations:
                self.db.execute('UPDATE baganriki_publications SET '+key+'=?',(value,))
                prior=self.unchanged();dto=self.get()['body'];again=self.publish_db()
                require(dto['state']=='UNAVAILABLE' and dto['publication'] is None and not again['ok'] and not again['candidates'] and prior==self.unchanged(),(sealed,key,dto['state'],again['code']))
                old.backup(self.db)
            self.db.execute('UPDATE baganriki_publications SET active_count=NULL,prediction_target_count=NULL,publication_target_count=NULL,prediction_quota=NULL')
            dto=self.get_checked([]);replay=self.publish_db()
            require(dto['publication']['active_count']==16 and int(dto['publication']['publication_target_count'])==7
                    and int(dto['publication']['prediction_target_count'])==7 and replay['prediction_quota']=={'A':3,'B':3,'D':1} and replay['code']=='ALREADY','derived optional old columns')
        finally:old.close()
        print('PASS 15 altered '+('W9' if sealed else 'normalized')+' publication summaries held by retry and GET; omitted optional counts recovered from verified data without modifying the stored row.',flush=True)

    def input_shapes(self):
        self.baseline();f=self.frozen
        # Rotate horse numbers so that band identity cannot be inferred from the horse number.
        size=18;ids=list(range(7,19))+list(range(1,7))
        runners=[{'horse_no':no,'win_rank':i+1,'band':'A' if i<6 else 'B' if i<10 else 'D'} for i,no in enumerate(ids)]
        items=[];expect=[]
        for mode in ['FIRM','MIXED','VOLATILE',None]:
            cap,q=oracle(mode,[6,4,8]);template=dict(f,N=size,target_count=cap,quota=q,runners=runners,allocation_mode=mode,selection_enabled=mode is not None)
            template={key:template[key] for key in ('N','target_count','quota','runners','allocation_mode','selection_enabled')}
            for a,b,d in product(range(7),range(5),range(9)):
                active=set(ids[:a]+ids[6:6+b]+ids[10:10+d]);gone=[n for n in range(1,19) if n not in active]
                items.append([template,list(range(1,19)),gone]);cap,q=oracle(mode,[a,b,d]);expect.append([a+b+d,cap,q])
        php="""require $argv[1].'/php/CandidateSelectionW2.php';require $argv[1].'/php/PublishedReadW2.php';$out=[];foreach(json_decode(stream_get_contents(STDIN),true) as $row){$x=\\App\\Services\\Baganriki\\PublishedReadW2::allocation(...$row);$out[]=[$x['active_count'],$x['target_count'],$x['quota']];}echo json_encode($out,JSON_THROW_ON_ERROR);"""
        proc=subprocess.run([self.php,'-r',php,str(HERE.parent)],input=dump(items),text=True,capture_output=True,check=True)
        require(not proc.stderr and json.loads(proc.stdout)==expect,'independent quotas, all modes and field sizes')
        print('PASS 1260 independent allocation comparisons, three race modes and unresolved, all 0 to 18 live headcounts, permuted horse IDs and all remaining A/B/D capacities.',flush=True)

    def donor(self):
        from verify_r1511_longshots import change_role
        self.setup()
        for role in ROLES:
            row=self.role_rows[role]
            head,body=row['original'].split('\n',1);body,tail=body.rsplit('\n',1);raw=json.loads(body)
            no_evidence=change_role(deepcopy(row),None)
            by_no={h['horse_no']:h for h in no_evidence['horses']}
            raw['horses']=[by_no[h['horse_no']] if h['band']=='D' else h for h in raw['horses']]
            for field in ('band_ranks','evidence_band_ranks','selection_ranks','expected_reviews'):
                raw[field]['D']=no_evidence[field]['D']
            raw['public_candidates']=[n for b in BANDS for n in raw['selection_ranks'][b][:raw['role_quota'][b]]]
            raw['shortfalls']=[x for x in raw['shortfalls'] if x['band']!='D']+[x for x in no_evidence['shortfalls'] if x['band']=='D']
            row['original']=head+'\n'+dump(raw)+'\n'+tail
            row['proof_horses']=[n for b in BANDS for n in raw['band_ranks'][b][:raw['role_quota'][b]]]
            row['receipt'].update(response_hash=sha256(row['original'].encode()).hexdigest(),original_approved=True)
            self.put(role)
        canonical=self.expected(ROLES);pub=self.publish_db();moves=pub['fill'];rows=pub['candidates']
        require(pub['code']=='PUBLISHED' and moves==canonical['fill'] and len(moves)==1 and moves[0]['requested']=='D' and moves[0]['actual']=='B'
                and any(h['horse_no']==moves[0]['horse_no'] and h['band']=='B' for h in rows) and pub['actual_quota']=={'A':3,'B':4,'D':0},(pub['code'],moves,pub['actual_quota']))
        for sealed in (False,True):
            if sealed:self.pin(pub,canonical)
            dto=self.get()['body'];require(dto['publication']['candidates']==rows and dto['shortfalls']==[] and dto['live_state']['actual_quota']==pub['actual_quota']
                and self.publish_db()['code']=='ALREADY' and not dto['buy_recommended'],dto)
        print('PASS genuine supported B-band horse fills a D vacancy on both formats: quota A3/B3/D1, selected A3/B4/D0, 7 candidates, zero unfilled slots and no buy.',flush=True)

    def concurrent_changes(self):
        self.baseline();first=self.publish_db();copy=self.db_copy()
        try:
            with ThreadPoolExecutor(2) as pool:
                applied=list(pool.map(self.update,[[11,12,13],[14,15,16]]))
            require(all(x['code']=='APPLIED' for x in applied),'concurrent official cancellations')
            gone=list(range(11,17));dto=self.get_checked(gone)
            require(dto['publication']['publication_id']==first['publication_id'] and self.cache()==(5,{'A':3,'B':2,'D':0}),dto)
            fresh=self.publish_db();require(fresh['code']=='PUBLISHED' and fresh['quota']==self.cache()[1],fresh)
            before=self.unchanged();require(self.update(gone)['code']=='NO_CHANGE' and before==self.unchanged(),'duplicate is a no-op')
            copy.backup(self.db)
            # A cache migrated as malformed is repaired for display without mutating it from GET.
            self.db.execute("UPDATE baganriki_live_states SET live_target_count=99,live_quota='broken'")
            body=self.get_checked([],cache=False)
            require(body['live_state']['live_target_count']==7 and self.scalar('SELECT live_target_count FROM baganriki_live_states')==99,'read-only projection')
            copy.backup(self.db)
            raw=dump({k:self.frozen[k] for k in ('race_id','prompt_version','time_contract')})
            self.db.execute('UPDATE baganriki_snapshots SET common_json=?,snapshot_hash=?',(raw,sha256(raw.encode()).hexdigest()))
            require(self.update([12])['code']=='APPLIED' and self.cache()==(None,None),'record official change without inventing unavailable quotas')
            body=self.get()['body'];require(body['state']=='UNAVAILABLE' and body['live_state']['available_candidate_nos'] is None,'incomplete frozen input held')
        finally:copy.close()
        print('PASS concurrent official withdrawals, duplicate update, unreadable old cached values and incomplete allocation source: confirmed state retained, GET has no writes or invented quotas.',flush=True)

    def legacy_shortfall(self,previous):
        ctrl,folder=(v.resolve() for v in previous)
        require(sha256(ctrl.read_bytes()).hexdigest()=='f0e394f8bde573ae2fedba3c4843453324b31a0ab2f957c06b2e7f949897885a','R15.11 required')
        self.setup()
        for role in ROLES:self.init_role(role,12 if role=='1st' else None)
        cmd=[self.php,str(HERE/'r1512_publication.php'),str(self.path),'_',str(ctrl),str(folder/'validation')]
        result=subprocess.run(cmd,input=dump(dict(cmd='publish',clock=self.us(self.now))),text=True,capture_output=True,check=True)
        require(not result.stderr,result.stderr)
        old=json.loads(result.stdout);dto=self.get()['body'];numbers=[x['horse_no'] for x in old['candidates']]
        require(old['code']=='PUBLISHED' and numbers==[1,2,3,12] and any(x.get('shortfall')==3 for x in old['shortfalls']),old)
        require(dto['publication']['publication_id']==old['publication_id'] and dto['live_state']['available_candidate_nos']==numbers and dto['shortfalls']==old['shortfalls'],'old B-band shortfall and outsider preserved')
        print('PASS genuine R15.11 pre-W2-summary representation: minority-supported horse 12, B-band shortfall 3, original ID and public order preserved.',flush=True)

    def migration(self,previous):
        ctrl,folder=(x.resolve() for x in previous);require(sha256(ctrl.read_bytes()).hexdigest()==PREVIOUS,'R15.14 required')
        self.baseline();calls=self.scalar('SELECT count(*) FROM provider_stub')
        def old(script,request):
            proc=subprocess.run([self.php,str(folder/'validation'/script),str(self.path),'_',str(ctrl)],
                                input=dump(request),text=True,capture_output=True,check=True)
            require(not proc.stderr,proc.stderr);return json.loads(proc.stdout)
        result=old('r1512_publication.php',dict(cmd='publish',clock=self.us(self.now)))
        require(result['code']=='PUBLISHED',result);pid=result['publication_id']
        gone=[11,12,13,14,15,16]
        change=old('r159_live_writer.php',dict(cmd='update',change={'cancellations':gone},clock=self.us(self.now)))
        require(change['code']=='APPLIED' and self.cache()[0]==7,change)
        dto=self.get_checked(gone,cache=False)
        require(dto['publication']['publication_id']==pid and int(dto['publication']['publication_target_count'])==7 and self.cache()[0]==7,'read-only migration over stale cache')
        new=self.publish_db();require(new['code']=='PUBLISHED' and new['publication_id']!=pid and sum(new['quota'].values())==5,new)
        require(self.update([10])['code']=='APPLIED','next withdrawal refreshes old row')
        self.get_checked(gone+[10]);require(self.scalar('SELECT count(*) FROM provider_stub')==calls,'no extra calls')
        print('PASS actual R15.14 database after real official cancellations: historical ID preserved, stale cache read correctly without writes, next official cancellation repairs cached quota; no additional AI calls.',flush=True)


def smoke(php,controller):
    with TemporaryDirectory() as tmp:
        t=AllocationTrial(str(php),Path(controller).resolve(),Path(tmp))
        try:t.smoke()
        finally:t.db.close()

if __name__=='__main__':
    with TemporaryDirectory() as tmp:
        t=AllocationTrial(args.php,args.controller.resolve(),Path(tmp))
        try:
            if args.legacy_from:t.legacy_shortfall(args.legacy_from)
            elif args.upgrade_from:t.migration(args.upgrade_from)
            else:
                t.smoke(bool(args.observe_r1514));t.single_withdrawal(bool(args.observe_r1514))
                if not args.observe_r1514:
                    t.ladders();t.defects(False);t.defects(True);t.input_shapes();t.concurrent_changes();t.donor()
        finally:t.db.close()
