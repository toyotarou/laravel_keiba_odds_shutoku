"""Replay the original r12.1 calculation and independent-evidence implementation against PHP."""
from decimal import Decimal, localcontext, ROUND_HALF_UP
from hashlib import sha256
from pathlib import Path
import itertools
import json
import random
import subprocess
import sys
from tempfile import TemporaryDirectory
from zipfile import ZipFile

ROOT=Path(__file__).resolve().parent
ARCHIVE_HASH='cfa4d9a5f3def2031e117c64cc6464451821a340faed51f226504f07f22c3541'

def typed(v):
    if isinstance(v,(int,Decimal)) and not isinstance(v,bool):
        number=format(v,'f') if isinstance(v,Decimal) else str(v)
        if '.' in number: number=number.rstrip('0').rstrip('.')
        return ['number','0' if number=='-0' else number]
    return ['null' if v is None else 'boolean' if isinstance(v,bool) else 'string',v]

def run(php,seed):
    rng=random.Random(seed);cases=[];answers=[];formulas=0;components=0;nodes=0
    archive=ROOT/'reference_r121.zip'
    if sha256(archive.read_bytes()).hexdigest()!=ARCHIVE_HASH: raise ValueError('r12.1 source hash')
    with TemporaryDirectory() as tmp:
        with ZipFile(archive) as z: z.extractall(tmp)
        sys.path.insert(0,tmp)
        try:
            import engine,reference
            def formula(name,parents,claimed):
                nonlocal formulas
                inputs={str(i):{'parents':[],'source_kind':'OBSERVED','scope':'HORSE','horse_nos':[1],'value':v} for i,v in enumerate(parents)}
                tree={**inputs,'result':{'source_kind':'DERIVED','parents':list(inputs),'scope':'HORSE','horse_nos':[1],'formula_version':'1','formula_id':name,'value':claimed}}
                try: engine.formula_check(tree);accept=True
                except engine.ContractError: accept=False
                cases.append(['formula',name,list(map(typed,parents)),typed(claimed)]);answers.append(accept);formulas+=1
            for a,b in [(None,None),(True,1),('2',2),('same','same'),(True,True),(Decimal('2.00'),2)]:
                formula('identity',[a],b)
            for f in ['ratio_12dp','shortening_12dp','unknown','identity']:
                for vs in [[],[0],[0,1],[-1,2],[True,1],[None,'x'],[1,None],[1,2,3]]:
                    for claim in [None,0]: formula(f,vs,claim)
            with localcontext() as ctx:
                ctx.prec=160;unit=Decimal('0.000000000001')
                pairs=[(Decimal('2'),Decimal(v)) for v in ['0.0000000000009999999','0.000000000001','0.000000000001000001','1.999999999999','2.000000000001','2.00000000000099999','2.00000000000100001']]
                pairs += [(Decimal(10**42+3),Decimal(10**38+17)),(Decimal(10**38+17),Decimal(10**42+3))]
                for _ in range(128):
                    pairs.append((Decimal(rng.randint(1,10**26)).scaleb(-rng.randrange(13)),Decimal(rng.randint(1,10**26)).scaleb(-rng.randrange(13))))
                for a,b in pairs:
                    for name,raw in [('ratio_12dp',b/a),('shortening_12dp',(a-b)/a)]:
                        correct=raw.quantize(unit,rounding=ROUND_HALF_UP)
                        for value in [correct,correct+unit,correct-unit]: formula(name,[a,b],value)
            def groups(ev):
                nonlocal components
                cases.append(['components',ev]);answers.append(reference.independent_count(ev));components+=1
            chain=[{'family':f,'verified_roots':r} for f,r in [('a',['x']),('b',['x','y']),('c',['y'])]]
            for seq in itertools.permutations(chain):groups(list(seq))
            for _ in range(512):
                groups([{'family':rng.choice('ABCDE'),'verified_roots':rng.sample(list('abcdefghij'),rng.randrange(1,5))} for _ in range(rng.randrange(10))])
            for _ in range(80):
                tree={}
                for i in range(12):
                    tree['n'+str(i)]={'dependency_key':'obs'+str(i%7),'dependency_role':rng.choice(['SIGNAL','CONTEXT']),
                        'parents':rng.sample(list(tree),rng.randrange(0,min(len(tree),3)+1))}
                keys=list(tree);rng.shuffle(keys)
                cases.append(['roots',tree,keys]);answers.append([sorted(reference.source_roots(k,tree)) for k in keys]);nodes+=len(keys)
        finally: sys.path.remove(tmp)
    stream=''.join(json.dumps(x,separators=(',',':'))+'\n' for x in cases)
    proc=subprocess.run([php,'-n',str(ROOT/'r4_oracle.php')],input=stream,capture_output=True,check=True,text=True)
    actual=[json.loads(line) for line in proc.stdout.splitlines()]
    if len(actual)!=len(answers): raise ValueError('incomplete reference run')
    for i,(got,expected) in enumerate(zip(actual,answers)):
        if got!=expected: raise ValueError((cases[i],got,expected))
    print(f'PASS r12.1 Python/PHP parity: {formulas} derived values, {components} family/root sets, {nodes} source-lineage lookups (seed {seed}).')
