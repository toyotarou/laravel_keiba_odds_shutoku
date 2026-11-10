"""Independent end-to-end oracle: directional ranks, horse-identity joins, race-level exclusions, E and its cohort."""
from datetime import date, datetime, time, timedelta, timezone
from decimal import Decimal
from pathlib import Path
import json
import math
import random
import subprocess

TOKYO=timezone(timedelta(hours=9))
DAY=date(2026,11,1)
PROBE=Path(__file__).with_name('r6_packet_probe.php')

def fixture(rng,key,age,nums,history,far=False,missing=False):
    # Author ground truth by horse number first, then build independently shuffled uptake tables by immutable ID.
    post=datetime.combine(DAY-timedelta(days=age),time(15),TOKYO)
    numbers=sorted(nums);n=len(numbers);odds={};early={};finish={}
    pop=numbers.copy();rng.shuffle(pop)
    for j,no in enumerate(pop):
        odds[no]=Decimal('2.00')+Decimal((j//2)*12+rng.randrange(0,2))/10
        early[no]=odds[no]+Decimal(rng.choice([0,1,3,5]))/10
    if n>=2: odds[pop[1]]=odds[pop[0]]   # equal decimals break on horse number, not input order
    if missing: early.pop(pop[-1])
    order=sorted(numbers,key=lambda no:(odds[no],no))
    ids={no:f'{key}-horse-{no:02d}' for no in numbers}
    roster=[{'horse_no':no,'horse_id':ids[no]} for no in numbers];rng.shuffle(roster)
    batches={}
    for slot,values in [(6,odds),(21,early)]:
        start=post-timedelta(minutes=slot);quotes=[{'horse_id':ids[no],'win':str(val)} for no,val in values.items()];rng.shuffle(quotes)
        batches[str(slot)]={'batch_id':key+'-'+str(slot),'requested_at':start.isoformat(),
                            'received_at':(start+timedelta(seconds=1)).isoformat(),'available_at':(start+timedelta(seconds=2)).isoformat(),'quotes':quotes}
    distance=5000 if far else 1800+rng.choice([0,0,100,200])
    cl=rng.choice(['C1','C2'])
    data={'race_id':key,'N':n,'post_at':post.isoformat(),'input_freeze_at':(post-timedelta(seconds=330)).isoformat(),
          'jurisdiction':'JRA','surface':'TURF','race_type':'FLAT','distance_metres':distance,'class_group':cl,'roster':roster,'batches':batches}
    if history:
        placed=numbers.copy();rng.shuffle(placed)
        if n>=5:
            finish[placed.pop()]=None
            if age%3==0: finish[placed.pop()]=None
        place=1;j=0
        while j<len(placed):
            width=2 if age%4==0 and j==1 and j+1<len(placed) else 1
            for no in placed[j:j+width]:finish[no]=place
            j+=width;place+=width
        results=[{'horse_id':ids[no],'finish':finish[no] if finish[no] is not None else ('DQ' if no%2 else 'DNF')} for no in numbers]
        rng.shuffle(results);data.update(results=results,post_freeze_scratch=False,result_available_at=(post+timedelta(hours=1)).isoformat())
    ref={'id':key,'odds':odds,'early':early,'finish':finish,'order':order,'distance':distance,'cl':cl,'age':age}
    return data,ref

def distance(c,h,rank):
    ci=c['order'];hi=h['order'];no=ci[rank];old=hi[rank]
    # Log ratios as exact decimal quotients before conversion, a path independent of the PHP log subtraction.
    lg=lambda a,b:math.log(float(a/b))
    gaps=[((lg(c['odds'][b],c['odds'][a])-lg(h['odds'][v],h['odds'][u]))/.35)**2 for a,b,u,v in zip(ci,ci[1:],hi,hi[1:])]
    profile=math.fsum(gaps)/len(gaps) if gaps else 0
    price=(lg(c['odds'][no],h['odds'][old])/.70)**2
    move=((lg(c['early'][no],c['odds'][no])-lg(h['early'][old],h['odds'][old]))/.35)**2
    return (profile+price+move+((c['distance']-h['distance'])/400)**2+(c['cl']!=h['cl']))/5

def calculate(c,hist,rank,cal):
    no=c['order'][rank];m=len(hist);near=[];b3=b5=0
    if no not in c['early']: return 'UNAVAILABLE_INPUT',None,[],{}
    if not m: return 'UNAVAILABLE_BASELINE',None,[],{}
    for h in hist:
        old=h['order'][rank];place=h['finish'][old];y3=int(place is not None and place<=3);y5=int(place is not None and place<=5);b3+=y3;b5+=y5
        if old not in h['early']:continue
        d2=distance(c,h,rank)
        if d2<=9:
            w=math.exp(-d2/2-math.log(2)*h['age']/365)
            near.append((w,d2,h['age'],h['id'],y3,y5))
    near=sorted(near,key=lambda x:(-x[0],x[1],x[2],x[3]))[:200];k=len(near);w=math.fsum(x[0] for x in near)
    ne=w*w/math.fsum(x[0]*x[0] for x in near) if w else 0
    stats={'M':m,'K':k,'W':w,'n_eff':ne}
    for n,b,idx in [(3,b3,4),(5,b5,5)]:
        p0=(b+1)/(m+2);raw=(math.fsum(x[0]*x[idx] for x in near)+20*p0)/(w+20)
        val=raw
        if cal and k:
            clip=min(.999999,max(.000001,raw))
            val=1/(1+math.exp(-(cal['a']*math.log(clip/(1-clip))+cal['b'])))
        stats.update({f'p0_{n}':p0,f'p{n}_raw':raw,f'E{n}':100*val})
    status='BASELINE_ONLY' if not k else 'LOW_SUPPORT' if m<100 or k<20 or w<10 or ne<15 else 'COMPARISON_READY'
    return status,'CALIBRATION_APPLIED' if cal and k else 'RAW_UNCALIBRATED',[x[3] for x in near],stats

def run(php,seed):
    rng=random.Random(seed);checked=0;scenarios=0;seen=set();trimmed=False;rejected=0
    for index,(n,m) in enumerate([(1,21),(5,0),(6,12),(8,105),(12,224),(16,120),(18,226),(7,99),(10,100),(15,5)]):
        nums=rng.sample(range(1,19),n)
        current,c=fixture(rng,f'R6-target-{index}',0,nums,False,missing=index in (3,6,7))
        payload=[];truth=[];cal=None
        if index%2==0:
            cal={'formula_version':'baganriki-expected-v0.1-draft-20261025','scope':'JRA_FLAT_TURF_DIRT','id':'r6-prior','a':1.7,'b':-.2,
                 'trained_through':'2026-01-01T12:00:00+09:00','approved_at':'2026-01-02T12:00:00+09:00'}
        for j in range(m):
            d,h=fixture(rng,f'R6-h{index}-{j:03d}',j+1,rng.sample(range(1,19),n),True,far=(index==9 or j%29==0),missing=(j%17==0 and n>1))
            payload.append(d);truth.append(h)
        if n>=5:
            for category in ('tie_overlap','gap','unclassified','scratch','midnight','duplicate'):
                d,h=fixture(rng,f'R6-excluded-{index}-{category}',8,nums,True)
                if category in ('tie_overlap','gap'):
                    ranks=([1,2,2,3]+list(range(5,n+1))) if category=='tie_overlap' else (list(range(2,n+1))+['DNF'])
                    for r,place in zip(d['results'],ranks):r['finish']=place
                if category=='unclassified':
                    for r in d['results']:r['finish']='DNF'
                if category=='scratch':d['post_freeze_scratch']=True
                if category=='midnight':d['result_available_at']=datetime.combine(DAY,time(0),TOKYO).isoformat()
                payload.append(d);rejected+=1
                if category=='duplicate':payload.append(d);rejected+=1
        rng.shuffle(payload)
        raw=json.dumps({'current':current,'history':payload,'calibration':cal},separators=(',',':'))
        result=subprocess.run([php,'-n',str(PROBE)],input=raw,text=True,capture_output=True,check=True)
        got=json.loads(result.stdout)
        if (not got['proven'] or sorted(got['ids'])!=sorted(h['id'] for h in truth) or len(got['rows'])!=n):raise ValueError(('r6-pool',n,m))
        if n>=5 and got['excluded']!={'HIST_FINISH_SEQUENCE':2,'HIST_NO_CLASSIFIED_FINISH':1,'HIST_ROSTER_OR_SCRATCH':1,'HISTORY_AVAILABILITY':1,'DUPLICATE_HISTORY_ID':2}:raise ValueError(got['excluded'])
        seen_nos=set()
        for row in got['rows']:
            no=row['horse_no'];rank=c['order'].index(no);status,basis,ids,values=calculate(c,truth,rank,cal)
            if no in seen_nos or row['formula_rank']!=rank+1 or row['status']!=status or got['near'].get(str(no),[])!=ids:raise ValueError(('r6-order',index,no,status))
            seen_nos.add(no);seen.add(status)
            if basis and basis!=row['basis']:raise ValueError(('r6-basis',no))
            for key,value in values.items():
                if not math.isclose(value,row[key],abs_tol=3e-12,rel_tol=3e-13):raise ValueError(('r6-value',index,no,key,value,row[key]))
            if not values and (row['E3'] is not None or row['E5'] is not None):raise ValueError(('r6-missing',no))
            trimmed|=len(ids)==200;checked+=1
        scenarios+=1
    if len(seen)!=5 or not trimmed:raise ValueError(('r6 coverage',seen,trimmed))
    print(f'PASS R6 independent full-packet replay: {scenarios} target races / {checked} horses, {rejected} excluded history rows; 21/6-minute horse IDs, capped neighbours, dead heats, E3/E5 and calibration (seed {seed}).')

def archived_join(php,w1dir):
    # Original horse numbers/odds; artificial transport timestamps, IDs and two controlled histories.
    # Only the keyed input join is evaluated. These E scores are never reported as old predictions.
    source=json.loads((w1dir/'validation/market_inputs.json').read_text());slots=source['slots']
    early,late=slots.index('21'),slots.index('6');moved=matched=0
    def shell(key,day,nums,late_odds,early_odds,is_history):
        post=datetime.combine(day,time(15),TOKYO);id_of={no:key+'#'+str(no) for no in nums}
        batches={}
        for slot,values in [(6,late_odds),(21,early_odds)]:
            at=post-timedelta(minutes=slot);seq=nums[::-1] if slot==21 else nums[1:]+nums[:1]
            batches[str(slot)]={'batch_id':key+'-'+str(slot),'requested_at':at.isoformat(),'received_at':(at+timedelta(seconds=1)).isoformat(),
                'available_at':(at+timedelta(seconds=2)).isoformat(),'quotes':[{'horse_id':id_of[n],'win':str(values[n])} for n in seq]}
        data={'race_id':key,'N':len(nums),'post_at':post.isoformat(),'input_freeze_at':(post-timedelta(seconds=330)).isoformat(),'jurisdiction':'JRA',
              'surface':'TURF','race_type':'FLAT','distance_metres':1800,'class_group':'C1','roster':[{'horse_no':n,'horse_id':id_of[n]} for n in reversed(nums)],'batches':batches}
        if is_history:data.update(post_freeze_scratch=False,result_available_at=(post+timedelta(hours=1)).isoformat(),
                                   results=[{'horse_id':id_of[n],'finish':i+1} for i,n in enumerate(nums)])
        return data
    for race in source['races']:
        day=date.fromisoformat(race['id'][:10]);rows=race['runners'];nums=sorted(r['horse'] for r in rows)
        win={r['horse']:Decimal(str(r['win'][late])) for r in rows};prior={r['horse']:Decimal(str(r['win'][early])) for r in rows}
        frozen=sorted(nums,key=lambda n:(win[n],n));previous=sorted(nums,key=lambda n:(prior[n],n));moved+=sum(a!=b for a,b in zip(frozen,previous))
        target=shell('archive-'+race['id'],day,nums,win,prior,False);history=[]
        for factor in (1,2):history.append(shell('control'+str(factor),day-timedelta(days=factor),nums,win,{n:win[n]*factor for n in nums},True))
        raw=json.dumps({'current':target,'history':history,'calibration':None,'with_distances':True},separators=(',',':'))
        got=json.loads(subprocess.run([php,'-n',str(PROBE)],input=raw,text=True,capture_output=True,check=True).stdout)
        for item in got['rows']:
            n=item['horse_no']
            if item['formula_rank']!=frozen.index(n)+1:raise ValueError(('archived rank',race['id'],n))
            for factor in (1,2):
                expected=(math.log(float(prior[n]/(win[n]*factor)))/.35)**2/5
                actual=got['dist'][str(n)].get('control'+str(factor))
                if (expected<=9 and (actual is None or not math.isclose(actual,expected,abs_tol=2e-13,rel_tol=2e-13))) or (expected>9 and actual is not None):
                    raise ValueError(('archived early/late join',race['id'],n,factor,actual,expected))
            matched+=1
    if (len(source['races']),matched,moved)!=(8,123,38):raise ValueError('weekend fixture changed')
    print(f'PASS archived 21/6-minute odds joined for {matched} actual runners in 8 races, {moved} changed formula rank; controlled test history only.')
