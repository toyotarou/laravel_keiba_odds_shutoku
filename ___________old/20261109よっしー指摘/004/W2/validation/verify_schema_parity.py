"""Independent checks for the exact-type PHP validator against the shipped Draft 2020-12 schemas."""
import copy,json,random,subprocess
from pathlib import Path
from jsonschema import Draft202012Validator,FormatChecker
def run(php,bootstrap,examples):
    checkdir=Path(__file__).parent;proc=subprocess.Popen([php,str(checkdir/'schema_probe.php'),str(bootstrap)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
    count=0
    try:
        for kind in ['input','output']:
            schema=json.loads((checkdir.parent/f'schemas/{kind}_schema.json').read_text())
            v=Draft202012Validator(schema,format_checker=FormatChecker())
            original=json.loads((examples/f'1st_{kind}.json').read_text())
            def compare(obj):
                nonlocal count
                raw=json.dumps(obj,ensure_ascii=False,separators=(',',':'))
                proc.stdin.write(kind+'\t'+raw+'\n');proc.stdin.flush();result=proc.stdout.readline().strip()
                if result!=('1' if v.is_valid(obj) else '0'):raise AssertionError((kind,count,result))
                count+=1
            compare(original); flat=[]
            def traverse(obj,path):
                if isinstance(obj,dict):
                    for key,value in obj.items():flat.append((path,key));traverse(value,(*path,key))
                if isinstance(obj,list):
                    for key,value in enumerate(obj[:2]):flat.append((path,key));traverse(value,(*path,key))
            traverse(original,());rng=random.Random(42)
            for key in list(original):
                obj=dict(original);del obj[key];compare(obj)
            for path,key in rng.sample(flat,min(68,len(flat))):
                obj=copy.deepcopy(original);parent=obj
                for part in path:parent=parent[part]
                parent[key]=rng.choice([None,False,-1,0,0.5,'',{},[],{'0':True}]);compare(obj)
            # Probe schema constraints that are not equivalently represented in associative PHP arrays.
            if kind=='output':
                for key in ['horses','shortfalls','public_candidates','validation_issues']:
                    wrong=dict(original);wrong[key]={str(i):x for i,x in enumerate(original[key])};compare(wrong)
        proc.stdin.close()
        if proc.wait()!=0:raise RuntimeError('PHP schema probe failed')
    finally:
        if proc.poll() is None:proc.kill();proc.wait()
    print(f'PASS PHP validator agrees with the independent Draft 2020-12 validator on {count} complete/altered JSON documents')
