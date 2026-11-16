"""W2 配布物、実レースのW1検証、合成のW2入出力を検査する。"""
from copy import deepcopy
from hashlib import sha256
import argparse
import json
import re
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
from zipfile import ZipFile
from jsonschema import Draft202012Validator,FormatChecker
from verify_market import run as verify_markets
from verify_schema_parity import run as verify_schema_parity
from verify_numeric import run as verify_numeric
from verify_r4_reference import run as verify_r4
from verify_r9_dispatch import run as verify_dispatch
from verify_r10_frames import run as verify_frames
from verify_r10_adapter import run as verify_adapter
from verify_r12_adapter import run as verify_score_adapter
from verify_r11_schema_identity import release_guard as schema_identity_guard, run as verify_schema_identity
from verify_r8_market import run as verify_r8_market
from verify_r7_storage import run as verify_storage
from verify_r7_histories import run as verify_histories
from verify_r6_packets import run as verify_packets, archived_join

ROOT=Path(__file__).resolve().parents[1]
V='baganriki-v041-r12-2-w2-e35-r15-20261104'
OLD_SHA='f77f7c27d3801a2157107c404fd3a3c3c1aedb86266d34e3abfea55516c4f8cf'

def ensure(condition,reason):
    if not condition:
        raise ValueError(reason)

def read(p):
    return json.loads(p.read_text(encoding='utf-8'))

def reference_from_submission(source,dest):
    data=source.read_bytes()
    ensure(sha256(data).hexdigest()=='1e8f0fbfe732fd24639c6b1f40c40b384e5956accd969a5aea3ff838159ed7ae','10/23 submission SHA-256 mismatch')
    app=data.decode('utf-8')
    def class_text(name):
        start=re.search(r'^final class '+name+r'\b',app,re.MULTILINE).start()
        return app[start:app.index('\n}\n',start)+3]
    start=app.index('    public const V04_QUOTA_BY_TARGET = [')
    constant=app[start:app.index('    ];',start)+6]
    begin=app.index('    public static function invalidBandsOf(')
    constant+='\n'+app[begin:app.index('\n    }\n',begin)+6]
    header='<?php\nnamespace App\\Http\\Controllers\\Api;\n'
    # Include the submitted namespace polyfill when testing the actual PHP 8.0 deployment version.
    compat=app.index("if (!function_exists(__NAMESPACE__")
    header+=app[compat:app.index('\n}\n',compat)+3]+'\n'
    # Laravel's pure comparator reads only this documented feature flag. Keep its project default.
    header+="function env($key,$default=null) { if ($key!=='BAGANRIKI_R122_FIX_PARTIAL') throw new \\LogicException('unexpected test setting'); return $default; }\n"
    classes=['BaganrikiPrompt','BaganrikiDecimal','BaganrikiEmptyObject','BaganrikiJson','BaganrikiResponse','BaganrikiPublicView','BaganrikiAllocation','BaganrikiRanking']
    dest.write_text(header+'final class AiController {\n'+constant+'\n}\n'+'\n'.join(class_text(n) for n in classes),encoding='utf-8')

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php',default='php')
    parser.add_argument('--codec-class',type=Path,help='任意。既存の信頼済みBaganrikiPromptクラスを読み込むPHPファイル')
    parser.add_argument('--backend-class',type=Path,help='任意。mbstring と提出済みの Json/Prompt/Ranking/Allocation・配分表を使う純粋試験')
    parser.add_argument('--submitted-php',type=Path,help='10月23日に提出したAiController.phpそのもの。完全なSHA-256一致時だけ検査用の純粋クラスを一時抽出')
    parser.add_argument('--stress-seed',type=int,default=20261101,help='独立した市場合成データの再現用シード')
    args=parser.parse_args()
    if args.submitted_php and (args.codec_class or args.backend_class):
        parser.error('--submitted-php is an alternative to the two bootstrap paths')
    checks=ROOT/'validation';cfg=read(ROOT/'config/w2_contract.json')
    schema_identity_guard()
    ensure(cfg['prompt_version']==V and cfg['expected_score_policy']['enabled_for_W2_selection'] is True and
           cfg['buy_recommended'] is False and cfg['initial_learning_stage']=='COLLECT_ONLY','policy')
    guide=next(ROOT.glob('02_*.txt')).read_text()
    ensure(('対象ID　'+cfg['prompt_version']+'\n') in guide and ('schema_version　'+cfg['schema_version']+'\n') in guide,'engineer guide version')
    common=(ROOT/'prompts/common_prompt.txt').read_bytes()
    for role in ('1st','2nd'):
        t=(ROOT/f'prompts/prompt_{role}.txt').read_bytes()
        ensure(t.endswith(common) and t.count(common)==1 and not t.startswith(b'\xef\xbb\xbf'),'role assembly '+role)
        text=t.decode('utf-8')
        for s in [V,'E35_BAND_BOUNDARY_V1','p0(k,r)=(B(k,r)+1)/(M+2)','E(k,i)=100×g(p_raw(k,i))',
                  'W=Σw','COMPARISON_READY','expected_v01','selection_ranks','expected_reviews',
                  'E5[j]>E5[i]','E3[j]>E3[i]','PENDING_VALIDATION','role_quota','7頭立ての目標は4頭','総合点・V小計・H小計を再計算','SOURCE_TABLE_V1_ADDENDUM_V2']:
            ensure(s in text,'missing '+role+': '+s)
        ensure('baganriki-v041-r12-2-w1-20261101' not in text and '京都6R' not in text,'old identity or outcome in model system')
    with TemporaryDirectory() as d:
        tmp=Path(d)
        if args.submitted_php:
            args.codec_class=args.backend_class=tmp/'reference.php'
            reference_from_submission(args.submitted_php,args.codec_class)
        previous=checks/'previous_W1.zip'
        ensure(sha256(previous.read_bytes()).hexdigest()==OLD_SHA,'pinned prior package')
        with ZipFile(previous) as z:
            ensure(all(not Path(n).is_absolute() and '..' not in Path(n).parts for n in z.namelist()),'zip entries')
            z.extractall(tmp/'w1')
        r=subprocess.run([sys.executable,'-B',str(tmp/'w1/validation/verify.py')],check=True,capture_output=True,text=True)
        ensure('8 races, 123 starters' in r.stdout,'weekend W1 replay')
        real=verify_markets(args.php,tmp/'w1',args.stress_seed)
        archived=checks/'週末8レース_R3市場判定.json'
        ensure(real==read(archived),'R3 archived market report drift')
        expected=read(tmp/'w1/validation/expected.json')['races']
        ensure(sum(x[1] for x in expected.values())==75 and sum(len(x[2]) for x in expected.values())==11,'observed outsider counts')
        print('PASS previous W1 fixtures: 8 races / 123 runners / 75 old ineligible outsiders / 11 reached top 3; no W2 performance inference')
        origparams=read(ROOT/'formula/parameters.json')
        ensure(origparams['implementation_version']==cfg['expected_score_policy']['implementation_version'] and
               origparams['formula_version']==cfg['expected_score_policy']['formula_version'] and origparams['lambda']==20 and
               origparams['lookback_days']==730 and origparams['max_neighbours']==200,'equation parameters')
        formula=subprocess.run([args.php,'-n',str(ROOT/'formula/verify.php')],cwd=ROOT/'formula',check=True,capture_output=True,text=True)
        ensure('56 passed; 0 failed' in formula.stdout,'original PHP tests')
        print('PASS original formula PHP tests: 56')
        verify_numeric(args.php,args.stress_seed)
        verify_r4(args.php,args.stress_seed)
        verify_packets(args.php,args.stress_seed)
        verify_histories(args.php,args.stress_seed)
        archived_join(args.php,tmp/'w1')
        Draft202012Validator.check_schema(read(ROOT/"schemas/recovery_schema.json"))
        for php in (ROOT/'php').glob('*.php'):
            subprocess.run([args.php,'-n','-l',str(php)],check=True,capture_output=True)
        examples=tmp/'examples'
        prog=subprocess.run([args.php,'-n',str(checks/'verify_w2.php'),'--examples',str(examples)],check=True,capture_output=True,text=True)
        for line in prog.stdout.splitlines():
            if line.startswith(('R1 regression checks:', 'R2 regression checks:', 'R3 regression checks:', 'R6 regression checks:', 'R7 regression checks:', 'R8 regression checks:', 'R9 regression checks:', 'W2 tests passed:')):
                print(line)
        with ZipFile(checks/'synthetic_W2_examples.zip') as shipped:
            ensure(set(shipped.namelist())=={p.name for p in examples.glob('*.json')},'shipped fixture names')
            for p in examples.glob('*.json'):
                ensure(shipped.read(p.name)==p.read_bytes(),'shipped fixture drift '+p.name)
        verify_r8_market(args.php,examples,args.stress_seed)
        validators={}
        for kind in ['input','output']:
            sc=read(ROOT/f'schemas/{kind}_schema.json')
            Draft202012Validator.check_schema(sc);validators[kind]=Draft202012Validator(sc,format_checker=FormatChecker())
            ensure(len(sc['required'])==(36 if kind=='input' else 23),'contract key count')
        for role in ['1st','2nd']:
            for kind in ['input','output']:
                payload=read(examples/f'{role}_{kind}.json')
                validators[kind].validate(payload)
                ensure(payload['prompt_version']==V,'wrong fixture version')
        a=read(examples/'1st_input.json'); b=read(examples/'1st_output.json')
        cases=[]
        for field in ['expected_v01','coverage','input_indices']:
            obj=deepcopy(a);del obj[field];cases.append(('input',obj))
        for field in ['selection_ranks','expected_reviews']:
            obj=deepcopy(b);del obj[field];cases.append(('output',obj))
        obj=deepcopy(a);obj['expected_v01']['rows'][0]['M']=99;cases.append(('input',obj))
        obj=deepcopy(a);obj['expected_v01']['rows'][0]['E3']='9.2';cases.append(('input',obj))
        obj=deepcopy(a);obj['expected_v01']['current_sha256']='invalid';cases.append(('input',obj))
        obj=deepcopy(b);obj['expected_reviews']['B']['decision']='NO_BOUNDARY';cases.append(('output',obj))
        obj=deepcopy(a);obj['prompt_version']='baganriki-v041-r12-2-w1-20261101';cases.append(('input',obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r1-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r2-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r3-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r4-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r5-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r6-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r7-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r8-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r9-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r10-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r11-20261101';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r12-20261104';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r13-20261104';cases.append((kind,obj))
        for kind,fixture in [('input',a),('output',b)]:
            obj=deepcopy(fixture);obj['prompt_version']='baganriki-v041-r12-2-w2-e35-r14-20261104';cases.append((kind,obj))
        cross=deepcopy(b);cross['mode']='FIRM';cross['allocation_mode']='FIRM'
        validators['output'].validate(cross)  # This is a valid shape; verifyReply must reject it against MIXED input.
        for kind,obj in cases:
            ensure(not validators[kind].is_valid(obj),'schema accepted negative '+kind)
        print(f'PASS JSON Schema Draft 2020-12: 4 full role documents and {len(cases)} rejecting cases')
        if args.codec_class:
            subprocess.run([args.php,'-n',str(checks/'verify_source_table.php'),str(args.codec_class.resolve()),str(examples)],check=True)
        if args.backend_class:
            verify_schema_parity(args.php,args.backend_class.resolve(),examples)
            verify_schema_identity(args.php,args.backend_class.resolve(),examples)
            subprocess.run([args.php,str(checks/'verify_backend.php'),str(args.backend_class.resolve()),str(examples)],check=True)
            verify_dispatch(args.php,args.backend_class.resolve(),examples,args.stress_seed)
            verify_frames(args.php,args.backend_class.resolve(),args.stress_seed)
            if args.submitted_php:
                verify_adapter(args.php,args.backend_class.resolve(),args.submitted_php.resolve())
                verify_score_adapter(args.php,args.backend_class.resolve(),args.submitted_php.resolve(),examples)
            verify_storage(args.php,args.backend_class.resolve(),examples)
    print('PASS W2 release checks')
if __name__=='__main__':main()
