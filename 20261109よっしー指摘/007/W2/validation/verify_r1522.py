"""Revision 05 compressed transport, preserved old receipts, and independent display-field corrections."""
from pathlib import Path
from tempfile import TemporaryDirectory
import argparse,json,shutil,subprocess
from verify_release import reference_from_submission
from verify_r1520 import Current,Sender,displayed
from verify_r1521 import run as verify_metric_paths

ROOT=Path(__file__).resolve().parents[1]
REVISION='COMPACT_TEXT_20261109_05'
class CompactCurrent(Current):
    TEXT_REVISION=REVISION
class CompactSender(Sender):
    TEXT_REVISION=REVISION

def guard_policy():
    cfg=json.loads((ROOT/'config/w2_contract.json').read_text())
    versions=json.loads((ROOT/'schemas/input_schema.json').read_text())['properties']['prompt_text_revision']['enum']
    assert REVISION in [cfg['active_text_revision'],*cfg['archived_text_revisions']]
    assert set(cfg['transport_policy'])==set(versions)
    assert cfg['transport_policy'][REVISION]=={role:dict(initial='SOURCE_TABLE_V1',required='SOURCE_TABLE_V1') for role in ['1st','2nd']}
    assert cfg['transport_policy']['COMPACT_TEXT_20261109_04']=={'1st':dict(initial='FULL',required='FULL'),'2nd':dict(initial='SOURCE_TABLE_V1',required=None)}
    for revision in versions:
        assert set(cfg['transport_policy'][revision])=={'1st','2nd'}
    print('PASS R15.22 declared schema/revision/transport policy coverage',flush=True)

def run(php,submitted,quick=False):
    guard_policy()
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';shutil.copy2(submitted,ctrl)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        bootstrap=tmp/'reference_classes.php';reference_from_submission(submitted,bootstrap)
        t=CompactCurrent(php,ctrl,tmp);t.cache={}
        try:
            t.configure('1st')
            subprocess.run([php,str(ROOT/'validation/r1522_probe.php'),str(bootstrap),str(tmp)],check=True)
        finally:t.db.close()
        if quick:return
        other=tmp/'sender';other.mkdir();t=CompactSender(php,ctrl,other);t.cache={}
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
                    assert record['format']=='SOURCE_TABLE_V1' and inp['prompt_text_revision']==REVISION
                    assert 'source_table' in inp and 'source_registry' not in inp
                    assert '【送信形式 SOURCE_TABLE_V1】' in record['system_text']
                assert t.get(clock=t.us(t.now))['body']['race_metrics']==displayed(dict(wave_level=3,lower_entry=2,big_gap_entry=2))
            print('PASS 2 R15.22 actual staged sender/repair/SQLite/GET flows; both roles compact, three calls each',flush=True)
        finally:t.db.close()
    verify_metric_paths(php,submitted,CompactCurrent)
    print('PASS R15.22 focused integration suite; no external model or deployed database',flush=True)

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',required=True);p.add_argument('--submitted-php',type=Path,required=True);p.add_argument('--quick',action='store_true')
    a=p.parse_args();run(a.php,a.submitted_php,a.quick)
