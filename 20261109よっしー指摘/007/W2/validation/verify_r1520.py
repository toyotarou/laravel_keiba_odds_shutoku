"""R15.20: real PHP controller + isolated SQLite/Queue/GET, with synthetic provider replies."""
from pathlib import Path
from tempfile import TemporaryDirectory
from datetime import timedelta
from copy import deepcopy
import argparse,json,os,subprocess,sys,shutil
from verify_release import reference_from_submission
from verify_r1518_candidates import Test,doc,pack
from verify_r1518_persistent import Trial as Persistent
BASE=Path(__file__).resolve().parent;ROOT=BASE.parent

def metric(value,refs,reason='凍結した同馬のオッズ時系列を比較'):
    return dict(value=value,source_refs=refs,reason=reason)
def metrics():
    return dict(wave_level=metric(3,[f'h{n}_win_odds_{s}' for n in [16,15] for s in [9,6]]),
        lower_entry=metric(2,['h10_place_min_9','h10_place_min_6']),
        big_gap_entry=metric(2,['h5_place_min_9','h5_place_min_6']))

def displayed(values):
    return {label:values[key] for key,label in [('wave_level','波乱度'),('lower_entry','下位進入度'),('big_gap_entry','大穴進入度')]}

class Current(Test):
    TEXT_REVISION='COMPACT_TEXT_20261109_04'
    def count_get(self):
        before=self.committed();result=self.get(clock=self.us(self.now));assert "body" in result,result
        body=result["body"];assert not body["buy_recommended"] and before==self.committed()
        return body
    def bundle(self,supported,pattern='sparse',race='20261017-4-05-07-11'):
        key=(supported,pattern,race)
        if key not in self.cache:
            env=dict(os.environ,W2_TEST_TEXT_REVISION=self.TEXT_REVISION)
            subprocess.run([self.php,str(BASE/'r1518_longshot_fixture.php'),str(self.directory),'MIXED',supported,pattern,race],env=env,check=True,capture_output=True)
            p=self.directory/'1st_output.json';o=json.loads(p.read_text());o['race_metrics']=metrics();p.write_text(json.dumps(o,ensure_ascii=False,separators=(',',':')))
            out=self.replay('export');assert out['ok'],out
            self.cache[key]=out['data']
        return deepcopy(self.cache[key])

class Sender(Current,Persistent):
    def configure(self,finder,pattern='sparse'):
        super().configure(finder,pattern)
        self.now=self.start+timedelta(seconds=4)
        for table in ['sender18_calls','sender18_receipts']:self.db.execute('DELETE FROM '+table)

def run(php,submitted,quick=False):
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';shutil.copy2(submitted,ctrl)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        bootstrap=tmp/'reference_classes.php';reference_from_submission(submitted,bootstrap)
        t=Current(php,ctrl,tmp);t.cache={}
        try:
            t.configure('1st')
            subprocess.run([php,str(BASE/'r1520_probe.php'),str(bootstrap),str(tmp)],check=True)
            if quick:return
            cases=['normal','missing','range','type','reference','unknown','second_field','fix_metric','fix_outside','first_absent','first_unreadable']
            report=[]
            for case in cases:
                t.configure('1st');raw=t.role_rows['1st']['original'];head,p,tail=doc(raw);expected=dict(wave_level=3,lower_entry=2,big_gap_entry=2)
                if case=='missing':del p['race_metrics'];expected=dict.fromkeys(expected)
                if case=='range':p['race_metrics']['lower_entry']['value']=6;expected['lower_entry']=None
                if case=='type':p['race_metrics']['lower_entry']['value']='2';expected['lower_entry']=None
                if case=='reference':p['race_metrics']['lower_entry']['source_refs']=['absent'];expected['lower_entry']=None
                if case=='unknown':p['race_metrics']['lower_entry']=metric(None,[],'資料不足');expected['lower_entry']=None
                if case=='fix_metric':p['race_metrics']['lower_entry']['value']=0
                if case=='fix_outside':
                    next(h for h in p['horses'] if h['horse_no']==5)['evidence'][0]['facts'][0]['value']=999
                if case=='second_field':
                    h2,p2,e2=doc(t.role_rows['2nd']['original']);p2['race_metrics']=metrics();t.role_rows['2nd']['original']=pack(h2,p2,e2)
                t.role_rows['1st']['original']=pack(head,p,tail)
                if case in ['first_absent','first_unreadable']:expected=dict.fromkeys(expected)
                if case=='first_unreadable':t.role_rows['1st']['original']='broken JSON'
                for role in ['1st','2nd']:t.send(role)
                t.now=t.start+timedelta(seconds=3,microseconds=200)
                for i,role in enumerate(['1st','2nd']):
                    if case=='first_absent' and role=='1st':continue
                    assert t.receive(role,at=t.start+timedelta(seconds=3,microseconds=i*50))['ok']
                before=t.inspect('1st',with_grant=True) if case not in ['first_absent','first_unreadable'] else None
                if case in ['missing','range','type','reference','fix_metric']:
                    codes=[r['code'] for r in before['diagnostics']['errors']];assert any(c.startswith('W20_METRIC_') for c in codes),(case,before)
                after=None
                if case in ['fix_metric','fix_outside']:
                    t.now=t.start+timedelta(seconds=6);h,f,e=doc(raw)
                    if case=='fix_outside':f['race_metrics']['wave_level']['value']=4
                    t.correction('1st',pack(h,f,e));after=t.inspect('1st',with_grant=True)
                    if case=='fix_metric':assert after['code']=='FIX_APPLIED',(case,after)
                    else:assert after['code']!='FIX_APPLIED',(case,after)
                for role in ['1st','2nd']:
                    if case!='first_absent' or role!='1st':t.settle(role)
                t.now=t.limit;pub=t.request('when_ready');view=t.count_get()
                assert pub['code']=='PUBLISHED',(case,pub)
                assert view['state']=='PUBLISHED' and view['race_metrics']==displayed(expected),(case,view)
                assert view['publication']['race_metrics']==displayed(expected) and 'race_metrics' not in view['publication']['evidence'],(case,view)
                saved=json.loads(t.scalar('SELECT evidence FROM baganriki_publications WHERE publication_id=?',pub['publication_id']))
                assert saved['race_metrics']['values']==expected,(case,saved)
                nums=[r['horse_no'] for r in pub['candidates']]
                assert nums==[r['horse_no'] for r in view['publication']['candidates']]
                if case not in ['first_absent','first_unreadable','fix_outside']:assert nums==[16,15,14,10,5],(case,nums)
                count=t.committed();replay=t.request('when_ready');assert replay['code']=='ALREADY' and replay['race_metrics']==saved['race_metrics'] and t.committed()==count,(case,replay)
                if case=='normal':
                    evidence=t.scalar('SELECT evidence FROM baganriki_publications WHERE publication_id=?',pub['publication_id'])
                    corrupt=deepcopy(saved);corrupt['race_metrics']['values']['wave_level']=4
                    corrupt['race_metrics']['assessments']['wave_level']['value']=4
                    t.db.execute('UPDATE baganriki_publications SET evidence=? WHERE publication_id=?',(json.dumps(corrupt),pub['publication_id']))
                    held=t.count_get();assert held['state']=='UNAVAILABLE' and held.get('race_metrics') is None and held['publication'] is None,held
                    t.db.execute('UPDATE baganriki_publications SET evidence=? WHERE publication_id=?',(evidence,pub['publication_id']))
                    assert t.count_get()['race_metrics']==displayed(expected)
                    t.withdraw(t.nums);cancelled=t.count_get();assert cancelled['state']=='CANCELLED' and cancelled.get('race_metrics') is None and cancelled['publication']['race_metrics'] is None,cancelled
                row=dict(case=case,values=expected,candidates=nums,correction=after['code'] if after else None)
                report.append(row);print(json.dumps(row,ensure_ascii=False),flush=True)
            print(f'PASS {len(report)} R15.20 SQLite/Queue/publication/GET cases; no external provider or deployed DB',flush=True)
        finally:t.db.close()
        if not quick:
            other=tmp/'sender';other.mkdir();t=Sender(php,ctrl,other);t.cache={}
            t.db.execute('ALTER TABLE baganriki_ai_attempts ADD COLUMN w2_send_proof CHAR(65)')
            t.db.execute('CREATE TABLE sender18_calls(role TEXT,sys TEXT,user TEXT)')
            t.db.execute('CREATE TABLE sender18_receipts(token TEXT PRIMARY KEY,record TEXT)')
            try:
                for role in ['1st','2nd']:
                    t.fix(role)
                    # The stored outbound proof is the actual provider-adapter request.
                    rows=t.db.execute('SELECT record FROM sender18_receipts').fetchall()
                    for raw_record, in rows:
                        record=json.loads(raw_record);body=json.loads(record['user_json']);first=body.get('input',body)
                        is_first=first['ai_role']=='1st'
                        assert record['format']==('FULL' if is_first else 'SOURCE_TABLE_V1'),record['format']
                        assert ('source_registry' in first)==is_first
                    assert t.get(clock=t.us(t.now))['body']['race_metrics']==displayed(dict(wave_level=3,lower_entry=2,big_gap_entry=2))
                print('PASS 2 R15.20 actual sender/repair/SQL/GET flows: 1st FULL, 2nd SOURCE_TABLE_V1, three calls each',flush=True)
            finally:t.db.close()

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',required=True);p.add_argument('--submitted-php',type=Path,required=True);p.add_argument('--quick',action='store_true')
    a=p.parse_args();run(a.php,a.submitted_php,a.quick)
