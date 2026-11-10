"""Cross-check the PHP market implementation with W1's independent Fraction/arithmetic reference."""
import itertools,json,random,runpy,subprocess,sys
from fractions import Fraction
from pathlib import Path
from decimal import Decimal
from zipfile import ZipFile

fields=['X1','X2','X3','X4','X5','X6','Z']
def truth(signals,w1):
    status,alloc,lo,hi=w1['allocate'](signals)
    star=lambda s:1 if s<=0 else 2 if s==1 else 3 if s<=3 else 4 if s<=5 else 5
    return {'S':lo if None not in signals else None,'S_min':lo,'S_max':hi,'mode':alloc if status=='CONFIRMED' else 'UNKNOWN','mode_status':status,
            'allocation_mode':alloc,'mode_policy_id':'X6_ONLY_UPPER_MODE_V1' if status=='PROVISIONAL' else 'BASE_TRI_STATE_V1','selection_enabled':alloc is not None,
            'display_rating':star(lo) if status=='CONFIRMED' and star(lo)==star(hi) else None}
def odds_case(raw,w1):
    rows=sorted(raw,key=lambda r:r['rank'])
    horses=[{'rank':r['rank'],'win':[None,r['win21'],None,None,r['win6']], 'place_min':[None,r['place21'],None,r['place9'],r['place6']]} for r in rows]
    sig=w1['market_signals'](horses)
    ranked={str(r['no']):None for r in rows}
    if all(r['win6'] is not None for r in rows):
        for pos,r in enumerate(sorted(rows,key=lambda h:(Fraction(h['win6']),h['no'])),1):ranked[str(r['no'])]=pos
    return {'signals':dict(zip(fields,sig)),'formula_ranks':ranked,**truth(sig,w1)}
def run(php,w1dir,seed=20261101):
    w1=runpy.run_path(str(w1dir/'validation/verify.py'));rng=random.Random(seed)
    tasks=[];wants=[];real_report=[]
    def add(test,want):tasks.append(test);wants.append(want)
    for n in [6,16]:
        for vals in itertools.product([False,True,None],repeat=6):
            sig=[*vals[:5],None if n>=7 else False,vals[5]]
            add({'N':n,'signals':dict(zip(fields,sig))},truth(sig,w1))
    markets=json.loads((w1dir/'validation/market_inputs.json').read_text())['races']
    for race in markets:
        raw=[{'no':h['horse'],'rank':h['rank'],'win21':h['win'][1],'win6':h['win'][4],'place21':h['place_min'][1],'place9':h['place_min'][3],'place6':h['place_min'][4]} for h in race['runners']]
        want=odds_case(raw,w1);add({'market':raw},want)
        real_report.append({'id':race['id'],'N':len(raw),'signals':want['signals'],'S_min':want['S_min'],'S_max':want['S_max'],
                            'mode_status':want['mode_status'],'allocation_mode':want['allocation_mode']})
    # Independent fake markets exercise every roster size, missing quotations, equality and threshold neighbours.
    edge=['1','1.5','1.49999999999999999999','1.50000000000000000001','1.99999999999999999999','2','2.00000000000000000001','10','9.5','9.7','1e+1']
    for n in range(1,19):
        for trial in range(32):
            nums=rng.sample(range(1,19),n);wins=sorted([rng.choice(edge) for _ in nums],key=Fraction)
            def pick():return None if rng.randrange(7)==0 else rng.choice(edge)
            rows=[{'no':no,'rank':j+1,'win6':None if rng.randrange(9)==0 else wins[j], 'win21':pick(),'place21':pick(),'place9':pick(),'place6':pick()} for j,no in enumerate(nums)]
            rng.shuffle(rows);add({'market':rows},odds_case(rows,w1))
    raw=''.join(json.dumps(v,separators=(',',':'))+'\n' for v in tasks)
    r=subprocess.run([php,'-n',str(Path(__file__).with_name('market_oracle.php'))],input=raw,text=True,check=True,capture_output=True)
    output=[json.loads(x) for x in r.stdout.splitlines()]
    if len(output)!=len(wants):raise AssertionError('market test case count')
    for i,(actual,expected) in enumerate(zip(output,wants)):
        if actual!=expected:raise AssertionError((seed,i,actual,expected))
    print(f'PASS PHP vs independent FRACTION oracle: 1458 tri-state cases, 576 market books across N=1..18 (seed={seed}), and 8 archived races / 123 actual runners')
    return real_report
