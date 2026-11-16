"""Independent checks of a surviving candidate, receipt order and scoped corrections."""
from datetime import datetime, timedelta
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory

BASE=Path(__file__).resolve().parent
from verify_r1513_corrected_publication import change_raw
from verify_r1517_queue import QueueTrial
from verify_r157_receive import db_time,dump

ROLES=('1st','2nd')
GLOBAL=('unanswered','before_reservation','known_failure','unknown_failure','incomplete','truncated','double_frame','duplicate_key','foreign_race','opposite_role','wrong_snapshot','saved_hash')
CASES=GLOBAL+('bad_nominee','bad_other_horse','clean')
def ok(test,label):
    if not test:raise AssertionError(label)
def identity(x): return [r['horse_no'] for r in x]
def other(role): return '2nd' if role=='1st' else '1st'
def doc(raw):
    head,body,tail=raw.splitlines()
    return head,json.loads(body),tail
def pack(head,p,tail):
    return '\n'.join((head,dump(p),tail))
def mutate(raw,case,foreign,opposite):
    h,p,e=doc(raw)
    if case=='incomplete':return raw,False
    if case=='truncated':return raw[:len(raw)//2],True
    if case=='double_frame':return raw+'\n'+raw,True
    if case=='duplicate_key':
        return h+'\n'+dump(p)[:-1]+',"race_id":'+json.dumps(p['race_id'])+'}\n'+e,True
    if case=='foreign_race':return foreign,True
    if case=='opposite_role':return opposite,True
    if case=='wrong_snapshot':p['snapshot_hash']='c'*64
    if case in ('bad_nominee','bad_other_horse'):
        n=10 if case=='bad_nominee' else 14
        horse=next(x for x in p['horses'] if x['horse_no']==n)
        # One horse asserts a numeric value that disagrees with the stored source.
        assert horse['evidence'][0]['facts'][0]['value']!=999
        horse['evidence'][0]['facts'][0]['value']=999
    if case not in ('wrong_snapshot','bad_nominee','bad_other_horse'):return raw,True
    return pack(h,p,e),True

class Test(QueueTrial):
    def bundle(self,supported,pattern='sparse',race='20261017-4-05-07-11'):
        key=(supported,pattern,race)
        if key not in self.cache:
            subprocess.run([self.php,str(BASE/'r1518_longshot_fixture.php'),str(self.directory),'MIXED',supported,pattern,race],check=True,capture_output=True)
            out=self.replay('export');ok(out['ok'],('fixture exact input/sent response binding',key,out.get('reason')))
            self.cache[key]=out['data']
        return self.cache[key]
    def configure(self,finder,pattern='sparse'):
        x=self.bundle(finder,pattern)
        self.data=x;self.rows={r['role']:r for r in x['roles']};self.nums=x['numbers']
        inp=json.loads(self.rows['1st']['input'])
        assert [(r['horse_no'],r['win_rank']) for r in inp['runners']]==[(n,17-n) for n in range(1,17)]
        assert all(r['E3'] is None and r['E5'] is None for r in inp['expected_v01']['rows'])
        self.start=datetime.fromisoformat(inp['input_freeze_at']);self.sales=datetime.fromisoformat(inp['time_contract']['sales_close_at'])
        self.limit=self.sales-timedelta(seconds=90)
        self.setup();self.now=self.limit-timedelta(seconds=1)
    def hook(self,role,inline=False):
        cmd=[self.php,str(BASE/'r1518_callback.php'),str(self.path),'_',str(self.controller)]
        a=subprocess.run(cmd,input=dump(dict(clock=self.us(self.now),role=role,inline=inline)),text=True,capture_output=True,check=True)
        d=json.loads(a.stdout);rc=d['result']
        ok(d['network_attempts']==0 and rc['ran'] and rc['settled'] and (rc['settled']['marked'] or rc['settled']['already']),('real callback failed',role,rc,a.stderr[-500:]))
        ok('接続で例外' not in a.stderr and rc['publish'] is not None,('callback exception',role,rc))
        if not inline:ok(rc['publish']['code']=='HANDED_TO_AGGREGATE',rc['publish'])
        return rc
    def count_get(self):
        before=self.committed();body=self.get(clock=self.us(self.now))['body']
        ok(not body['buy_recommended'] and before==self.committed(),'read-only GET, no purchase/call')
        return body
    def verify_public(self,out,outsiders,zero_all=False,shared5=False):
        ok(out['code'] in ('PUBLISHED','ALREADY'),('no publication',out['code'],out['reason']))
        rows=out['candidates'];nums=identity(rows)
        ok(len(nums)==len(set(nums)) and [n for n in nums if 17-n>=7]==outsiders,('outsider and order',nums,outsiders))
        ok(all((h['band']=='A' if 17-h['horse_no']<=6 else h['band']=='B' if 17-h['horse_no']<=10 else h['band']=='D') for h in rows),'correct frozen bands')
        for h in rows:
            if zero_all or (17-h['horse_no']>=7 and not (shared5 and h['horse_no']==5)):
                ok(h['bonus']==0 and not h['bonus_proven'] and not h['public_proof_hash'],('false agreement',h['horse_no'],h['bonus']))
        if shared5:
            good=next(row for row in rows if row['horse_no']==5)
            ok(good['bonus']==5 and good['bonus_proven'] and len(set(good['public_proof_hash'].values()))==2,'exact two role hashes')
            for role in ROLES:
                actual=self.scalar('select response_hash from baganriki_ai_attempts where role=? and attempt_no=0',role)
                ok(good['public_proof_hash'][role]==actual,('original response proof',role))
        stored=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',out['publication_id'])
        state=self.count_get();ok(json.loads(stored)==rows==state['publication']['candidates'] and state['publication']['publication_id']==out['publication_id']
            and state['live_state']['available_candidate_nos']==nums and state['state']=='PUBLISHED',('saved/display mismatch',nums))
        counts=self.committed();again=self.request('when_ready')
        ok(again['code']=='ALREADY' and again['publication_id']==out['publication_id'] and again['candidates']==rows and counts==self.committed(),'duplicate job changed an ID or called the provider')
        return nums
    def run_case(self,finder,case,wrong_first,inline=False):
        bad=other(finder);self.configure(finder)
        foreign=self.bundle(finder,race='20261017-4-05-07-12')
        other_raw=next(r['original'] for r in foreign['roles'] if r['role']==bad)
        ok(doc(other_raw)[1]['race_id']!=self.data['race_id'] and doc(other_raw)[1]['snapshot_hash']!=self.data['hash'],'foreign input test')
        order=[bad,finder] if wrong_first else [finder,bad]
        for role in order:
            if role==bad and case=='before_reservation':continue
            self.send(role)
        # All sends and receives are separate real queue processes in this isolated fixture.
        for i,role in enumerate(order):
            at=self.limit-timedelta(seconds=2)+timedelta(microseconds=i*200)
            self.now=at+timedelta(microseconds=50)
            if role==bad and case in ('unanswered','before_reservation'):continue
            if role==bad and case in ('known_failure','unknown_failure'):
                failure=self.worker('fail',at,token=self.status[role][0],unknown=case=='unknown_failure')
                ok(failure['ok'],('failed transport transition',failure));continue
            raw=self.role_rows[role]['original'];complete=True
            if role==bad:raw,complete=mutate(raw,case,other_raw,self.role_rows[finder]['original'])
            self.role_rows[role]['original']=raw
            receipt=self.receive(role,at=at,complete=complete)
            ok(receipt['ok'],('receipt',case,receipt))
            if role==bad and case=='saved_hash':
                self.db.execute("UPDATE baganriki_ai_attempts SET response_hash=? WHERE role=? AND attempt_no=0",('0'*64,role))
            rc=self.hook(role,inline)
            if inline:
                expect='PUBLISHED' if i==1 and case in ('bad_nominee','opposite_role') else 'WAITING'
                ok(rc['publish']['code']==expect,('synchronous callback itself',role,i,case,rc['publish']))
                current=self.count_get()['publication']
                if expect=='PUBLISHED':
                    ok(current['publication_id']==rc['publish']['publication_id'] and identity(current['candidates'])==[16,15,14,5],('immediate publication before repeat',current))
                else:ok(current is None,'no premature publication')
            if rc['fix'] and case in ('bad_nominee','bad_other_horse'):
                ok(not rc['fix']['sent'],'correction attempted below minimum remaining time')
        self.now=self.limit-timedelta(seconds=1)
        sent=self.scalar('SELECT count(*) FROM provider_stub')
        prior=self.committed()
        out=self.request('when_ready')
        if case in ('unanswered','before_reservation'):
            ok(out['code']=='WAITING' and self.count_get()['publication'] is None and prior==self.committed(),('not waiting',case,out['code']))
            self.now=self.limit;out=self.request('when_ready')
        expected=[10,5] if case in ('bad_other_horse','clean') else [5]
        nums=self.verify_public(out,expected,case in GLOBAL)
        if case in GLOBAL:ok(nums==[16,15,14,5],('valid role alone',case,finder,nums))
        ok(sent==self.scalar('SELECT count(*) FROM provider_stub') and self.scalar('SELECT count(*) FROM baganriki_publications')==1,('extra sends/IDs',case))
        print('CASE',finder,case,'bad-first' if wrong_first else 'good-first','inline' if inline else 'queue',nums,flush=True)
    def controls(self):
        for pattern in ('shared','empty','invalid'):
            self.configure('1st',pattern='sparse' if pattern=='invalid' else pattern)
            for role in ROLES:self.send(role)
            for i,role in enumerate(ROLES):
                at=self.limit-timedelta(seconds=2)+timedelta(microseconds=i*200)
                self.now=at+timedelta(microseconds=50)
                if pattern=='invalid':self.role_rows[role]['original']='unreadable'
                ok(self.receive(role,at=at)['ok'],'control receipt')
                self.hook(role)
            out=self.request('when_ready')
            if pattern=='invalid':
                ok(out['code']=='NO_RESPONSE' and not out['publication_id'] and not out['candidates'] and self.committed()[0]==0 and self.count_get()['publication'] is None,('neither response is usable',out['code']))
            elif pattern=='empty':
                nums=self.verify_public(out,[],zero_all=True);ok(not nums and sum(h['missing'] for h in out['shortfalls'])==7,'valid checked empty is distinguishable from no response')
            else:self.verify_public(out,[5],shared5=True)
            print('CONTROL',pattern,out['code'],flush=True)

MODES=('correct','foreign_race','other_role','incomplete','out_of_scope','unchanged','hash_mismatch')
class Trial(Test):
    def corrected(self, finder, pattern, mode):
        bad=other(finder)
        self.configure(finder,pattern)
        target=5 if pattern=='shared' else 10
        foreign=self.bundle(finder,pattern,race='20261017-4-05-07-12')
        foreign_raw=next(r['original'] for r in foreign['roles'] if r['role']==bad)
        orig=self.role_rows[bad]['original']
        broken=change_raw(orig,[(target,'evidence')])
        self.role_rows[bad]['original']=broken
        for role in ROLES:self.send(role)
        for i,role in enumerate(ROLES):
            at=self.start+timedelta(seconds=3,microseconds=i*10)
            ok(self.receive(role,at=at)['ok'],'initial receipt')
            self.now=at+timedelta(microseconds=2)
            self.settle(role)
        # Enforce actual singleton scope, create the correction dispatch with the submitted W2 code
        # and save its send record separately from the answer to it.
        grant=self.inspect(bad,with_grant=True)
        ok(grant['scope']['first_response_hash']==sha256(broken.encode()).hexdigest()
           and grant['scope']['horses']==[target],('unexpected diagnosis or scope',bad,target,grant['scope']))
        row=self.role_rows[bad]
        prepared=self.replay('record_correction_fixture',dict(common=self.data['common'],input=row['input'],hash=self.data['hash'],
              witness=self.data['witness'],role=bad,original=broken,format=row['receipt']['sent_request']['format'],
              scope=grant['scope'],diagnostics=grant['diagnostics']))
        ok(prepared['ok'],('dispatch not verified',prepared))
        self.db.execute('CREATE TABLE IF NOT EXISTS r1513_fix_dispatch(role TEXT PRIMARY KEY,sent TEXT)')
        self.db.execute('INSERT OR REPLACE INTO r1513_fix_dispatch(role,sent) VALUES(?,?)',(bad,dump(prepared['data'])))
        orig_public=self.request('when_ready')
        self.verify_public(orig_public,[5])
        old_id=orig_public['publication_id'];old_nums=identity(orig_public['candidates']);old_serial=dump(orig_public['candidates'])
        at=self.start+timedelta(seconds=4)
        token=self.begin_fix(bad,at);self.send_fix(token,at+timedelta(microseconds=10))
        during=self.request('when_ready')
        ok(during['code']=='WAITING' and self.count_get()['publication']['publication_id']==old_id,'waiting for a possible correction')
        raw={'correct':orig,'foreign_race':foreign_raw,'other_role':self.role_rows[finder]['original'],'incomplete':orig,
             'out_of_scope':change_raw(orig,[(16,'outside')]),'unchanged':broken,'hash_mismatch':orig}[mode]
        self.now=self.start+timedelta(seconds=5)
        ok(self.worker('receive',self.now,token=token,raw=raw,complete=mode!='incomplete')['ok'],'receive correction')
        if mode=='hash_mismatch':
            self.db.execute('UPDATE baganriki_ai_attempts SET response_hash=? WHERE role=? AND attempt_no=1',('d'*64,bad))
        self.now+=timedelta(microseconds=10)
        inspected=self.inspect(bad)
        self.settle(bad)
        out=self.request('when_ready');calls=self.scalar('SELECT count(*) FROM provider_stub')
        ok(out['code']=='PUBLISHED' and out['publication_id']!=old_id and calls==3 and self.committed()[2]==3,('new receipt ID',out['code'],calls))
        winners=identity(out['candidates']);outside=[n for n in winners if 17-n>=7]
        ok(outside==([10,5] if mode=='correct' and pattern=='sparse' else [5]),('outsider after fix',pattern,mode,outside))
        ok((target in inspected['rescued'])==(mode=='correct'),('scope actually rescued',mode,inspected))
        for h in out['candidates']:
            if 17-h['horse_no']<7:continue
            if pattern=='shared' and mode=='correct' and h['horse_no']==5:
                want={finder:sha256(self.role_rows[finder]['original'].encode()).hexdigest(),bad:sha256(orig.encode()).hexdigest()}
                ok(h['bonus']==5 and h['bonus_proven'] and h['public_proof_hash']==want,('which original/repair version was used',h['public_proof_hash'],want))
            else:ok(h['bonus']==0 and not h['bonus_proven'] and not h['public_proof_hash'],('false agreement',h['horse_no'],h['bonus']))
        stored=json.loads(self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',out['publication_id']))
        archived=json.loads(self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',old_id))
        view=self.count_get();before=self.committed();again=self.request('when_ready')
        ok(dump(archived)==old_serial and identity(archived)==old_nums,'earlier publication was mutated')
        ok(stored==out['candidates']==view['publication']['candidates'] and view['publication']['publication_id']==out['publication_id']
           and view['live_state']['available_candidate_nos']==winners and again['code']=='ALREADY' and again['publication_id']==out['publication_id']
           and before==self.committed(),'read-only display and repeated job')
        print('FIX',finder,pattern,mode,inspected['code'],outside,flush=True)


def main(php,controller,section):
    if section not in ('response','correction'):raise ValueError('choose response or correction')
    with TemporaryDirectory() as folder:
        t=Trial(php,Path(controller).resolve(),Path(folder));t.cache={}
        try:
            if section=='response':
                for finder in ROLES:
                    for case in CASES:
                        for wrong_first in (False,True):t.run_case(finder,case,wrong_first)
                for finder in ROLES:
                    for case in ('known_failure','bad_nominee','opposite_role','unanswered'):
                        t.run_case(finder,case,False,inline=True)
                t.controls()
                print('PASS 60 queue cases, 8 inline callback cases and 3 controls',flush=True)
            else:
                for finder in ROLES:
                    for pattern in ('sparse','shared'):
                        for mode in MODES:t.corrected(finder,pattern,mode)
                print('PASS 28 correction paths, independent and shared longshot, both roles',flush=True)
        finally:t.db.close()

if __name__=='__main__':main(*sys.argv[1:])
