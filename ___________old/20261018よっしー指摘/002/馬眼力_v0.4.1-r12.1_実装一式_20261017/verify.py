"""Run from any working directory. Exit nonzero on any failed gate."""
import subprocess,sys,json
from pathlib import Path
root=Path(__file__).resolve().parent;results=[]
for script in ('test_reference.py','test_contract.py','test_engine.py','test_audit_regressions.py','test_r7_integration.py','test_r8_regressions.py','test_journal.py','test_r10_regressions.py','test_r11_regressions.py','test_r12_regressions.py','test_quota_revision.py','test_malformed_responses.py','schema_standard_check.py'):
 p=subprocess.run([sys.executable,str(root/script)],cwd=root,capture_output=True,text=True)
 (root/(script+'.log')).write_text(p.stdout+p.stderr,encoding='utf-8')
 results.append(dict(script=script,exit_code=p.returncode))
 print(script,'PASSED' if p.returncode==0 else 'FAILED')
summary=dict(all_passed=all(x['exit_code']==0 for x in results),checks=results,production_deployed=False,real_model_calls=False)
(root/'verification_results.json').write_text(json.dumps(summary,indent=2))
sys.exit(0 if summary['all_passed'] else 1)
