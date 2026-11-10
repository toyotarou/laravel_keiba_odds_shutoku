"""V2 exact transport regression and controller/SQLite/Queue/readback paths for revision 07."""
from pathlib import Path
from tempfile import TemporaryDirectory
from hashlib import sha256
import argparse,json,shutil,subprocess,sys
from verify_release import reference_from_submission
from verify_r1520 import Current,Sender,displayed
from verify_r1521 import run as verify_metric_paths

ROOT=Path(__file__).resolve().parents[1]
REVISION='COMPACT_TEXT_20261109_07'
class CurrentV2(Current):
    TEXT_REVISION=REVISION
class SenderV2(Sender):
    TEXT_REVISION=REVISION

def guard_policy():
    cfg=json.loads((ROOT/'config/w2_contract.json').read_text())
    versions=json.loads((ROOT/'schemas/input_schema.json').read_text())['properties']['prompt_text_revision']['enum']
    assert cfg['active_text_revision']==REVISION
    assert set(cfg['transport_policy'])==set(versions)
    assert cfg['transport_policy'][REVISION]=={role:dict(initial='SOURCE_TABLE_V2',required='SOURCE_TABLE_V2') for role in ['1st','2nd']}
    assert cfg['validator_version']==cfg['validation_contract_revision']=='w2-r15.24'
    for revision,hashes in [(cfg['active_text_revision'],cfg['system_file_sha256']),*cfg['archived_text_revisions'].items()]:
        folder=ROOT/'prompts' if revision==cfg['active_text_revision'] else ROOT/'prompts/versions'/revision
        for name,digest in hashes.items():assert sha256((folder/name).read_bytes()).hexdigest()==digest,(revision,name)
    print('PASS R15.24 active/archive policy and system checksum coverage',flush=True)

def guard_controller_paths(php,submitted):
    manifest=json.loads((ROOT/'integration/controller_bases.json').read_text())
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';shutil.copy2(ROOT/'integration/patched/AiController.php',ctrl)
        sources={sha256(Path(submitted).read_bytes()).hexdigest():Path(submitted).resolve()}
        for patch in ['r1522_to_r1523_controller.patch','r1520_to_r1521_controller.patch','r1519_to_r1520_controller.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-R','-p1','-i',str(ROOT/'integration'/patch)],cwd=tmp,check=True)
            digest=sha256(ctrl.read_bytes()).hexdigest();path=tmp/(digest+'.php');shutil.copy2(ctrl,path);sources[digest]=path
        assert set(sources)==set(manifest['accepted_bases'])
        for index,(digest,source) in enumerate(sources.items()):
            output=tmp/f'result_{index}.php'
            subprocess.run([sys.executable,str(ROOT/'integration/apply_checked.py'),'--source',str(source),'--destination',str(output),'--php',php],check=True,capture_output=True)
            assert sha256(output.read_bytes()).hexdigest()==manifest['result_sha256'],digest
    print('PASS 4 recognized controller bases produce identical R15.24 bytes through guarded patches',flush=True)

def run(php,submitted,quick=False,sender_only=False):
    guard_policy()
    guard_controller_paths(php,submitted)
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';shutil.copy2(submitted,ctrl)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        manifest=json.loads((ROOT/'integration/controller_bases.json').read_text())
        assert sha256(ctrl.read_bytes()).hexdigest()==manifest['result_sha256']
        print('PASS R15.24 tested controller SHA-256 '+manifest['result_sha256'],flush=True)
        bootstrap=tmp/'reference_classes.php';reference_from_submission(submitted,bootstrap)
        if not sender_only:
            t=CurrentV2(php,ctrl,tmp);t.cache={}
            try:
                t.configure('1st')
                subprocess.run([php,str(ROOT/'validation/r1524_probe.php'),str(bootstrap),str(tmp)],check=True)
            finally:t.db.close()
        if quick:return
        other=tmp/'sender';other.mkdir();t=SenderV2(php,ctrl,other);t.cache={}
        t.db.execute('ALTER TABLE baganriki_ai_attempts ADD COLUMN w2_send_proof CHAR(65)')
        t.db.execute('CREATE TABLE sender18_calls(role TEXT,sys TEXT,user TEXT)')
        t.db.execute('CREATE TABLE sender18_receipts(token TEXT PRIMARY KEY,record TEXT)')
        try:
            for role in ['1st','2nd']:
                t.fix(role)
                receipts=t.db.execute('SELECT record FROM sender18_receipts').fetchall()
                assert len(receipts)==3
                for raw, in receipts:
                    record=json.loads(raw);body=json.loads(record['user_json']);inp=body.get('input',body)
                    assert record['format']=='SOURCE_TABLE_V2' and inp['prompt_text_revision']==REVISION
                    assert inp['source_table']['encoding']=='SOURCE_TABLE_V2' and 'source_registry' not in inp
                    assert '【送信形式 SOURCE_TABLE_V2】' in record['system_text']
                assert t.get(clock=t.us(t.now))['body']['race_metrics']==displayed(dict(wave_level=3,lower_entry=2,big_gap_entry=2))
            print('PASS 2 R15.24 staged sender/repair/SQLite/GET flows; both roles V2, three calls each',flush=True)
            for role in ['1st','2nd']:
                t.configure(role);token=t.reserve(role,0)
                assert t.run_op('stage',role,0,token)['ok'] and t.run_op('allow',role,0,token)['ok']
                for encoding in ['SOURCE_TABLE_V1','UNDECLARED',None,123]:
                    rejected=t.run_op('dispatch',role,0,token,wire_encoding=encoding)
                    assert not rejected['ok'] and rejected['code']=='OUTBOUND_MISMATCH' and t.count()==0,rejected
                assert t.run_op('dispatch',role,0,token,receive=True)['ok'] and t.count()==1
            print('PASS 8 altered V2 encoding rejections and 2 original-request retries; zero wrong provider calls',flush=True)
        finally:t.db.close()
    if sender_only:return
    verify_metric_paths(php,submitted,CurrentV2)
    print('PASS R15.24 focused integration suite; no external model or deployed database',flush=True)

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',required=True);p.add_argument('--submitted-php',type=Path,required=True);g=p.add_mutually_exclusive_group();g.add_argument('--quick',action='store_true');g.add_argument('--sender-only',action='store_true')
    a=p.parse_args();run(a.php,a.submitted_php,a.quick,a.sender_only)
