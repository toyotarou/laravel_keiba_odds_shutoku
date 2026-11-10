"""Compare the approved ranking and the submitted publisher all the way to the public read DTO."""
import argparse
from copy import deepcopy
from datetime import timedelta
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
OLD_DIRECTORY = None
if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php')
    parser.add_argument('controller',type=Path)
    parser.add_argument('--observe-r1511',type=Path,metavar='OLD_W2_DIRECTORY')
    parser.add_argument('--permutations',action='store_true')
    parser.add_argument('--upgrade',nargs=2,type=Path,metavar=('OLD_CONTROLLER','OLD_W2_DIRECTORY'))
    args = parser.parse_args()
    if args.observe_r1511:
        OLD_DIRECTORY = args.observe_r1511.resolve()/'validation'
        if sha256(args.controller.read_bytes()).hexdigest() != 'f0e394f8bde573ae2fedba3c4843453324b31a0ab2f957c06b2e7f949897885a' or sha256((OLD_DIRECTORY.parent/'php/CandidateSelectionW2.php').read_bytes()).hexdigest() != '4384aadc09a426dcd4200b25f2f4c126d16aa46654671a379dd8de169ab23e85':
            parser.error('Use the original R15.11 W2 directory and its patched submitted controller.')
        sys.path.insert(0,str(OLD_DIRECTORY))

from tempfile import TemporaryDirectory

from verify_r1510_read import ReadTrial
from verify_r1511_longshots import change_role
from verify_r157_receive import db_time, dump, require, microseconds

HERE = Path(__file__).resolve().parent
ROLES = ('1st', '2nd')


class PublicationTrial(ReadTrial):
    def __init__(self, php, controller, directory):
        super().__init__(php, controller, directory)
        self.db.execute('ALTER TABLE baganriki_ai_attempts ADD COLUMN id INTEGER')
        self.db.execute('ALTER TABLE baganriki_ai_attempts ADD COLUMN sent_at TEXT')

    def setup(self):
        super().setup()
        self.role_rows=deepcopy(self.rows)
        self.status={}
        self.now=self.start+timedelta(seconds=6)

    def us(self, instant):
        return microseconds(instant-instant.fromtimestamp(0,instant.tzinfo))

    def publish_db(self, **opt):
        p=subprocess.run([self.php,str(HERE/'r1512_publication.php'),str(self.path),'_',str(self.controller)]+([str(OLD_DIRECTORY)] if OLD_DIRECTORY else []),
                         input=dump(dict(cmd='publish',clock=self.us(self.now),**opt)),text=True,capture_output=True,check=True)
        require(not p.stderr,p.stderr)
        return json.loads(p.stdout)

    def send(self, role):
        first=self.start+timedelta(seconds=1)
        row=self.worker('reserve',first,race=1,role=role,slot=0)
        require(row['ok'],row)
        at=self.start+timedelta(seconds=2)
        ok=self.worker('send',at,token=row['token'])
        require(ok['ok'],ok)
        self.db.execute('UPDATE baganriki_ai_attempts SET id=rowid,sent_at=? WHERE token=?',
                        (at.isoformat(timespec='microseconds'),row['token']))
        self.status[role]=[row['token'],at,None,False]

    def receive(self, role, at=None, complete=True):
        at=at or self.start+timedelta(seconds=3)
        state=self.status[role];row=self.role_rows[role]
        result=self.worker('receive',at,token=state[0],raw=row['original'],complete=complete)
        if result['ok']: state[2:]=[at,complete]
        return result

    def expected(self, selected):
        # Reopen verified stored W2 receipts with their independent dispatch proof and the official roster.
        s=self.row();packet=deepcopy(self.data);rows=[]
        for role in ROLES:
            row=deepcopy(self.role_rows[role]);ok=role in selected
            if ok:
                _,sent,received,complete=self.status[role]
                row['receipt'].update(sent_at=sent.isoformat(timespec='microseconds'),received_at=received.isoformat(timespec='microseconds'),complete=complete)
            row['receipt']['original_approved']=ok
            if not ok: row['proof_horses']=[]
            rows.append(row)
        gone=json.loads(s[0]);packet.update(roles=rows,now=self.now.isoformat(timespec='microseconds'),send=False,cancelled=gone)
        packet['live']=dict(race_id=packet['race_id'],snapshot_hash=packet['hash'],now=packet['now'],
                            live_close_at=None if s[2] is None else s[2].replace(' ','T')+'+09:00',
                            state_revision=s[3],response_revision=s[4],publication_revision=s[5],cancelled_horse_nos=gone,
                            all_cancelled=s[1]==0,race_started=False)
        packet['live']['role_journal']=self.replay('adopt',packet)['data']['role_journal']
        opened=self.replay('open',packet)
        if not selected:
            require(not opened['ok'] and opened['reason']=='W2_NO_VERIFIED_ROLE',opened)
            return None
        require(opened['ok'],opened)
        result=opened['data']['result']
        require(self.replay('commit',dict(packet,selection=result,finished_at=packet['now']))['ok'],'canonical commit')
        return result

    def compare(self, roles, old=False):
        canonical=self.expected(roles);g=self.publish_db()
        if canonical is None:
            require(g['code']=='NO_RESPONSE' and self.scalar('SELECT count(*) FROM baganriki_publications')==0,g)
            return None
        picks=canonical['public_candidates']
        require(g['code']=='PUBLISHED',g)
        rows=g['candidates'];written=[h['horse_no'] for h in rows]
        dbrows=json.loads(self.db.execute('SELECT candidates FROM baganriki_publications WHERE publication_id=?',(g['publication_id'],)).fetchone()[0])
        dto=self.get(clock=self.us(self.now))['body']
        require(dbrows==rows and dto['state']=='PUBLISHED' and dto['live_state']['available_candidate_nos']==written and not dto['buy_recommended'],dto)
        if old:
            require(picks==[1,2,3,7,8,10,12] and written==[1,2,3,7,8,9,11],(picks,written))
            require(canonical['agreement_bonus']['2']==0 and next(r['bonus'] for r in rows if r['horse_no']==2)==5,rows)
            print('R15.11 canonical 1/2/3/7/8/10/12; actual SQL and GET 1/2/3/7/8/9/11. Horse 2 also received an unearned +5.',flush=True)
            return g
        require(written==picks,(roles,written,picks))
        require(g['shortfalls']==canonical['shortfalls'] and g['fill']==canonical['fill'] and g['actual_quota']==canonical['actual_quota'],g)
        proof=canonical['public_proof_hashes']
        for row in rows:
            n=str(row['horse_no'])
            require(row['bonus']==canonical['agreement_bonus'][n] and row['bonus_proven']==(row['bonus']==5), row)
            require(row['public_proof_hash']==(proof.get(n) if isinstance(proof,dict) else None),row)
        return g

    def put(self, role):
        self.send(role);require(self.receive(role)['ok'],'normal receipt')

    def future_revision(self, old):
        self.setup();self.put('1st');self.send('2nd')
        rev=self.row()[4]
        out=self.publish_db(read_revisions={'response':rev+1},arrival={'token':self.status['2nd'][0],'raw':self.role_rows['2nd']['original']})
        if old:
            require(out['code']=='PUBLISHED' and self.row()[4]==rev+1 and int(self.scalar('SELECT used_response_revision FROM baganriki_publications'))==rev+1,out)
            print('R15.11 future response revision: single-role result committed as the next revision after the second receipt.',flush=True)
        else:
            require(out['code']=='STALE' and self.row()[4]==rev and self.scalar('SELECT COUNT(*) FROM baganriki_publications')==0,out)
            for bad in (str(rev+1),False,1.0,'0'+str(rev),'',str(2**64),None):
                out=self.publish_db(read_revisions={'response':bad})
                require(out['code']=='STALE' and self.scalar('SELECT COUNT(*) FROM baganriki_publications')==0,(bad,out))
            # A current, canonical numeric string is an allowed optimistic precondition.
            ok=self.publish_db(read_revisions={'response':str(rev)})
            require(ok['code']=='PUBLISHED',ok)
            print('PASS future, stale and ill-typed caller revisions rejected before writing; exact current read revision allowed.',flush=True)

    def empty(self,old=False):
        from r1519_fixture import reviews
        self.setup()
        for role in ROLES:
            row=self.role_rows[role]
            reply=change_role(row,None)
            for h in reply['horses']:
                if h['band']!='A':continue
                h.update(eligibility='UNVERIFIED',tier='UNVERIFIED',rank_basis='NOT_RANKED',evidence=[],evidence_rank_evidence_ids=[],
                         public_candidate=False,nonselect_reason={'code':'UNVERIFIED','reason':'合成試験では資料不足'})
                for key in h['family_review']:
                    h['family_review'][key]={'status':'DATA_MISSING','reason':'合成試験では資料不足','evidence_ids':[],'source_refs':[]}
            for k in ('band_ranks','evidence_band_ranks','selection_ranks'):
                reply[k]['A']=[]
            reply['public_candidates']=[]
            reply['expected_reviews']['A']={'incumbent':None,'challenger':None,'decision':'NO_BOUNDARY'}
            q=reply['role_quota']['A']
            if q:reply['shortfalls'].insert(0,{'band':'A','requested':q,'eligible':0,'missing':q,'reason':'資料不足'})
            lines=row['original'].splitlines()
            reply=reviews(row['input'],reply)
            raw=lines[0]+'\n'+dump(reply)+'\n'+lines[-1]
            row['original']=raw;row['proof_horses']=[];row['receipt']['response_hash']=sha256(raw.encode()).hexdigest()
            self.put(role)
        if old:
            wanted=self.expected(ROLES);got=self.publish_db()
            require(wanted['selection_state']=='VERIFIED_NO_CANDIDATES' and wanted['public_candidates']==[] and got['code']=='NO_RESPONSE',got)
            print('R15.11 verified zero eligible: canonical confirmed empty, submitted publisher NO_RESPONSE.',flush=True)
            return
        result=self.compare(ROLES)
        require(result['candidates']==[] and result['actual_quota']=={'A':0,'B':0,'D':0} and sum(x['missing'] for x in result['shortfalls'])==7,result)
        print('PASS two verified full responses with zero eligible horses publish and read a confirmed empty list, all 7 vacancies recorded.',flush=True)

    def verify(self,old):
        self.setup()
        for r in ROLES: self.put(r)
        result=self.compare(ROLES,old)
        if old:
            self.future_revision(True);self.empty(True)
            return
        self.future_revision(False)
        print('PASS normal dual receipt: W2 commit, exact bonus/proofs, SQL publication and GET agree on 1/2/3/7/8/10/12; buys false.',flush=True)
        for r in ROLES:
            other=ROLES[1-ROLES.index(r)]
            for case in ('missing','sent','incomplete','late','hash','alternate_input','future'):
                self.setup();self.put(r)
                if case!='missing':
                    self.send(other)
                    if case=='late':
                        self.now=self.sales-timedelta(seconds=89,microseconds=999998)
                        got=self.receive(other,self.sales-timedelta(seconds=90)+timedelta(microseconds=1))
                        require(not got['ok'] and got['code']=='DEADLINE_PASSED',got)
                    elif case=='incomplete':require(self.receive(other,complete=False)['ok'],'incomplete kept')
                    elif case in ('hash','alternate_input','future'):
                        require(self.receive(other)['ok'],'initial valid role')
                        if case=='hash':self.db.execute('UPDATE baganriki_ai_attempts SET response_hash=? WHERE role=?',('0'*64,other))
                        elif case=='future':self.db.execute('UPDATE baganriki_ai_attempts SET received_at=? WHERE role=?',(db_time(self.now+timedelta(microseconds=1)),other))
                        else:
                            col='input_json_'+other
                            self.db.execute('UPDATE baganriki_snapshots SET '+col+'=?', (self.rows[r]['input'],))
                result=self.compare([r]);require(all(h['bonus']==0 for h in result['candidates']),case)
        print('PASS 14 single-role cases: either role can survive a missing, pending, incomplete, late, corrupt, role-swapped or future counterpart without bonus.',flush=True)
        self.setup();self.compare([])
        self.setup();self.send('1st');self.send('2nd');self.compare([])
        print('PASS no verified response: no new publication for absent or still pending roles.',flush=True)
        for r in ROLES:
            other=ROLES[1-ROLES.index(r)];self.setup();self.put(r);self.send(other)
            before=self.compare([r]);id1=before['publication_id'];oldrows=deepcopy(before['candidates'])
            self.now=self.sales-timedelta(seconds=90)
            require(self.receive(other,self.now)['ok'],'receipt at inclusive deadline')
            unchanged=self.get(clock=self.us(self.now))['body']
            require(unchanged['publication']['publication_id']==id1,'a receipt must not publish or reorder the screen')
            after=self.compare(ROLES)
            require(after['publication_id']!=id1 and self.scalar('SELECT COUNT(*) FROM baganriki_publications')==2,after)
            stored=self.db.execute('SELECT candidates FROM baganriki_publications WHERE publication_id=?',(id1,)).fetchone()[0]
            require(json.loads(stored)==oldrows,'first publication history changed')
            totals=self.counts()
            for _ in range(3):require(self.get(clock=self.us(self.now))['body']['publication']['publication_id']==after['publication_id'],'read latest')
            require(totals==self.counts(),'refresh caused writes')
        self.setup();self.put('1st');self.send('2nd')
        conflict=self.publish_db(arrival={'token':self.status['2nd'][0],'raw':self.role_rows['2nd']['original']})
        require(conflict['code']=='STALE' and self.scalar('SELECT COUNT(*) FROM baganriki_publications')==0,conflict)
        self.status['2nd'][2:]=[self.now,True];self.compare(ROLES)
        print('PASS deadline equality, both arrival orders, immutable first publication, stale in-flight commit refused, fresh dual receipt and read-only refresh.',flush=True)
        for gone in ([10],[12],[7,10],[8,10,11,12],[1,2,3,4,5,6],list(range(7,17))):
            self.setup()
            for r in ROLES:self.put(r)
            require(self.update(gone)['code']=='APPLIED',gone)
            pub=self.compare(ROLES);require(not set(gone)&{h['horse_no'] for h in pub['candidates']},gone)
        print('PASS 6 official withdrawal sets: E boundary, reduced field, structural reallocation and all outsider slots removed, with identical order in SQL and GET.',flush=True)
        self.empty()
        for role in ROLES:
            for target in (7,10,12,16):
                self.setup()
                for r in ROLES:
                    change_role(self.role_rows[r],target if r==role else None);self.put(r)
                pub=self.compare(ROLES)
                require([h['horse_no'] for h in pub['candidates'] if h['horse_no']>=7]==[target],pub)
        print('PASS 8 real raw-response scenarios: one supported outsider in either AI survives with one evidence family, without favourite padding.',flush=True)


def smoke(php,controller):
    with TemporaryDirectory() as temp:
        t=PublicationTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            t.setup()
            for role in ROLES:t.put(role)
            ok=t.compare(ROLES)
            require([h['horse_no'] for h in ok['candidates']]==[1,2,3,7,8,10,12],ok)
            t.future_revision(False);t.empty()
        finally:t.db.close()
    print('PASS R15.12 standard publication: E swaps, exact bonuses, caller revision bound to the read and verified empty.',flush=True)


def permutations(php,controller):
    import random
    from collections import Counter
    rng=random.Random(291104)
    outcomes=Counter()
    public_lists=set()
    with TemporaryDirectory() as temp:
        t=PublicationTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            for _ in range(12):
                t.setup()
                orders={};nominations={}
                for role,row in t.role_rows.items():
                    inp=json.loads(row['input'])
                    odds={h['horse_no']:h for h in inp['expected_v01']['rows']}
                    lines=row['original'].splitlines()
                    reply=json.loads('\n'.join(lines[1:-1]))
                    original=[];promoted=[]
                    for band,base in reply['band_ranks'].items():
                        rng.shuffle(base)
                        q=reply['role_quota'][band];inc,chall=base[q-1:q+1]
                        a,b=odds[inc],odds[chall]
                        require(a['status']==b['status']=='COMPARISON_READY' and a['basis']==b['basis'],'fixture E data')
                        primary,secondary=('5','3') if band=='A' else ('3','5')
                        swap=(b['E'+primary]>a['E'+primary] and b['E'+secondary]>=a['E'+secondary] and b['p'+primary+'_raw']>b['p0_'+primary])
                        final=base.copy()
                        if swap:final[q-1:q+1]=[chall,inc]
                        decision='SWAP' if swap else 'KEEP';outcomes[decision]+=1
                        reply['expected_reviews'][band]={'incumbent':inc,'challenger':chall,'decision':decision}
                        reply['evidence_band_ranks'][band]=base.copy();reply['selection_ranks'][band]=final
                        original+=base[:q];promoted+=final[:q]
                    reply['public_candidates']=promoted
                    for h in reply['horses']:
                        chosen=h['horse_no'] in promoted;h['public_candidate']=chosen
                        h['nonselect_reason']=None if chosen else {'code':'BAND_QUOTA','reason':'合成した帯内順位に従うと枠外'}
                    raw=lines[0]+'\n'+dump(reply)+'\n'+lines[-1]
                    row['original']=raw;row['proof_horses']=original;row['receipt']['response_hash']=sha256(raw.encode()).hexdigest()
                    orders[role]=reply['selection_ranks'];nominations[role]=set(original)&set(promoted)
                    t.put(role)
                proven=nominations['1st']&nominations['2nd'];expect=[]
                for band,q in {'A':3,'B':3,'D':1}.items():
                    first,second=(orders[r][band] for r in ROLES);size=len(first)
                    def sort_key(n):
                        rank=min(first.index(n),second.index(n))+1
                        bonus=5 if n in proven else 0
                        return (-100*(size-rank+1)-bonus*size,-bonus,n)
                    expect+=sorted(first,key=sort_key)[:q]
                got=t.compare(ROLES)
                require([x['horse_no'] for x in got['candidates']]==expect,(expect,got))
                require(all(x['bonus']==(5 if x['horse_no'] in proven else 0) for x in got['candidates']),got)
                public_lists.add(tuple(expect))
            require(outcomes['SWAP']>0 and outcomes['KEEP']>0 and len(public_lists)>=9,(outcomes,public_lists))
            print(f"PASS 12 independent paired role orderings, {outcomes['SWAP']} SWAP / {outcomes['KEEP']} KEEP over 72 boundaries, {len(public_lists)} distinct ordered public lists; independent Python scores, W2 replay, SQL and GET agree.",flush=True)
        finally:t.db.close()


def upgrade(php,controller,previous):
    old,old_dir=previous
    if sha256(old.read_bytes()).hexdigest()!='f0e394f8bde573ae2fedba3c4843453324b31a0ab2f957c06b2e7f949897885a':
        raise ValueError('Use the R15.11 controller for the archive replay.')
    with TemporaryDirectory() as temp:
        t=PublicationTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            t.setup()
            for role in ROLES:t.put(role)
            before=t.scalar('SELECT COUNT(*) FROM provider_stub')
            proc=subprocess.run([t.php,str(HERE/'r1512_publication.php'),str(t.path),'_',str(old.resolve()),str(old_dir.resolve()/'validation')],
                                input=dump(dict(cmd='publish',clock=t.us(t.now))),text=True,capture_output=True,check=True)
            require(not proc.stderr,proc.stderr)
            original=json.loads(proc.stdout)
            require(original['code']=='PUBLISHED' and [h['horse_no'] for h in original['candidates']]==[1,2,3,7,8,9,11],original)
            oldid=original['publication_id'];dto=t.get(clock=t.us(t.now))['body']
            require(dto['publication']['publication_id']==oldid and dto['live_state']['available_candidate_nos']==[1,2,3,7,8,9,11],dto)
            row=t.db.execute('SELECT candidates FROM baganriki_publications WHERE publication_id=?',(oldid,)).fetchone()[0]
            current=t.compare(ROLES)
            require(current['publication_id']!=oldid and t.scalar('SELECT COUNT(*) FROM baganriki_publications')==2,current)
            require(t.db.execute('SELECT candidates FROM baganriki_publications WHERE publication_id=?',(oldid,)).fetchone()[0]==row,'old raw list')
            require(t.scalar('SELECT COUNT(*) FROM provider_stub')==before,'unexpected extra AI request')
            print('PASS R15.11 to R15.12 on one database: old ID and ordered bytes retained; new approved publication has a new ID and the corrected order; zero extra calls.',flush=True)
        finally:t.db.close()


def run(php,controller,old=False):
    with TemporaryDirectory() as temp:
        t=PublicationTrial(str(php),Path(controller).resolve(),Path(temp))
        try:t.verify(old)
        finally:t.db.close()


if __name__=='__main__':
    if args.permutations:permutations(args.php,args.controller)
    elif args.upgrade:upgrade(args.php,args.controller,args.upgrade)
    else:run(args.php,args.controller,bool(args.observe_r1511))
