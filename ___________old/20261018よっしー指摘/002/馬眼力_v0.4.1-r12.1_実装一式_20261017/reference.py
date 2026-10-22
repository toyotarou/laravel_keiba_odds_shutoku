"""r8 reference model. Not a drop-in production PHP adapter or predictor."""
from __future__ import annotations
import copy
import hashlib
import itertools
import json
import math
import re
from decimal import Decimal
from collections import deque
from datetime import datetime, timedelta
from collections import Counter
from fractions import Fraction

BANDS = ('A', 'B', 'D')
FAMILIES = ('market', 'ability', 'gap', 'win_place', 'similar_value')
COVERAGE_KEYS = ('past_runs','OPI','estimated_odds','recovery_statistics',
                 'similar_statistics','legacy_qualifications','gap_history')

def validate_coverage(coverage, registry):
    if not isinstance(coverage,dict) or set(coverage)!=set(COVERAGE_KEYS):
        raise ValueError('coverage manifest required')
    for key,item in coverage.items():
        if not isinstance(item,dict): raise ValueError('coverage item')
        if item.get('status')=='INCLUDED':
            refs=item.get('source_refs')
            if (set(item)!={'status','source_refs'} or not isinstance(refs,list) or not refs
                    or any(not isinstance(x,str) or x not in registry for x in refs)
                    or len(refs)!=len(set(refs))): raise ValueError('coverage refs: '+key)
        elif item.get('status')=='UNAVAILABLE':
            if (set(item)!={'status','reason'} or not isinstance(item.get('reason'),str)
                    or not item['reason'].strip()): raise ValueError('coverage reason: '+key)
        else: raise ValueError('coverage status: '+key)
CAPS = dict(H1=18, H2=15, H3=12, H4=9, H5=6, V1=12, V2=10, V3=8, V4=6, V5=4)
BASE = {
 4: {'FIRM':(3,1,0),'MIXED':(2,2,0),'VOLATILE':(2,1,1)},
 5: {'FIRM':(4,1,0),'MIXED':(3,2,0),'VOLATILE':(2,2,1)},
 6: {'FIRM':(4,2,0),'MIXED':(3,2,1),'VOLATILE':(3,2,1)},
 7: {'FIRM':(5,2,0),'MIXED':(3,3,1),'VOLATILE':(3,3,1)},
}
STRUCTURAL = {'D':('B','A'),'B':('D','A'),'A':('B','D')}
ELIGIBLE_FILL = {'D':('B',),'B':('D',),'A':('B','D')}

def exact_int(x):
    return type(x) is int

def finite_number(x):
    return type(x) is int or (type(x) is float and math.isfinite(x)) or (type(x) is Decimal and x.is_finite())

def strict_loads(text, max_bytes=4*1024*1024):
    def pairs(items):
        out={}
        for k,v in items:
            if k in out: raise ValueError('duplicate JSON key')
            out[k]=v
        return out
    def invalid(x): raise ValueError('nonfinite JSON constant')
    if not isinstance(text,str) or len(text.encode('utf-8'))>max_bytes: raise ValueError('JSON size/type')
    def decimal(token):
        value=Decimal(token)
        if len(value.as_tuple().digits)>128 or not -308<=value.adjusted()<=308:
            raise ValueError('decimal precision/range')
        return value
    def integer(token):
        if len(token.lstrip('-'))>128: raise ValueError('integer precision/range')
        return int(token)
    # Bound container depth before parsing, copying or formatting an error. Brackets
    # in strings (including escaped quotes) do not count as containers.
    depth=0;quoted=False;escaped=False
    for char in text:
        if quoted:
            if escaped: escaped=False
            elif char=='\\': escaped=True
            elif char=='"': quoted=False
        elif char=='"': quoted=True
        elif char in '[{':
            depth+=1
            if depth>64: raise ValueError('JSON nesting limit: 64')
        elif char in ']}': depth-=1
    try: result=json.loads(text,object_pairs_hook=pairs,parse_constant=invalid,parse_float=decimal,parse_int=integer)
    except RecursionError as exc: raise ValueError('JSON nesting') from exc
    def check(v):
        if isinstance(v,float) and not math.isfinite(v): raise ValueError('nonfinite number')
        if isinstance(v,str):v.encode('utf-8')  # reject isolated UTF-16 surrogates before persistence
        if isinstance(v,dict):
            for k,x in v.items(): k.encode('utf-8');check(x)
        if isinstance(v,list):
            for x in v: check(x)
    check(result)
    return result

def applicable_ref(ref,registry,horse_no):
    if not isinstance(ref,str) or ref not in registry: return False
    s=registry[ref]
    return ((s['scope'] not in ('HORSE','PAIR') or horse_no in s['horse_nos'])
        and (s['scope']!='COHORT' or horse_no in s['applicable_horse_nos']))

def verified_counterevidence(items,horse_no,registry):
    valid=[];issues=[]
    if not isinstance(items,list): return [],['counterevidence_not_array']
    for item in items:
        refs=item.get('source_refs') if isinstance(item,dict) else None
        if (not isinstance(refs,list) or not refs or any(not applicable_ref(x,registry,horse_no) for x in refs)
                or len(set(refs))!=len(refs) or not isinstance(item.get('reason'),str) or not item['reason'].strip()
                or not any(registry[x]['value'] is not None for x in refs)):
            issues.append('invalid counterevidence reference/reason');continue
        valid.append(copy.deepcopy(item))
    return valid,issues

def review_issues(review,evidence,horse_no,registry):
    issues=[]
    if not isinstance(review,dict) or set(review)!=set(FAMILIES): return ['family_review fields']
    for family in FAMILIES:
        item=review[family];ev=[x for x in evidence if x['family']==family]
        if not isinstance(item,dict): issues.append(family);continue
        refs=item.get('source_refs');ids=item.get('evidence_ids');status=item.get('status')
        valid=(isinstance(refs,list) and all(applicable_ref(x,registry,horse_no) for x in refs)
            and len(refs)==len(set(refs)) and isinstance(ids,list) and all(isinstance(x,str) for x in ids)
            and len(ids)==len(set(ids)) and isinstance(item.get('reason'),str) and bool(item['reason'].strip()))
        if valid and ev:
            valid=(status=='SUPPORTED' and set(ids)=={x['evidence_id'] for x in ev}
                and {ref for x in ev for ref in x['source_refs']}<=set(refs))
        elif valid:
            valid=(not ids and status in ('NO_SUPPORT','DATA_MISSING'))
            if status=='NO_SUPPORT': valid=valid and bool(refs) and any(registry[x]['value'] is not None for x in refs)
        if not valid: issues.append(family)
    return issues

def json_dumps(value):
    """Exact finite decimal JSON, also used for PHP interchange. No float coercion."""
    if value is None: return 'null'
    if type(value) is bool: return 'true' if value else 'false'
    if type(value) is int: return str(value)
    if type(value) is Decimal:
        if not value.is_finite(): raise ValueError('nonfinite Decimal')
        return str(value)
    if type(value) is float:
        if not math.isfinite(value): raise ValueError('nonfinite float')
        return json.dumps(value)
    if isinstance(value,str): return json.dumps(value,ensure_ascii=False)
    if isinstance(value,(list,tuple)): return '['+','.join(json_dumps(x) for x in value)+']'
    if isinstance(value,dict):
        if any(not isinstance(k,str) for k in value): raise ValueError('JSON object key')
        return '{'+','.join(json_dumps(k)+':'+json_dumps(value[k]) for k in sorted(value))+'}'
    raise ValueError('unsupported JSON type')

def canonical_hash(value):
    return hashlib.sha256(json_dumps(value).encode('utf-8')).hexdigest()

def target_count(n):
    if not exact_int(n) or not 0<=n<=18: raise ValueError('unsupported active count')
    return n if n<=3 else 4 if n<=8 else 5 if n<=13 else 6 if n<=15 else 7

def band(rank):
    if not exact_int(rank) or not 1<=rank<=18: raise ValueError('bad frozen rank')
    return 'A' if rank<=6 else 'B' if rank<=10 else 'D'

def validate_runners(runners):
    n=len(runners)
    if not 1<=n<=18: raise ValueError('bad snapshot N')
    numbers=[h['horse_no'] for h in runners]
    ranks=[h['win_rank'] for h in runners]
    if any(not exact_int(x) or x<1 for x in numbers+ranks): raise ValueError('bad identity')
    if len(set(numbers))!=n or set(ranks)!=set(range(1,n+1)): raise ValueError('bad snapshot set')
    if any(h['band']!=band(h['win_rank']) for h in runners): raise ValueError('bad band')

def transfer(requested, available, donors):
    selected={b:min(requested[b],available[b]) for b in BANDS}
    missing={b:requested[b]-selected[b] for b in BANDS}
    moves=[]
    for b in ('D','B','A'):
        for donor in donors[b]:
            count=min(missing[b],available[donor]-selected[donor])
            if count:
                selected[donor]+=count; missing[b]-=count
                moves.append(dict(requested_band=b,actual_band=donor,count=count))
    return selected,moves

def structural_quota(runners, mode):
    if mode not in ('FIRM','MIXED','VOLATILE'): return None, None, []
    c=target_count(len(runners))
    base=dict(zip(BANDS,(c,0,0) if c<=3 else BASE[c][mode]))
    capacities={b:sum(h['band']==b for h in runners) for b in BANDS}
    quota,moves=transfer(base,capacities,STRUCTURAL)
    assert sum(quota.values())==c
    return base,quota,moves

def role_quota(quota,c,role):
    if quota is None: return 0,None
    if role not in ('1st','2nd'): raise ValueError('bad role')
    if role=='1st' or c<=5: return c,quota.copy()
    q=dict(A=5-quota['B']-quota['D'],B=quota['B'],D=quota['D'])
    if min(q.values())<0: raise ValueError('2nd quota impossible')
    return 5,q

def tri_and(*values):
    return False if False in values else None if None in values else True

def tri_or(*values):
    return True if True in values else None if None in values else False

def at_least(values,m):
    t=sum(x is True for x in values); u=sum(x is None for x in values)
    return True if t>=m else False if t+u<m else None

def mode_value(s): return 'FIRM' if s<=1 else 'MIXED' if s<=3 else 'VOLATILE'
def rating_value(s): return 1 if s<=0 else 2 if s==1 else 3 if s<=3 else 4 if s<=5 else 5

def signal_mode(signals):
    if len(signals)!=7 or any(x is not None and type(x) is not bool for x in signals):
        raise ValueError('signals must be seven bool/nulls')
    weights=(2,1,1,1,1,1,-2)
    possible={sum(w*x for w,x in zip(weights,case))
       for case in itertools.product(*((0,1) if x is None else (int(x),) for x in signals))}
    modes={mode_value(s) for s in possible}; ratings={rating_value(s) for s in possible}
    mode=next(iter(modes)) if len(modes)==1 else 'UNKNOWN'
    return dict(S=next(iter(possible)) if None not in signals else None,
                S_min=min(possible),S_max=max(possible),mode=mode,
                display_rating=next(iter(ratings)) if mode!='UNKNOWN' and len(ratings)==1 else None)

def extract_signals(n,pairs,horses,similar=None):
    """pairs[k]={W21,W6,P6}; horses have frozen win_rank, P21/P9/P6.
    Input observations and ratio provenance must already be validated.
    Fractions compare finite decimal values without binary threshold drift.
    """
    if not exact_int(n) or not 1<=n<=18: raise ValueError('bad signal N')
    ranks=[h.get('win_rank') for h in horses]
    if (len(ranks)!=n or any(not exact_int(x) for x in ranks)
            or set(ranks)!=set(range(1,n+1))): raise ValueError('bad signal horse set')
    if any(not exact_int(k) or not 1<=k<n for k in pairs): raise ValueError('bad pair rank')
    def num(v): return v if isinstance(v,Fraction) and v>0 else Fraction(str(v)) if finite_number(v) and v>0 else None
    def compare(v,op,threshold):
        x=num(v)
        return None if x is None else (x>=Fraction(threshold) if op=='ge' else x<Fraction(threshold))
    def shortening(before,after,threshold):
        a,b=num(before),num(after)
        return None if a is None or b is None else (a-b)/a>=Fraction(threshold)
    def p(k,key): return pairs.get(k,{}).get(key)
    x1=at_least([tri_and(compare(p(k,'W21'),'ge','2'),
        tri_or(compare(p(k,'W6'),'lt','2'),shortening(p(k,'W21'),p(k,'W6'),'0.20')))
        for k in range(1,min(6,n-1)+1)],1)
    x2=at_least([tri_and(compare(p(k,'W6'),'ge','2'),compare(p(k,'P6'),'lt','1.50'))
        for k in range(1,min(10,n-1)+1)],1)
    x3=False if n<7 else tri_and(*(compare(p(k,'W6'),'lt','1.50') for k in (3,4,5,6)))
    x4=at_least([tri_or(shortening(h.get('P21'),h.get('P6'),'0.05'),
                           shortening(h.get('P9'),h.get('P6'),'0.03'))
        for h in horses if h['win_rank']>=7],2)
    x5=at_least([compare(p(k,'W6'),'ge','2') for k in range(7,min(10,n-1)+1)],1)
    x6=False if n<7 else None
    if n>=7 and similar and similar.get('definition_valid') is True:
        count=similar.get('race_count'); rate=similar.get('rate'); baseline=similar.get('baseline')
        enough=(count>=50) if exact_int(count) and count>=0 else None
        difference=(Fraction(str(rate))-Fraction(str(baseline))>=Fraction('0.10')) if (
            finite_number(rate) and finite_number(baseline) and 0<=rate<=1 and 0<=baseline<=1) else None
        x6=tri_and(enough,difference)
    z=at_least([tri_and(compare(p(k,'W21'),'ge','2'),compare(p(k,'W6'),'ge','2'),
                        compare(p(k,'P6'),'ge','1.50')) for k in range(1,min(5,n-1)+1)],2)
    return [x1,x2,x3,x4,x5,x6,z]

def timestamp(text):
    # datetime.fromisoformat silently truncates fractions beyond microseconds
    # and accepts forms that our RFC3339 input schema does not accept.
    if not isinstance(text,str) or re.fullmatch(
            r'[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}'
            r'(?:\.[0-9]{1,6})?(?:[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])',text) is None:
        raise ValueError('timestamp: RFC3339 with timezone and at most 6 fractional digits required')
    return datetime.fromisoformat(text.upper().replace('Z','+00:00'))

def checked_json(value,max_bytes=4*1024*1024):
    """Validate native DTOs against the same bounds as persisted/transport JSON.

    Check a serialization, but keep the original object and exact numeric values.
    """
    try:encoded=json_dumps(value)
    except RecursionError as exc:raise ValueError('JSON nesting') from exc
    strict_loads(encoded,max_bytes=max_bytes)
    return encoded

def same_scalar(a,b):
    # Boolean is never a number. JSON numeric 2 and 2.0 compare as numbers.
    if finite_number(a) and finite_number(b): return a==b
    return type(a) is type(b) and (a is None or isinstance(a,(str,bool))) and a==b

def validate_registry(registry,race_id,cutoff,runner_numbers,field_catalog,request_at=None):
    cutoff=timestamp(cutoff); identifiers=set(runner_numbers); seen=set()
    request_at=timestamp(request_at) if request_at is not None else cutoff
    for ref,s in registry.items():
        if ref!=s['source_ref'] or s['race_id']!=race_id: raise ValueError('source identity')
        v=s['value']
        if not (v is None or type(v) in (str,bool) or finite_number(v)): raise ValueError('source scalar')
        if timestamp(s['observed_at'])>cutoff or timestamp(s['available_at'])>cutoff:
            raise ValueError('future source')
        if timestamp(s['observed_at'])>timestamp(s['available_at']):
            raise ValueError('available before observation')
        owners=s['horse_nos']
        if any(not exact_int(x) for x in owners) or len(set(owners))!=len(owners) or not set(owners)<=identifiers:
            raise ValueError('source owners')
        if s['scope']=='HORSE' and len(owners)!=1: raise ValueError('horse scope')
        if s['scope']=='PAIR' and len(owners)!=2: raise ValueError('pair scope')
        if s['scope'] in ('RACE','COHORT') and owners: raise ValueError('aggregate owners')
        if s['scope'] not in ('HORSE','PAIR','RACE','COHORT'): raise ValueError('source scope')
        applies=s['applicable_horse_nos']
        if any(not exact_int(x) for x in applies) or len(set(applies))!=len(applies) or not set(applies)<=identifiers:
            raise ValueError('cohort membership')
        if (s['scope']=='COHORT' and not applies) or (s['scope']!='COHORT' and applies):
            raise ValueError('cohort scope')
        if s['dependency_role'] not in ('SIGNAL','CONTEXT'): raise ValueError('dependency role')
        if not s['field'] or not s['unit'] or not s['dependency_key']: raise ValueError('source metadata')
        catalog=field_catalog.get(s['field'])
        if not catalog or catalog['unit']!=s['unit'] or catalog['scope']!=s['scope']:
            raise ValueError('unknown field, unit or scope')
        if v is None:
            if not catalog['missing_allowed']: raise ValueError('missing required source')
        else:
            actual='number' if finite_number(v) else 'boolean' if type(v) is bool else 'string'
            if actual!=catalog['value_type']: raise ValueError('field value type')
            if actual=='number':
                if catalog['minimum'] is not None and v<catalog['minimum']: raise ValueError('field minimum')
                if catalog['maximum'] is not None and v>catalog['maximum']: raise ValueError('field maximum')
        offset=s['slot_offset']
        if offset is not None and (not exact_int(offset) or offset not in (21,18,15,12,9,6)):
            raise ValueError('slot')
        if offset is not None and timestamp(s['observed_at'])>cutoff-timedelta(minutes=offset-6):
            raise ValueError('future slot observation')
        if s['source_kind']=='OBSERVED':
            if s['parents'] or s['formula_id'] is not None or s['formula_version'] is not None or s['computed_at'] is not None:
                raise ValueError('observed derivation')
            key=(s['scope'],tuple(sorted(owners)),s['field'],s['unit'],offset,timestamp(s['observed_at']))
            if key in seen: raise ValueError('duplicate canonical source')
            seen.add(key)
        elif s['source_kind']=='DERIVED':
            if not s['parents'] or not s['formula_id'] or not s['formula_version']: raise ValueError('missing derivation')
            if len(set(s['parents']))!=len(s['parents']): raise ValueError('duplicate parent')
            if timestamp(s['computed_at'])<timestamp(s['available_at']): raise ValueError('computed before data')
            if timestamp(s['computed_at'])>request_at: raise ValueError('future computation')
        else: raise ValueError('source kind')
    for ref in topological_refs(registry):
        s=registry[ref]
        for p in s['parents']:
            parent=registry[p]
            if timestamp(parent['observed_at'])>timestamp(s['observed_at']):
                raise ValueError('derived observation predates parent')
            if timestamp(parent['available_at'])>timestamp(s['available_at']):
                raise ValueError('derived availability')
            if parent['source_kind']=='DERIVED' and timestamp(parent['computed_at'])>timestamp(s['computed_at']):
                raise ValueError('computed before parent')

def topological_refs(registry):
    if len(registry)>20000: raise ValueError('source count limit')
    indegree={};children={k:[] for k in registry}
    for ref,s in registry.items():
        parents=s['parents']
        if not isinstance(parents,list) or any(not isinstance(p,str) or p not in registry for p in parents):
            raise ValueError('missing parent')
        if len(set(parents))!=len(parents): raise ValueError('duplicate parent')
        indegree[ref]=len(parents)
        for p in parents: children[p].append(ref)
    queue=deque(k for k,v in indegree.items() if v==0);order=[]
    while queue:
        ref=queue.popleft();order.append(ref)
        for child in children[ref]:
            indegree[child]-=1
            if indegree[child]==0: queue.append(child)
    if len(order)!=len(registry): raise ValueError('cyclic parent')
    return order

def source_roots(ref,registry):
    if ref not in registry: raise ValueError('unknown source')
    # A derived CONTEXT reference does not become a SIGNAL merely because
    # its ancestors include signals. SIGNAL derivations retain their lineage.
    if registry[ref]['dependency_role']!='SIGNAL': return set()
    roots=set();seen=set();stack=[ref]
    # registry graph is validated once before evidence processing.
    while stack:
        key=stack.pop()
        if key in seen: continue
        seen.add(key);s=registry[key]
        if s['parents']: stack.extend(s['parents'])
        elif s['dependency_role']=='SIGNAL': roots.add(s['dependency_key'])
    return roots

def verified_evidence(evidence,horse_no,registry):
    if not isinstance(evidence,list): return [],['evidence_not_array']
    valid=[]; issues=[]
    counts=Counter(e.get('evidence_id') for e in evidence if isinstance(e,dict) and isinstance(e.get('evidence_id'),str))
    for e in evidence:
        try:
            if not isinstance(e,dict) or not isinstance(e.get('evidence_id'),str) or not e['evidence_id']:
                raise ValueError('invalid evidence id')
            if counts[e['evidence_id']]!=1: raise ValueError('duplicate evidence id')
            if e['family'] not in FAMILIES or not e['interpretation'].strip(): raise ValueError('evidence fields')
            refs=e['source_refs']; facts=e['facts']
            if (not isinstance(refs,list) or any(not isinstance(x,str) or not x for x in refs)
                    or not isinstance(facts,list) or any(not isinstance(f,dict) for f in facts)):
                raise ValueError('evidence container type')
            if not refs or len(set(refs))!=len(refs): raise ValueError('refs')
            if len(facts)!=len(refs) or {f['source_ref'] for f in facts}!=set(refs): raise ValueError('facts bijection')
            if len({f['source_ref'] for f in facts})!=len(facts): raise ValueError('duplicate fact')
            roots=set(); known_signal=False; individual_signal=False
            for fact in facts:
                s=registry[fact['source_ref']]
                if s['scope'] in ('HORSE','PAIR') and horse_no not in s['horse_nos']:
                    raise ValueError('wrong horse or pair')
                if s['scope']=='COHORT' and horse_no not in s['applicable_horse_nos']:
                    raise ValueError('wrong cohort')
                if not same_scalar(fact['value'],s['value']): raise ValueError('wrong fact value/type')
                fact_roots=source_roots(fact['source_ref'],registry)
                roots |= fact_roots
                known_signal |= bool(fact_roots) and s['value'] is not None
                individual_signal |= (bool(fact_roots) and s['value'] is not None
                    and s['scope'] in ('HORSE','PAIR','COHORT'))
            if not roots: raise ValueError('context-only evidence')
            if not known_signal: raise ValueError('missing-only evidence')
            if not individual_signal: raise ValueError('race-only evidence')
            valid.append(dict(e,verified_roots=sorted(roots)))
        except (KeyError,TypeError,ValueError,AttributeError) as exc:
            issues.append(str(exc))
    return valid,issues

def independent_count(evidence):
    # Same family or overlapping lineage counts as one connected component.
    components=[]
    for e in evidence:
        roots=set(e['verified_roots']); families={e['family']}
        merged=[]
        for c_roots,c_families in components:
            if roots & c_roots or families & c_families:
                roots |= c_roots; families |= c_families
            else: merged.append((c_roots,c_families))
        # Transitive merge can meet a component scanned earlier.
        again=True
        while again:
            again=False; rest=[]
            for c_roots,c_families in merged:
                if roots & c_roots or families & c_families:
                    roots |= c_roots; families |= c_families; again=True
                else: rest.append((c_roots,c_families))
            merged=rest
        components=merged+[(roots,families)]
    return len(components)

def recover_scores(scores,score_evidence,registry,horse_no):
    scores=scores if isinstance(scores,dict) else {}
    score_evidence=score_evidence if isinstance(score_evidence,dict) else {}
    recovered={}; issues=[]
    for key,cap in CAPS.items():
        value=scores.get(key)
        se=score_evidence.get(key,{})
        refs=se.get('source_refs',[]) if isinstance(se,dict) else []
        if (not isinstance(refs,list) or not all(isinstance(r,str) for r in refs)
                or len(refs)!=len(set(refs)) or not isinstance(se.get('reason') if isinstance(se,dict) else None,str)
                or not se.get('reason','').strip()): refs=[]
        known=(exact_int(value) and 0<=value<=cap and bool(refs) and all(
            r in registry and (registry[r]['scope'] not in ('HORSE','PAIR') or horse_no in registry[r]['horse_nos'])
            and (registry[r]['scope']!='COHORT' or horse_no in registry[r]['applicable_horse_nos'])
            for r in refs) and any(registry[r]['value'] is not None and source_roots(r,registry) for r in refs))
        recovered[key]=value if known else None
        if value is not None and not known: issues.append(key)
    total=sum(v for v in recovered.values() if v is not None)
    cap=sum(CAPS[k] for k,v in recovered.items() if v is not None)
    complete=all(v is not None for v in recovered.values())
    return dict(scores=recovered,score_status='complete' if complete else 'partial',
                total_score=total if complete else None,observed_sum=total,observed_cap=cap),issues

def recover_rank(rows,primary_order,backup_order,backup_valid,original_basis='SCORE'):
    eligible=[r for r in rows if r['evidence']]
    numbers={r['horse_no'] for r in eligible}
    if not eligible: return [],'EMPTY'
    backup_valid=(backup_valid is True and isinstance(backup_order,list)
        and all(exact_int(x) and x>0 for x in backup_order)
        and len(backup_order)==len(set(backup_order)) and numbers<=set(backup_order))
    if original_basis=='EVIDENCE' and backup_valid:
        return [n for n in backup_order if n in numbers],'EVIDENCE_BACKUP'
    if all(r['score_status']=='complete' for r in eligible):
        order=sorted(eligible,key=lambda r:(-r['total_score'],
            -sum(r['scores'][k] for k in CAPS if k.startswith('V')),r['horse_no']))
        return [r['horse_no'] for r in order],'SCORE'
    # backup_valid must be independently checked against effective evidence and provenance.
    if backup_valid:
        return [n for n in backup_order if n in numbers],'EVIDENCE_BACKUP'
    order=sorted(eligible,key=lambda r:(-independent_count(r['evidence']),r['horse_no']))
    return [r['horse_no'] for r in order],'VERIFIED_EVIDENCE_FALLBACK'

def partition_rows(raw_rows,runners):
    """Isolate absent, duplicate, unknown, and malformed identities."""
    grouped={h['horse_no']:[] for h in runners}; issues=[]
    for row in raw_rows:
        if not isinstance(row,dict) or not exact_int(row.get('horse_no')):
            issues.append('INVALID_HORSE_ID'); continue
        no=row['horse_no']
        if no not in grouped: issues.append('UNKNOWN_HORSE_ID'); continue
        grouped[no].append(row)
    accepted={}; unavailable=[]
    for no,rows in grouped.items():
        if len(rows)==1: accepted[no]=rows[0]
        else:
            unavailable.append(no)
            issues.append(('MISSING_HORSE' if not rows else 'DUPLICATE_HORSE',no))
    return accepted,unavailable,issues

def keep_correction(initial,corrected,repair_horses,envelope_valid,runner_numbers=None):
    """Only validated views; frozen runner IDs allow missing-row rescue."""
    result=copy.deepcopy(initial);log=[]
    if envelope_valid is not True: return result,[dict(action='CORRECTION_REJECTED_ENVELOPE')]
    known=set(initial) if runner_numbers is None else set(runner_numbers)
    for no,row in corrected.items():
        if not exact_int(no) or no not in known:
            log.append(dict(action='UNKNOWN_CORRECTION_HORSE'));continue
        if no not in repair_horses:
            def content(x):return {k:v for k,v in x.items() if k not in ('response_hash','field_sources')} if isinstance(x,dict) else x
            if content(row)!=content(initial.get(no)): log.append(dict(horse_no=no,action='OUT_OF_SCOPE_CHANGE_IGNORED'))
            continue
        if not isinstance(row,dict) or row.get('row_valid') is not True or row.get('horse_no')!=no:
            log.append(dict(horse_no=no,action='CORRECTION_INVALID_RETAIN_INITIAL'));continue
        old=initial.get(no)
        if old and old['evidence'] and not row['evidence']:
            log.append(dict(horse_no=no,action='CORRECTION_REGRESSION_RETAIN_INITIAL'));continue
        if old and old['score_status']=='complete' and row['score_status']!='complete':
            log.append(dict(horse_no=no,action='CORRECTION_SCORE_REGRESSION_RETAIN_INITIAL'));continue
        result[no]=copy.deepcopy(row)
        log.append(dict(horse_no=no,action='CORRECTION_ADOPTED',source=row.get('response_hash')))
    return result,log

def merge_orders(runners,orders,views,valid_public=None):
    valid_public=valid_public or {}
    output={b:[] for b in BANDS}; scores={}
    capacities={b:sum(h['band']==b for h in runners) for b in BANDS}
    horse_map={h['horse_no']:h for h in runners}
    for role,order in orders.items():
        if role not in ('1st','2nd') or not isinstance(order,dict) or set(order)!=set(BANDS):
            raise ValueError('bad merge role or bands')
        flat=[]
        for b in BANDS:
            if not isinstance(order[b],list): raise ValueError('bad merge order')
            for no in order[b]:
                if not exact_int(no) or no not in horse_map or horse_map[no]['band']!=b:
                    raise ValueError('bad merge identity or band')
                flat.append(no)
        if len(flat)!=len(set(flat)): raise ValueError('duplicate merge vote')
    for b in BANDS:
        candidates=set().union(*(set(v.get(b,[])) for v in orders.values()))
        for no in candidates:
            if horse_map[no]['band']!=b: raise ValueError('wrong merge band')
            votes=[]; evidence=[]
            for role,order in orders.items():
                if no in order.get(b,[]):
                    if not views[role][no]['evidence']: raise ValueError('unsupported vote')
                    r=order[b].index(no)+1
                    votes.append(100*(capacities[b]-r+1)/capacities[b])
                    evidence.extend(views[role][no]['evidence'])
            both=all(no in valid_public.get(role,set()) and no in orders.get(role,{}).get(b,[]) for role in ('1st','2nd'))
            scores[no]=(max(votes)+(5 if both else 0),both,independent_count(evidence))
        output[b]=sorted(candidates,key=lambda no:(-scores[no][0],-scores[no][1],-scores[no][2],no))
    return output,scores

def select_final(quota,orders):
    if quota is None: return [],[],{b:0 for b in BANDS}
    if len(set(n for b in BANDS for n in orders[b]))!=sum(len(orders[b]) for b in BANDS):
        raise ValueError('duplicate across bands')
    used=set(); result=[]; assignments=[]
    missing={}
    for b in BANDS:
        chosen=orders[b][:quota[b]]
        for no in chosen:
            result.append(no); used.add(no); assignments.append((b,b,no))
        missing[b]=quota[b]-len(chosen)
    for b in ('D','B','A'):
        for donor in ELIGIBLE_FILL[b]:
            for no in orders[donor]:
                if missing[b]==0: break
                if no not in used:
                    result.append(no); used.add(no); assignments.append((b,donor,no)); missing[b]-=1
    actual={b:sum(a[1]==b for a in assignments) for b in BANDS}
    assert actual['A']<=quota['A'] and len(result)<=sum(quota.values())
    return result,assignments,actual

def cancellation_view(runners,cancelled,mode):
    validate_runners(runners)
    if not isinstance(cancelled,list): raise ValueError('cancellation array required')
    valid_numbers={r['horse_no'] for r in runners}
    if any(not exact_int(no) for no in cancelled) or not set(cancelled)<=valid_numbers: raise ValueError('unknown cancellation')
    active=[copy.deepcopy(h) for h in runners if h['horse_no'] not in cancelled]
    base,quota,moves=structural_quota(active,mode)
    return dict(active_runners=active,active_count=len(active),publication_target_count=target_count(len(active)),
                publication_base_quota=base,publication_quota=quota,quota_adjustments=moves)

def publication_deadline(publish_deadline,live_close,safety_margin_ms):
    if not exact_int(safety_margin_ms) or safety_margin_ms<0: raise ValueError('safety margin')
    try:return min(timestamp(publish_deadline),timestamp(live_close)-timedelta(milliseconds=safety_margin_ms))
    except OverflowError as exc:raise ValueError('publication deadline outside supported datetime range') from exc

def can_publish(*,now,received_at,reply_deadline,publish_deadline,live_close,
                snapshot_hash,current_snapshot_hash,state_revision,current_state_revision,verified_at,
                safety_margin_ms=0):
    # Fail closed for malformed identity or temporal contracts.
    if (not isinstance(snapshot_hash,str) or not snapshot_hash
            or snapshot_hash!=current_snapshot_hash
            or not exact_int(state_revision) or not exact_int(current_state_revision)
            or state_revision<0 or state_revision!=current_state_revision): return False
    try:
        effective_deadline=publication_deadline(publish_deadline,live_close,safety_margin_ms)
        now,received,reply,publish,close,verified=map(timestamp,
            (now,received_at,reply_deadline,publish_deadline,live_close,verified_at))
    except (TypeError,ValueError,OverflowError): return False
    return (received<=reply<=publish and received<=verified<=now<=effective_deadline and now<close)

def verified_explanation(row,registry,reason='VERIFIED_FACTS'):
    # Fact-only template: do not display unchecked AI prose or causal interpretations.
    def value_type(value):
        return 'number' if finite_number(value) else 'null' if value is None else 'boolean' if type(value) is bool else 'string'
    facts=[]; seen=set()
    for e in row['evidence']:
        for ref in e['source_refs']:
            if ref in seen: continue
            seen.add(ref); s=registry[ref]
            facts.append(dict(source_ref=ref,field=s['field'],value=s['value'],value_type=value_type(s['value']),unit=s['unit'],
                              observed_at=s['observed_at'],horse_nos=s['horse_nos'],
                              evidence_id=e['evidence_id'],family=e['family'],response_hash=row.get('response_hash')))
    counterfacts=[]; seen_counter=set()
    for e in (row.get('counterevidence') if isinstance(row.get('counterevidence'),list) else []):
        if not isinstance(e,dict) or not isinstance(e.get('source_refs'),list): continue
        for ref in e['source_refs']:
            if not isinstance(ref,str) or ref not in registry or ref in seen_counter: continue
            s=registry[ref]
            if s['scope'] in ('HORSE','PAIR') and row['horse_no'] not in s['horse_nos']: continue
            if s['scope']=='COHORT' and row['horse_no'] not in s['applicable_horse_nos']: continue
            seen_counter.add(ref)
            counterfacts.append(dict(source_ref=ref,field=s['field'],value=s['value'],value_type=value_type(s['value']),unit=s['unit'],
                                     observed_at=s['observed_at'],horse_nos=s['horse_nos']))
    return dict(status='VERIFIED_FACTS_ONLY' if facts else 'EXPLANATION_PENDING',
                facts=facts,counterfacts=counterfacts,rank_recovery_reason=reason)
