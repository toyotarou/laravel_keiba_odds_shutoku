# Reproducible independent numerical/JSON audit. Does NOT execute PHP or Dart.
import json, math, re, random, datetime
from decimal import Decimal, localcontext
from pathlib import Path
root=Path(__file__).resolve().parents[1]
DV="baganriki-expected-display-v0.1.2"
IV="baganriki-expected-impl-v0.1.4"
FV="baganriki-expected-v0.1-draft-20261025"
data=json.loads((root/"display/display_test_vectors.json").read_text())
def stamp(identity):
    for key in ['race_id','horse_id','snapshot_id','frozen_at']:
        assert isinstance(identity[key],str) and identity[key].strip()==identity[key] and identity[key]
    for key in ['horse_number','popularity_rank']:
        assert type(identity[key]) is int and 1<=identity[key]<=99
    assert identity['source_minutes_before']==6 and identity['runner_status'] in ['ACTIVE','WITHDRAWN']
    s=identity['frozen_at']
    assert re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00',s)
    datetime.datetime.strptime(s,'%Y-%m-%dT%H:%M:%S+09:00')

def check_client(p,e):
    ident=dict(e,source_minutes_before=p['source_minutes_before'])
    stamp(ident)
    assert p['display_schema_version']==DV and p['implementation_version']==IV and p['formula_version']==FV
    assert p['comparison_only'] is True and p['validated_for_live'] is False
    assert p['main_target']=='TOP5' and p['unit']=='score_0_100'
    assert type(p['source_minutes_before']) is int and p['source_minutes_before']==6
    for key,v in e.items():assert p[key]==v
    for key in ['horse_number','popularity_rank']:assert type(p[key]) is int
    scores=[]
    for key in ['top3_expectation_value','expectation_value']:
        v=p[key];text=p[key+'_text']
        if v is None:assert text=='—'
        else:
            assert type(v) in [int,float] and math.isfinite(v) and 0<=v<=100
            assert abs(v*10-round(v*10))<=1e-7 and text==f'{v:.1f}'
        scores.append(v)
    assert (p['support_status']=='WITHDRAWN')==(e['runner_status']=='WITHDRAWN')
    if None in scores:
        assert scores==[None,None] and p['score_basis']=='UNAVAILABLE'
        assert p['support_status'] in ['UNAVAILABLE_INPUT','UNAVAILABLE_BASELINE','OUT_OF_SCOPE','CALCULATION_ERROR','WITHDRAWN']
    else:
        assert scores[0]<=scores[1]
        assert p['support_status'] in ['COMPARISON_READY','LOW_SUPPORT','BASELINE_ONLY']
        assert p['score_basis'] in ['RAW_UNCALIBRATED','CALIBRATION_APPLIED']
        if p['support_status']=='BASELINE_ONLY':assert p['score_basis']=='RAW_UNCALIBRATED'
passed=0
for case in data['client_cases']:
    try:
        check_client(case['payload'],case['expected_identity'])
        accepted=True
    except (AssertionError,KeyError,TypeError,ValueError):
        accepted=False
    assert accepted==case['expected_accept'],case['id']
    passed+=1
# Compare stable weighted shrinkage to a high-precision independent expression.
rng=random.Random(20261025);math_passed=0
for n in range(500):
    weights=[10.0**rng.uniform(-280,0) for _ in range(rng.randint(1,200))]
    outcomes=[rng.randrange(3) for _ in weights]
    p03=.10;p05=.20;lam=10.0**rng.uniform(-280,280)
    wsum=math.fsum(weights);maxw=max(weights);scaled=[w/maxw for w in weights]
    ss=math.fsum(scaled);sq=math.fsum(w*w for w in scaled)
    ne=ss*ss/sq
    assert 1-1e-12<=ne<=len(weights)+1e-10
    share=wsum/(wsum+lam)
    prior_share=lam/(wsum+lam)
    stable=[]
    for k,p0 in [(0,p03),(1,p05)]:
        rate=math.fsum(w for w,y in zip(scaled,outcomes) if y<=k)/ss
        value=min(1.0,max(0.0,share*rate+prior_share*p0))
        with localcontext() as ctx:
            ctx.prec=80
            dw=[Decimal(str(w)) for w in weights];dl=Decimal(str(lam))
            exact=(sum((w for w,y in zip(dw,outcomes) if y<=k),Decimal(0))+dl*Decimal(str(p0)))/(sum(dw)+dl)
        assert math.isclose(value,float(exact),rel_tol=3e-13,abs_tol=0.0)
        stable.append(value)
    assert stable[0]<=stable[1]+1e-15
    math_passed+=1
assert math.exp(-.5*(math.sqrt(0)/1e-200)**2)==1
# pow(1e200,2) in Python raises; multiplication, as in PHP code, becomes infinity.
z=math.sqrt(1)/1e-200
assert math.exp(-.5*z*z)==0
assert sum((w/max([1e-200,1e-200])) for w in [1e-200,1e-200])**2/sum((w/1e-200)**2 for w in [1e-200,1e-200])==2


# Zero local successes make cancellation of the prior contribution visible.
tail_checks=0
for exponent in [20,40,100,200,280]:
    for p0 in [.1,.2]:
        lam=10.0**(-exponent)
        prior_share=lam/(1.0+lam)
        actual=prior_share*p0
        with localcontext() as ctx:
            ctx.prec=100
            dlam=Decimal(10)**(-exponent)
            expected=float(dlam*Decimal(str(p0))/(1+dlam))
        assert actual>0 and math.isclose(actual,expected,rel_tol=3e-13,abs_tol=0.0)
        tail_checks+=1
# Previous v0.1.2 expression was 0.0 for this representable positive result.
assert (1.0 - 1.0/(1.0+1e-20))*.1 == 0.0
assert 1e-20/(1+1e-20)*.1 > 0.0

example=json.loads((root/"display/example_numeric_response.json").read_text())
assert example['expectation_value']==32.0 and example['top3_expectation_value']==14.0
assert example['expectation_value_text']=='32.0' and example['top3_expectation_value_text']=='14.0'
print(json.dumps({'python_transport_cases_passed':passed,'python_decimal_math_cases_passed':math_passed,'positive_tail_checks_passed':tail_checks,'php_dart_flutter_execution_performed':False,'example_values':[32.0,14.0]}))
