"""Drive the staged W2 sender, a durable reservation, response inspection, and the public read endpoint."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
from verify_r1517_queue import QueueTrial
from verify_r157_receive import dump, require as check

HERE=Path(__file__).resolve().parent
ROLES=('1st','2nd')
def opposite(role): return '2nd' if role=='1st' else '1st'
class Trial(QueueTrial):
    def configure(self,finder,pattern='sparse'):
        subprocess.run([self.php,str(HERE/'r1518_longshot_fixture.php'),str(self.directory),'MIXED',finder,pattern],check=True,capture_output=True)
        packet=self.replay('export');check(packet['ok'],packet.get('reason'))
        self.data=packet['data'];self.rows={r['role']:r for r in self.data['roles']};self.nums=self.data['numbers']
        input_data=json.loads(self.rows[finder]['input']);self.start=datetime.fromisoformat(input_data['input_freeze_at'])
        self.sales=datetime.fromisoformat(input_data['time_contract']['sales_close_at']);self.limit=self.sales-timedelta(seconds=90)
        check(next(r['win_rank'] for r in input_data['runners'] if r['horse_no']==5)==12 and all(r['E3'] is None and r['E5'] is None for r in input_data['expected_v01']['rows']),'mixed market')
        self.setup();self.now=self.start+timedelta(seconds=4)
        for t in ('sender18_calls','sender18_receipts'):self.db.execute('DELETE FROM '+t)
    def reserve(self,role,slot):
        result=self.worker('reserve',self.now-timedelta(seconds=1),race=1,role=role,slot=slot);check(result['ok'],result);return result['token']
    def run_op(self,op,role,slot,token,**extra):
        cmd=[self.php,str(HERE/'r1518_persistent.php'),str(self.path),'_',str(self.controller)]
        request=dict(op=op,role=role,slot=slot,token=token,examples=str(self.directory),clock=self.us(self.now),**extra)
        a=subprocess.run(cmd,input=dump(request),capture_output=True,text=True)
        check(a.returncode==0 and not a.stderr and a.stdout.startswith('{'),(op,a.returncode,a.stderr[-600:],a.stdout[:390],a.stdout[-130:]));return json.loads(a.stdout)
    def count(self):return self.scalar('SELECT count(*) FROM sender18_calls')
    def view(self,subset,agreement):
        out=self.request('when_ready');check(out['code']=='PUBLISHED',out['code']);rows=out['candidates'];nums=[h['horse_no'] for h in rows]
        check([n for n in nums if 17-n>=7]==subset,(nums,subset));h=next(item for item in rows if item['horse_no']==5)
        check(h['bonus']==agreement and h['bonus_proven']==bool(agreement),('outsider agreement',h['bonus'],agreement))
        saved=json.loads(self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',out['publication_id']))
        num_calls=self.count();view=self.get(clock=self.us(self.now))['body']
        check(view['state']=='PUBLISHED' and view['publication']['candidates']==saved==rows and view['live_state']['available_candidate_nos']==nums and not view['buy_recommended'], 'saved and displayed')
        replay=self.request('when_ready');check(replay['code']=='ALREADY' and replay['publication_id']==out['publication_id'] and self.count()==num_calls,'read or replay called provider')
        return out,h
    def denied(self,role,reason):
        self.configure(role);token=self.reserve(role,0)
        if reason=='ALL_CANCELLED':self.withdraw(self.nums)
        else:self.now=self.limit+timedelta(microseconds=1)
        refused=self.run_op('chain',role,0,token)
        check(refused['code']==reason and refused.get('skip_attempt_update') and self.count()==0,('permission reason',role,reason,refused))
        status,proof=self.db.execute('SELECT status,w2_send_proof FROM baganriki_ai_attempts WHERE token=?',(token,)).fetchone()
        check(status=='RESERVED' and proof[0]=='R','permission withheld')
        print('PASS '+role+' preserves '+reason+'; zero sends and zero state updates by the rejected caller.',flush=True)
    def single(self,finder):
        self.configure(finder);bad=opposite(finder);a=self.reserve(finder,0);b=self.reserve(bad,0)
        check(self.run_op('chain',finder,0,a,receive=True)['ok'],'normal public entry')
        self.now+=timedelta(milliseconds=1);self.settle(finder)
        check(self.run_op('stage',bad,0,b)['ok'] and self.run_op('allow',bad,0,b)['ok'],'separate worker stages')
        self.db.execute(f'UPDATE baganriki_snapshots SET input_json_{bad}=?',(self.rows[finder]['input'],))
        refused=self.run_op('dispatch',bad,0,b);check(refused['code']=='OUTBOUND_MISMATCH' and refused.get('skip_attempt_update') and self.count()==1,'swapped stored row must not call')
        check(self.request('when_ready')['code']=='WAITING','wait for outcome before cutoff')
        self.now=self.limit;self.view([5],0);check(self.count()==1,'one paid-model stub only')
        print('PASS stored role swap, valid solo 12th favourite from '+finder+' persists through SQL and public GET.',flush=True)
    def overlap(self,finder):
        self.configure(finder);token=self.reserve(finder,0)
        check(self.run_op('stage',finder,0,token)['ok'] and self.run_op('allow',finder,0,token)['ok'],'staged')
        altered=self.run_op('dispatch',finder,0,token,tamper=True);check(altered['code']=='OUTBOUND_MISMATCH' and self.count()==0,'bad caller')
        with ThreadPoolExecutor(4) as pool:results=list(pool.map(lambda _:self.run_op('dispatch',finder,0,token,receive=True),range(4)))
        check(sum(bool(r['ok']) for r in results)==1 and self.count()==1 and all(r['ok'] or r.get('skip_attempt_update') for r in results),('four contenders',[(r.get('code'),r.get('reason'),r.get('skip_attempt_update')) for r in results],self.count()))
        proof,status=self.db.execute('SELECT w2_send_proof,status FROM baganriki_ai_attempts WHERE token=?',(token,)).fetchone()
        check(proof[0]=='U' and status=='RECEIVED' and self.run_op('chain',finder,0,token)['skip_attempt_update'],'marker and replay after restart')
        self.now+=timedelta(milliseconds=1);self.settle(finder);self.now=self.limit;self.view([5],0)
        print('PASS wrong request then four sender processes, one call and received answer from '+finder+'.',flush=True)
    def fix(self,role):
        self.configure(role,'shared');other=opposite(role);hashes={}
        for r in (other,role):
            tok=self.reserve(r,0);result=self.run_op('chain',r,0,tok,receive=True,**({'break_horse':5} if r==role else {}))
            check(result['ok'],(r,result));hashes[r]=result['raw_sha256'];self.now+=timedelta(milliseconds=1);self.settle(r)
        earlier,_=self.view([5],0);grant=self.inspect(role,with_grant=True);check(grant['scope']['horses']==[5],'real scoped diagnosis')
        self.now+=timedelta(seconds=2);tok=self.reserve(role,1)
        check(self.run_op('stage',role,1,tok,grant=grant['scope'],diagnostics=grant['diagnostics'])['ok'] and self.run_op('allow',role,1,tok)['ok'],'correction staged')
        check(self.request('when_ready')['code']=='WAITING','wait for correction')
        no=self.run_op('dispatch',role,1,tok,tamper=True);check(no['code']=='OUTBOUND_MISMATCH' and self.count()==2,'bad correction')
        good=self.run_op('dispatch',role,1,tok,receive=True);check(good['ok'],'authorized correction');hashes[role]=good['raw_sha256']
        self.now+=timedelta(milliseconds=1);check(self.inspect(role)['code']=='FIX_APPLIED','actual accepted correction');self.settle(role)
        latest,h=self.view([5],5);check(h['public_proof_hash']==hashes and latest['publication_id']!=earlier['publication_id'] and self.count()==3,'genuine two sources only')
        print('PASS corrected '+role+', exact original/repair hashes, 12th favourite +5 and historical publication kept.',flush=True)
def main(php,controller):
    with TemporaryDirectory() as tmp:
        t=Trial(php,Path(controller).resolve(),Path(tmp))
        t.db.execute('ALTER TABLE baganriki_ai_attempts ADD COLUMN w2_send_proof CHAR(65)')
        t.db.execute('CREATE TABLE sender18_calls(role TEXT,sys TEXT,user TEXT)')
        t.db.execute('CREATE TABLE sender18_receipts(token TEXT PRIMARY KEY,record TEXT)')
        try:
            for role in ROLES:
                t.single(role);t.overlap(role);t.fix(role)
                for reason in ('DEADLINE_PASSED','ALL_CANCELLED'):t.denied(role,reason)
        finally:t.db.close()
    print('PASS R15.18 six persistent send/publication paths and four permission-reason checks.',flush=True)
if __name__=='__main__':main(*sys.argv[1:])
