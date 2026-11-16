"""R15.21 regression: exact JSON types and independently repaired display fields through SQL/Queue/GET."""
from pathlib import Path
from tempfile import TemporaryDirectory
from datetime import timedelta
from itertools import permutations
from hashlib import sha256
import argparse,json,shutil,subprocess
from verify_r1520 import Current,doc,pack,displayed
from verify_release import reference_from_submission

ROOT=Path(__file__).resolve().parents[1]
VALUES=dict(wave_level=3,lower_entry=2,big_gap_entry=2)

def run(php,submitted,trial_class=Current):
    cases=[('normal',None,None)]
    for key in VALUES:
        cases.extend((kind,key,None) for kind in ['object_refs','object_empty_refs','integer_decimal','fraction'])
    cases += [('partial',a,b) for a,b in permutations(VALUES,2)]
    cases += [(kind,'wave_level','lower_entry') for kind in
              ['partial_horse','changed_valid','immutable_unknown','incomplete','foreign','opposite_role','hash_mismatch']]
    cases += [('repair_object','lower_entry',None)]
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';shutil.copy2(submitted,ctrl)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        t=trial_class(php,ctrl,tmp);t.cache={};reports=[]
        try:
            for kind,key,remaining in cases:
                t.configure('1st');original=t.role_rows['1st']['original'];h,p,e=doc(original)
                expected=dict(VALUES);status=dict.fromkeys(VALUES,'VALID');fixed=False;correction=False
                if kind in ['object_refs','repair_object']:
                    p['race_metrics'][key]['source_refs']={str(i):ref for i,ref in enumerate(p['race_metrics'][key]['source_refs'])}
                    expected[key]=None;status[key]='INVALID'
                if kind=='object_empty_refs':
                    p['race_metrics'][key]={'value':None,'source_refs':{},'reason':'資料不足'}
                    expected[key]=None;status[key]='INVALID'
                if kind in ['integer_decimal','fraction']:p['race_metrics'][key]['value']='NUMERIC_TOKEN'
                if kind=='fraction':expected[key]=None;status[key]='INVALID'
                if kind in ['partial','partial_horse','changed_valid','immutable_unknown','incomplete','foreign','opposite_role','hash_mismatch']:
                    p['race_metrics'][key]['value']=0;p['race_metrics'][remaining]['value']=0
                    expected[key]=expected[remaining]=None;status[key]=status[remaining]='INVALID'
                    if kind=='immutable_unknown':
                        p['race_metrics'][key]={'value':None,'source_refs':[],'reason':'資料不足'};status[key]='UNKNOWN'
                    if kind=='partial_horse':next(x for x in p['horses'] if x['horse_no']==5)['evidence'][0]['facts'][0]['value']=999
                raw=pack(h,p,e)
                if kind in ['integer_decimal','fraction']:
                    token=str(VALUES[key])+('.000000000000000000000' if kind=='integer_decimal' else '.000000000000000000001')
                    raw=raw.replace('"NUMERIC_TOKEN"',token)
                t.role_rows['1st']['original']=raw
                for role in ['1st','2nd']:t.send(role)
                t.now=t.start+timedelta(seconds=3,microseconds=200)
                for i,role in enumerate(['1st','2nd']):assert t.receive(role,at=t.start+timedelta(seconds=3,microseconds=i*50))['ok']
                initial=t.inspect('1st',with_grant=True);after=None
                if kind in ['partial','partial_horse','changed_valid','immutable_unknown','incomplete','foreign','opposite_role','hash_mismatch','repair_object']:
                    correction=True;t.now=t.start+timedelta(seconds=6);h,f,e=doc(original)
                    if remaining:f['race_metrics'][remaining]['value']=0
                    if kind=='changed_valid':f['race_metrics']['big_gap_entry']['value']=1
                    if kind=='foreign':f['race_id']='20261017-4-05-07-12'
                    if kind=='opposite_role':f['ai_role']='2nd'
                    fixed_raw=pack(h,f,e);t.correction('1st',fixed_raw,complete=kind!='incomplete')
                    if kind=='hash_mismatch':t.db.execute("UPDATE baganriki_ai_attempts SET response_hash=? WHERE role='1st' AND attempt_no=1",('a'*64,))
                    after=t.inspect('1st',with_grant=True)
                    if kind in ['partial','partial_horse','repair_object']:
                        fixed=True;expected[key]=VALUES[key];status[key]='VALID'
                        assert after['code']==('FIX_APPLIED' if kind=='repair_object' else 'FIX_PARTIAL'),(kind,after)
                for role in ['1st','2nd']:t.settle(role)
                t.now=t.limit;pub=t.request('when_ready');view=t.count_get()
                assert pub['code']=='PUBLISHED',(kind,key,pub)
                saved=json.loads(t.scalar('SELECT evidence FROM baganriki_publications WHERE publication_id=?',pub['publication_id']))['race_metrics']
                assert view['race_metrics']==displayed(expected) and saved['values']==expected,(kind,key,'values',view,saved)
                assert saved['status']==status,(kind,key,'status',saved)
                assert view['publication']['race_metrics']==view['race_metrics'] and 'race_metrics' not in view['publication']['evidence']
                expected_hash=sha256((fixed_raw if fixed else raw).encode()).hexdigest()
                assert saved['response_hash']==expected_hash,(kind,key,'wrong receipt',saved)
                nums=[x['horse_no'] for x in pub['candidates']]
                assert nums==[16,15,14,10,5],(kind,key,'lost valid longshot',nums)
                assert nums==[x['horse_no'] for x in view['publication']['candidates']]
                assert not view['buy_recommended']
                committed=t.committed();again=t.request('when_ready');second=t.count_get()
                assert again['code']=='ALREADY' and again['publication_id']==pub['publication_id'] and committed==t.committed()
                assert second['race_metrics']==view['race_metrics'] and again['race_metrics']==saved
                calls=t.scalar('SELECT count(*) FROM provider_stub')
                assert calls==(3 if correction else 2),(kind,key,'extra provider call',calls)
                report=dict(case=kind,key=key,remaining=remaining,correction=after['code'] if after else None,values=expected,status=status,candidates=nums,calls=calls)
                reports.append(report);print(json.dumps(report,ensure_ascii=False),flush=True)
            t.configure('1st')
            bootstrap=tmp/'reference_classes.php';reference_from_submission(submitted,bootstrap)
            subprocess.run([php,str(ROOT/'validation/r1521_metric_recovery.php'),str(bootstrap),str(tmp)],check=True)
        finally:t.db.close()
    print(f'PASS {len(reports)} R15.21 real-controller SQLite/Queue/publication/GET regressions under {trial_class.TEXT_REVISION}; synthetic replies only',flush=True)

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',required=True);p.add_argument('--submitted-php',type=Path,required=True)
    a=p.parse_args();run(a.php,a.submitted_php)
