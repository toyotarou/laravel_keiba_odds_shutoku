"""Independent UTF-8 system/user messages and exact correction-budget boundaries for both roles and formats."""
from hashlib import sha256
from pathlib import Path
import json
import random
import subprocess


def encoded(obj):
    return json.dumps(obj,ensure_ascii=False,separators=(',',':')).replace('\u2028','\\u2028').replace('\u2029','\\u2029')


def run(php,bootstrap,examples,seed):
    folder=Path(__file__).resolve().parents[1]/'prompts'
    digest=lambda b:sha256(b).hexdigest()
    rng=random.Random(seed)
    count=0
    with subprocess.Popen([php,str(Path(__file__).with_name('r9_wire_probe.php')),str(bootstrap),str(examples)],
                          stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True) as worker:
        def ask(q):
            worker.stdin.write(json.dumps(q,separators=(',',':'))+'\n');worker.stdin.flush()
            return json.loads(worker.stdout.readline())
        for role in ('1st','2nd'):
            original=(examples/f'{role}_input.json').read_bytes();meta=json.loads(original)
            texts=folder/'versions'/meta['prompt_text_revision']
            first='<<<BAGANRIKI_V041_R8_BEGIN>>>\n'+(examples/f'{role}_output.json').read_bytes().decode()+'\n<<<BAGANRIKI_V041_R8_END>>>'
            for fmt in ('FULL','SOURCE_TABLE_V1'):
                init=ask(dict(role=role,format=fmt));input_text=init['input'];wire=json.loads(input_text)
                system=(texts/f'prompt_{role}.txt').read_bytes()
                if fmt=='SOURCE_TABLE_V1':system+=b'\n\n'+(texts/'SOURCE_TABLE_V1_ADDENDUM_V2.txt').read_bytes()
                if digest(system)!=init['system_hash'] or (fmt=='FULL' and input_text.encode()!=original) or wire['race_id']!=meta['race_id']:
                    raise AssertionError('independent system or initial input identity')
                for sample in range(8):
                    grant={'horses':sorted(rng.sample(range(1,17),2)),'summary_horses':[3],'presentation_horses':[4],
                           'rank_bands':sorted(rng.sample(['A','B','D'],2))}
                    diagnostic={'errors':[{'scope':'band','target':(7 if sample%2 else 'B'),'code':'QA','reason':rng.choice([
                        '馬番７の確認 🐎','quoted "text" / slash','first line\nnext line','boundary\u2028and\u2029','a\\b'])+str(sample)}],
                        'missing_horses':[],'duplicated_horses':[],'unknown_horses':[31] if sample%2 else [],'isolated_horses':[]}
                    fix={'race_id':meta['race_id'],'ai_role':role,'snapshot_hash':meta['snapshot_hash'],'attempt_no':1,'max_attempts':1,'repair_scope':grant,**diagnostic}
                    user=encoded({'fix_request':fix,'first_response':first})[:-1]+',"input":'+input_text+'}'
                    body=user.encode();limit=len(body)+len(system);user_sha=digest(body);passed=sample%2==0
                    actual=ask(dict(role=role,format=fmt,grant=grant,diagnostics=diagnostic,limit=limit if passed else limit-1))
                    if actual['checked']!=[limit,user_sha] or actual['code']!=('ACCEPTED' if passed else 'W2_SEND_BUDGET'):
                        raise AssertionError(('wire or budget mismatch',role,fmt,sample,actual['code']))
                    if passed:
                        identity=['W9_REQUEST_V1',meta['prompt_version'],meta['race_id'],meta['snapshot_hash'],role,1,fmt,digest(system),user_sha]
                        if actual['request_sha256']!=digest(json.dumps(identity,separators=(',',':')).replace('/','\\/').encode()):
                            raise AssertionError('dispatch journal identity')
                    count+=1
        worker.stdin.close()
        if worker.wait()!=0:raise AssertionError('PHP dispatch oracle exited')
    print(f'PASS R9 independent exact-byte dispatch: 4 initial role/format pairs and {count} correction bodies with inclusive or one-byte-short budgets (seed {seed}).')
