"""Reproduce R15.19 defects and guards offline; no provider, network or live database."""
from pathlib import Path
from tempfile import TemporaryDirectory
from fractions import Fraction
from hashlib import sha256
import argparse,json,random,shutil,subprocess,sys
from verify_release import reference_from_submission
BASE=Path(__file__).resolve().parent;ROOT=BASE.parent

def run(php,submitted,semantic=True):
    with TemporaryDirectory() as folder:
        tmp=Path(folder);bootstrap=tmp/'bootstrap.php';reference_from_submission(submitted,bootstrap)
        ctrl=tmp/'AiController.php';shutil.copy2(submitted,ctrl)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        subprocess.run([php,str(BASE/'r1519_probe.php'),str(bootstrap),str(BASE/'r1519_fixtures'),str(tmp/'probe')],check=True)
        triples=[['23','23','23'],['30','28','23'],['30','24','20'],['32','24','18'],['20','21','19'],['30','28','29'],['1.00000000000000000002','1.00000000000000000001','1']]
        rng=random.Random(20261109)
        for _ in range(600):triples.append([f'{rng.randrange(1,99999)}.{rng.randrange(10**12):012d}' for _ in range(3)])
        expected=[]
        for row in triples:
            a,b,c=map(Fraction,row);expected.append(a>b>c>0 and (b-c)/b>(a-b)/a)
        r=subprocess.run([php,str(BASE/'r1519_decimal.php')],input=json.dumps(triples),capture_output=True,text=True,check=True)
        assert r.stdout.splitlines()==['true' if b else 'false' for b in expected]
        print('PASS 607 independent Fraction arithmetic cases',flush=True)
        dest=tmp/'next'/'W2';shutil.copytree(ROOT,dest,ignore=shutil.ignore_patterns('__pycache__'))
        cfg=json.loads((dest/'config/w2_contract.json').read_text());old=cfg['active_text_revision'];archive=dest/'prompts/versions'/old;archive.mkdir(parents=True)
        cfg['archived_text_revisions'][old]=cfg['system_file_sha256'].copy()
        for name in cfg['system_file_sha256']:
            src=dest/'prompts'/name;shutil.copy2(src,archive/name);src.write_bytes(src.read_bytes()+b'\nSynthetic revision switch for tests only.\n');cfg['system_file_sha256'][name]=sha256(src.read_bytes()).hexdigest()
        cfg['active_text_revision']='TEST_NEXT_04';(dest/'config/w2_contract.json').write_text(json.dumps(cfg,ensure_ascii=False,indent=2)+'\n')
        schema=dest/'schemas/input_schema.json';v=json.loads(schema.read_text());v['properties']['prompt_text_revision']['enum'].append('TEST_NEXT_04');schema.write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
        saved=tmp/'records.json'
        for active,op in [(ROOT,'save'),(dest,'verify')]:
            subprocess.run([php,str(BASE/'r1519_revision.php'),str(active),str(bootstrap),str(BASE/'r1519_fixtures'),op,str(saved)],check=True)
        if semantic:subprocess.run([sys.executable,'-u',str(BASE/'verify_r1519_semantics.py'),php,str(ctrl)],check=True)
        print('PASS R15.19 targeted suite; patched controller SHA-256 '+sha256(ctrl.read_bytes()).hexdigest(),flush=True)

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',required=True);p.add_argument('--submitted-php',type=Path,required=True);p.add_argument('--quick',action='store_true')
    a=p.parse_args();run(a.php,a.submitted_php,not a.quick)
