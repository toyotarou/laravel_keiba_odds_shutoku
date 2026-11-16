"""Check the unmodified formula engine against independently calculated weighted frequencies."""
import json,math,random,subprocess
from pathlib import Path
def run(php,seed):
    rng=random.Random(seed); inputs=[];wanted=[]
    for case in range(256):
        M=rng.choice([0,1,12,99,100,101,199,200,450,1400]);K=min(M,rng.choice([0,1,14,15,19,20,21,130,200])); rows=[]
        b3=b5=0
        for j in range(M):
            v=rng.randrange(12);y3=int(v<3);y5=int(v<5);b3+=y3;b5+=y5
            if j<K:
                # Dyadic weights avoid accidental equality jitter at decision thresholds.
                rows.append({'race_id':str(j),'w':rng.choice([0,0.0625,0.25,0.5,0.75,1]),'y3':y3,'y5':y5})
        cal={'a':1.4,'b':-0.3} if case%3==0 else None
        inputs.append({'M':M,'b3':b3,'b5':b5,'rows':rows,'cal':cal})
        W=math.fsum(r['w'] for r in rows);sq=math.fsum(r['w']**2 for r in rows);ne=W*W/sq if sq else 0
        count=sum(r['w']>0 for r in rows)
        status='UNAVAILABLE_BASELINE' if M==0 else 'BASELINE_ONLY' if W==0 else 'LOW_SUPPORT' if (M<100 or count<20 or W<10 or ne<15) else 'COMPARISON_READY'
        e={'W':W,'n_eff':ne,'support_status':status}
        for n,b in [(3,b3),(5,b5)]:
            raw=None if not M else (math.fsum(r['w']*r[f'y{n}'] for r in rows)+20*(b+1)/(M+2))/(W+20)
            e[f'p{n}_raw']=raw
            clip=None if raw is None else min(1-1e-6,max(1e-6,raw))
            e[f'p{n}_cal']=None if not cal or W==0 else 1/(1+math.exp(-(cal['a']*math.log(clip/(1-clip))+cal['b'])))
        wanted.append(e)
    r=subprocess.run([php,'-n',str(Path(__file__).with_name('formula_probe.php'))],input=''.join(json.dumps(x)+'\n' for x in inputs),text=True,check=True,capture_output=True)
    values=[json.loads(line) for line in r.stdout.splitlines()]
    if len(values)!=len(wanted):raise AssertionError('formula output length')
    for i,(got,expect) in enumerate(zip(values,wanted)):
        for k,y in expect.items():
            x=got[k]
            if not (x==y or (isinstance(x,(float,int)) and isinstance(y,(float,int)) and math.isclose(x,y,rel_tol=1e-12,abs_tol=1e-13))):raise AssertionError((seed,i,k,x,y))
    print(f'PASS {len(values)} independent E3/E5 formula cohorts, including calibration and sparse samples (seed={seed})')
