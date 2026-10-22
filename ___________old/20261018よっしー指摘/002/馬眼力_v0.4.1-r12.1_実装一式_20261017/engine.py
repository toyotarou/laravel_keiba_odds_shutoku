"""r8 executable boundary: prepared input -> validated AI views -> publication plan.
No model calls, no odds rewriting, no legacy filters. Keep original bytes outside this module.
"""
from __future__ import annotations
import copy,json,hashlib,re
from collections import Counter
from pathlib import Path
from decimal import Decimal,localcontext,ROUND_HALF_UP
from fractions import Fraction
from datetime import timedelta
from jsonschema import Draft202012Validator,FormatChecker
import reference as r

ROOT=Path(__file__).resolve().parent
CONFIG=json.loads((ROOT/'config.json').read_text())
SCHEMAS={n:json.loads((ROOT/(n+'_schema.json')).read_text()) for n in ('input','output','source_registry')}
CHECKER=FormatChecker()
if 'date-time' not in CHECKER.checkers: raise RuntimeError('rfc3339-validator required')
for s in SCHEMAS.values(): Draft202012Validator.check_schema(s)
VALIDATORS={n:Draft202012Validator(s,format_checker=CHECKER) for n,s in SCHEMAS.items()}
HORSE=Draft202012Validator(SCHEMAS['output']['properties']['horses']['items'],format_checker=CHECKER)
EVIDENCE=Draft202012Validator(SCHEMAS['output']['properties']['horses']['items']['properties']['evidence']['items'])
TOP_KEYS=('schema_version','prompt_version','ai_role','race_id','snapshot_hash','selection_enabled','mode','target_count','role_cap','quota','role_quota','learning_stage')
ROLE_KEYS=('ai_role','role_cap','role_quota')
SUMMARY_FIELDS=('band','tier','eligibility','score_status','total_score','observed_sum','observed_cap','family_review')
BEGIN='<<<BAGANRIKI_V041_R8_BEGIN>>>';END='<<<BAGANRIKI_V041_R8_END>>>'

class ContractError(ValueError): pass

def require(ok,msg):
    if not ok: raise ContractError(msg)

def formula_check(registry):
    for key in r.topological_refs(registry):
        s=registry[key]
        if s['source_kind']!='DERIVED': continue
        parents=[registry[x] for x in s['parents']]
        if s['scope'] in ('HORSE','PAIR'):
            require(all(p['scope'] in ('HORSE','PAIR') and set(p['horse_nos'])<=set(s['horse_nos']) for p in parents),'derived ownership')
        else:
            raise ContractError('aggregate formulas require separately approved implementation')
        values=[p['value'] for p in parents]
        fid=s['formula_id'];require(s['formula_version']=='1','unknown formula version')
        require(fid in ('identity','ratio_12dp','shortening_12dp'),'unknown formula')
        if fid=='identity':
            require(len(values)==1,'identity arity');expected=values[0]
        else:
            require(len(values)==2,'ratio arity')
            if any(v is None for v in values): expected=None
            else:
                require(all(r.finite_number(v) and v>0 for v in values),'formula inputs')
                with localcontext() as ctx:
                    ctx.prec=160
                    a,b=(Decimal(str(v)) for v in values)
                    expected=(b/a if fid=='ratio_12dp' else (a-b)/a).quantize(Decimal('0.000000000001'),rounding=ROUND_HALF_UP)
        require(r.same_scalar(s['value'],expected),'formula value mismatch')

def index_value(inp,key,horse_no=None,field=None,slot=None):
    refs=inp['input_indices'].get(key,[])
    require(len(refs)<=1,'scalar index cardinality: '+key)
    if not refs:return None
    s=inp['source_registry'][refs[0]]
    if horse_no is not None:
        require(s['scope']=='HORSE' and s['horse_nos']==[horse_no],'index owner: '+key)
    if field is not None:require(s['field']==field,'index field: '+key)
    if slot is not None:require(s['slot_offset']==slot,'index slot: '+key)
    return s['value']

def derive_signals(inp):
    """Compute fixed-pair ratios from raw decimal odds; never from rounded stored W/P."""
    ordered=sorted(inp['runners'],key=lambda h:h['win_rank']);pairs={};horses=[]
    def value(no,field,t):
        v=index_value(inp,f'horse/{no}/{field}/{t}',no,field,t)
        require(v is None or (r.finite_number(v) and v>0),'invalid odds')
        return v
    odds={}
    for h in ordered:
        no=h['horse_no'];odds[no]={}
        for t in (21,18,15,12,9,6):
            vals={f:value(no,f,t) for f in ('win_odds','place_min','place_max')}
            if vals['place_min'] is not None and vals['place_max'] is not None:
                require(vals['place_min']<=vals['place_max'],'place min exceeds max')
            odds[no][t]=vals
        horses.append(dict(win_rank=h['win_rank'],**{f'P{t}':odds[no][t]['place_min'] for t in (21,9,6)}))
    known_win_odds=[odds[h['horse_no']][6]['win_odds'] for h in ordered if odds[h['horse_no']][6]['win_odds'] is not None]
    require(all(a<=b for a,b in zip(known_win_odds,known_win_odds[1:])),'frozen win_rank contradicts 6-minute win odds')
    def ratio(a,b):
        return None if a is None or b is None else Fraction(str(b))/Fraction(str(a))
    for a,b in zip(ordered,ordered[1:]):
        oa,ob=odds[a['horse_no']],odds[b['horse_no']]
        pairs[a['win_rank']]={f'W{t}':ratio(oa[t]['win_odds'],ob[t]['win_odds']) for t in (21,6)}
        pairs[a['win_rank']]['P6']=ratio(oa[6]['place_min'],ob[6]['place_min'])
    # X6 is unknown until its historical-data adapter and definition are supplied.
    # r8 deliberately does not trust a generic "definition_valid=true" in an input JSON.
    return r.extract_signals(len(ordered),pairs,horses,None)

def common_payload(inp):return {k:v for k,v in inp.items() if k not in (*ROLE_KEYS,'snapshot_hash')}

def validate_input(inp,request_at):
    try:r.checked_json(inp)
    except ValueError as exc:raise ContractError('input validation: '+str(exc)) from exc
    VALIDATORS['input'].validate(inp)
    require(inp['learning_stage']=='COLLECT_ONLY' and inp['model_version'] is None,'r8 supports COLLECT_ONLY only')
    r.validate_runners(inp['runners']);require(r.exact_int(inp['N']) and inp['N']==len(inp['runners']),'N mismatch')
    tc=inp['time_contract'];cutoff=r.timestamp(inp['cutoff_at']);request=r.timestamp(request_at)
    require(cutoff==r.timestamp(tc['anchor_at'])-timedelta(minutes=6),'cutoff anchor')
    if tc['anchor_kind']=='SALES_CLOSE_TIME':
        require(r.timestamp(tc['anchor_at'])==r.timestamp(tc['sales_close_at']),'sales close anchor mismatch')
    reply,publish,close=map(r.timestamp,(tc['reply_deadline'],tc['publish_deadline'],tc['sales_close_at']))
    require(cutoff<=request<=reply<=publish<close,'time contract')
    margin=tc['safety_margin_ms'];require(r.exact_int(margin) and margin>=0,'margin type')
    require((close-publish)>=timedelta(milliseconds=margin),'publication safety margin')
    r.validate_registry(inp['source_registry'],inp['race_id'],inp['cutoff_at'],[h['horse_no'] for h in inp['runners']],inp['field_catalog'],request_at)
    r.validate_coverage(inp['coverage'],inp['source_registry'])
    formula_check(inp['source_registry'])
    for key,refs in inp['input_indices'].items():
        require(len(refs)==len(set(refs)) and all(x in inp['source_registry'] for x in refs),'invalid index refs: '+key)
    signals=derive_signals(inp);mode=r.signal_mode(signals)
    require(inp['signals']==dict(zip(('X1','X2','X3','X4','X5','X6','Z'),signals)),'signal mismatch')
    for k,v in mode.items():require(inp[k]==v and (v is None or type(inp[k]) is type(v)),k+' mismatch')
    base,quota,moves=r.structural_quota(inp['runners'],mode['mode']);c=r.target_count(inp['N'])
    cap,rq=r.role_quota(quota,c,inp['ai_role'])
    for k,v in dict(base_quota=base,quota=quota,quota_adjustments=moves,target_count=c,role_cap=cap,role_quota=rq,selection_enabled=quota is not None).items():
        require(r.json_dumps(inp[k])==r.json_dumps(v),k+' mismatch')
    require(inp['snapshot_hash']==r.canonical_hash(common_payload(inp)),'snapshot hash mismatch')
    return inp

def prepare(seed,request_at):
    """Seed is a server-authored DTO; computed fields in it are ignored and regenerated."""
    required=('race_id','cutoff_at','time_contract','runners','source_registry','field_catalog','input_indices','coverage')
    require(isinstance(seed,dict) and all(k in seed for k in required),'snapshot seed fields')
    r.checked_json(seed)  # native callers must obey the transport contract too
    require(seed.get('learning_stage','COLLECT_ONLY')=='COLLECT_ONLY','unsupported learning stage')
    inp={k:copy.deepcopy(seed[k]) for k in required}
    inp.update(schema_version=CONFIG['schema_version'],prompt_version=CONFIG['prompt_version'],
        snapshot_hash_version='exact-common-payload-sha256-r8',N=len(inp['runners']),
        legacy_gap_type=seed.get('legacy_gap_type'),learning_stage='COLLECT_ONLY',model_version=None)
    r.validate_runners(inp['runners'])
    # Validate identity/time metadata before calculating any signs.
    r.validate_registry(inp['source_registry'],inp['race_id'],inp['cutoff_at'],[h['horse_no'] for h in inp['runners']],inp['field_catalog'],request_at)
    for refs in inp['input_indices'].values():require(isinstance(refs,list) and all(x in inp['source_registry'] for x in refs),'index refs')
    signals=derive_signals(inp);inp['signals']=dict(zip(('X1','X2','X3','X4','X5','X6','Z'),signals));inp.update(r.signal_mode(signals))
    inp['target_count']=r.target_count(inp['N'])
    inp['base_quota'],inp['quota'],inp['quota_adjustments']=r.structural_quota(inp['runners'],inp['mode'])
    inp['selection_enabled']=inp['quota'] is not None;inp['snapshot_hash']=r.canonical_hash(inp)
    roles={}
    for role in ('1st','2nd'):
        x=copy.deepcopy(inp);x['ai_role']=role;x['role_cap'],x['role_quota']=r.role_quota(x['quota'],x['target_count'],role)
        validate_input(x,request_at);roles[role]=x
    return dict(common_json=r.json_dumps(common_payload(inp)),snapshot_hash=inp['snapshot_hash'],inputs=roles,
        input_json={role:r.json_dumps(value) for role,value in roles.items()},
        prompts={role:(ROOT/f'prompt_{role}.txt').read_text() for role in roles})

def parse_response(text):
    require(isinstance(text,str) and len(text.encode('utf-8'))<=4*1024*1024,'response size/type')
    match=re.fullmatch(re.escape(BEGIN)+r'\s*([\s\S]+?)\s*'+re.escape(END),text.strip())
    require(match is not None,'response markers')
    obj=r.strict_loads(match.group(1));require(isinstance(obj,dict),'response object');return obj

def response_view(text,inp):
    obj=parse_response(text)
    for key in TOP_KEYS:require(key in obj and r.json_dumps(obj[key])==r.json_dumps(inp[key]),'envelope '+key)
    require(isinstance(obj.get('horses'),list),'horses array')
    accepted,missing,issues=r.partition_rows(obj['horses'],inp['runners']);views={}
    digest=hashlib.sha256(text.encode('utf-8')).hexdigest();horse_map={h['horse_no']:h for h in inp['runners']}
    for no,raw in accepted.items():
        raw_ev=raw.get('evidence',[]);raw_ev=raw_ev if isinstance(raw_ev,list) else []
        typed=[e for e in raw_ev if not list(EVIDENCE.iter_errors(e))]
        ev,ev_issues=r.verified_evidence(typed,no,inp['source_registry'])
        # Keep duplicate-id rejection across the whole original list, including malformed duplicates.
        counts=Counter(q.get('evidence_id') for q in raw_ev if isinstance(q,dict) and isinstance(q.get('evidence_id'),str))
        ev=[e for e in ev if counts[e['evidence_id']]==1]
        counter,counter_issues=r.verified_counterevidence(raw.get('counterevidence'),no,inp['source_registry'])
        review_errors=r.review_issues(raw.get('family_review'),ev,no,inp['source_registry'])
        no_support=(not ev and not review_errors and all(x['status']=='NO_SUPPORT' for x in raw['family_review'].values()))
        score,score_issues=r.recover_scores(raw.get('scores'),raw.get('score_evidence'),inp['source_registry'],no)
        errors=list(HORSE.iter_errors(raw))
        evaluation_errors=[x for x in errors if not x.absolute_path or x.absolute_path[0] not in (*SUMMARY_FIELDS,*PRESENTATION_FIELDS)]
        views[no]=dict(raw,**score)
        views[no].update(horse_no=no,band=horse_map[no]['band'],evidence=ev,counterevidence=counter,response_hash=digest,
            rank_inputs_valid=not counter_issues and len(ev)==len(raw_ev),
            row_valid=not errors,eligibility='ELIGIBLE' if ev else 'NO_EVIDENCE' if no_support else 'UNVERIFIED',
            tier='A' if r.independent_count(ev)>=2 else 'B' if ev else 'C' if no_support else 'UNVERIFIED')
        if evaluation_errors or ev_issues or score_issues or len(typed)!=len(raw_ev):issues.append(dict(horse_no=no,code='LOCAL_RECOVERY'))
        if counter_issues:issues.append(dict(horse_no=no,code='COUNTEREVIDENCE_INVALID'))
    return dict(raw=obj,views=views,missing=missing,issues=issues,hash=digest,full_schema_valid=not list(VALIDATORS['output'].iter_errors(obj)))

# Response rows, band rank claims and public declarations retain separate provenance.
RANK_FIELDS=('rank_basis','rank_reason','evidence_rank_evidence_ids','evidence_rank_reason')
PRESENTATION_FIELDS=('public_candidate','nonselect_reason')

def row_payload(row):
    return {k:v for k,v in row.items() if k not in ('response_hash','row_valid','field_sources','rank_inputs_valid')}

def band_payload(views,b):
    # Backup evidence order must be independent of scores and non-eligible rows.
    return {no:{k:h.get(k) for k in ('horse_no','band','evidence','counterevidence')}
        for no,h in views.items() if h['band']==b and h['evidence']}

def rank_dependencies(views,b):
    rows={no:h for no,h in views.items() if h['band']==b and h['evidence']}
    complete=bool(rows) and all(h['score_status']=='complete' for h in rows.values())
    return dict(basis='SCORE' if complete else 'EVIDENCE',evidence=band_payload(views,b),
        scores={no:h['scores'] for no,h in rows.items()} if complete else None)

def source_claim(view):
    return {k:copy.deepcopy(view[k]) for k in ('raw','views','hash','full_schema_valid','issues','missing')}

def rank_claim(view,b):
    raw=view['raw']
    def order(key):
        data=raw.get(key)
        return copy.deepcopy(data.get(b,[])) if isinstance(data,dict) else []
    return dict(source_hash=view['hash'],primary=order('band_ranks'),backup=order('evidence_band_ranks'),
        invalid_horses=[no for no,h in view['views'].items() if h['band']==b and not h.get('rank_inputs_valid',True)],
        rows=band_payload(view['views'],b),proofs={no:{k:copy.deepcopy(h.get(k)) for k in RANK_FIELDS}
            for no,h in view['views'].items() if h['band']==b})

def rank_band(views,claim,inp,b):
    rows=[h for h in views.values() if h['band']==b]
    eligible={h['horse_no'] for h in rows if h['evidence']}
    backup=claim['backup'];primary=claim['primary']
    valid=(claim['rows']==band_payload(views,b) and isinstance(backup,list)
        and all(r.exact_int(n) for n in backup) and len(backup)==len(set(backup)))
    frozen={h['horse_no']:h['band'] for h in inp['runners']}
    valid=valid and eligible<=set(backup) and all(n in frozen and frozen[n]==b for n in backup)
    if any(claim['proofs'].get(no,{}).get('rank_basis')=='EVIDENCE' for no in eligible):
        valid=valid and primary==backup
    for no in eligible:
        proof=claim['proofs'].get(no,{})
        ids=proof.get('evidence_rank_evidence_ids');reason=proof.get('evidence_rank_reason')
        actual={ev['evidence_id'] for ev in views[no]['evidence']}
        valid=valid and no not in claim.get('invalid_horses',[]) and isinstance(ids,list) and bool(ids) and all(isinstance(x,str) for x in ids)
        if valid:valid=len(ids)==len(set(ids)) and set(ids)<=actual and isinstance(reason,str) and bool(reason.strip())
    # The server's recovered score status decides the mode. A model declaration never overrides it.
    order,method=r.recover_rank(rows,primary,backup,valid,'SCORE')
    return order,method,valid

def expected_public(inp,orders):
    q=inp['role_quota']
    return [] if q is None else [n for b in r.BANDS for n in orders[b][:q[b]]]

def diagnose(view,inp,orders,methods):
    raw=view['raw'];views=view['views'];errors=[]
    horses=set(view['missing']);bands=set();presentation=set();summary=set();top=set();public_repair=False
    def issue(code,**fields):errors.append(dict(code=code,**fields))
    for no in view['missing']:issue('MISSING_OR_DUPLICATE_HORSE',horse_no=no)
    for old in view['issues']:
        if isinstance(old,dict):
            errors.append(old)
            if r.exact_int(old.get('horse_no')):horses.add(old['horse_no'])
        elif isinstance(old,(list,tuple)) and len(old)==2:
            issue(str(old[0]),horse_no=old[1]);horses.add(old[1])
        else:issue(str(old));top.add('horses')
    declared={x['horse_no']:x for x in raw['horses'] if isinstance(x,dict) and r.exact_int(x.get('horse_no'))}
    for no,h in views.items():
        original=declared.get(no,{})
        row_errors=list(HORSE.iter_errors(original))
        if row_errors:
            issue('ROW_SCHEMA_INVALID',horse_no=no)
            for error in row_errors:
                field=error.absolute_path[0] if error.absolute_path else None
                if field in PRESENTATION_FIELDS:presentation.add(no)
                elif field in SUMMARY_FIELDS:summary.add(no)
                else:horses.add(no)
        for key in ('band','tier','eligibility','score_status','total_score','observed_sum','observed_cap'):
            if key not in original or r.json_dumps(original[key])!=r.json_dumps(h[key]):
                summary.add(no);issue('DERIVED_FIELD_MISMATCH',horse_no=no,field=key,expected=h[key])
        review_errors=r.review_issues(h.get('family_review'),h['evidence'],no,inp['source_registry'])
        if review_errors:
            summary.add(no);issue('FAMILY_REVIEW_MISMATCH',horse_no=no,families=review_errors)
        if not h['evidence'] and (h.get('rank_basis')!='NOT_RANKED' or h.get('evidence_rank_evidence_ids')!=[]):
            summary.add(no);issue('UNRANKED_METADATA_MISMATCH',horse_no=no)
    for b in r.BANDS:
        rows=[h for h in views.values() if h['band']==b and h['evidence']]
        basis='SCORE' if rows and all(h['score_status']=='complete' for h in rows) else 'EVIDENCE'
        for h in rows:
            if h.get('rank_basis')!=basis:
                bands.add(b);issue('RANK_BASIS_MISMATCH',horse_no=h['horse_no'],band=b,expected=basis)
        supplied=raw.get('band_ranks')
        if not isinstance(supplied,dict) or supplied.get(b)!=orders[b]:
            bands.add(b);issue('RANK_ORDER_MISMATCH',band=b,expected=orders[b])
        _,_,backup_valid=rank_band(views,rank_claim(view,b),inp,b)
        if methods[b]=='VERIFIED_EVIDENCE_FALLBACK' or (rows and not backup_valid):
            bands.add(b);issue('RANK_PROOF_INVALID',band=b)
    expected=expected_public(inp,orders)
    if raw.get('public_candidates')!=expected:
        public_repair=True;issue('PUBLIC_LIST_MISMATCH',expected=expected)
    for no,h in views.items():
        chosen=no in expected
        if h.get('public_candidate') is not chosen:
            presentation.add(no);issue('PUBLIC_FLAG_MISMATCH',horse_no=no,expected=chosen)
        reason=h.get('nonselect_reason')
        if chosen:reason_ok=reason is None
        else:
            allowed={'SELECTION_DISABLED'} if not inp['selection_enabled'] else {'BAND_QUOTA'} if h['evidence'] else {h['eligibility']}
            reason_ok=(isinstance(reason,dict) and isinstance(reason.get('code'),str) and reason['code'] in allowed
                and isinstance(reason.get('reason'),str) and bool(reason['reason'].strip()))
        if not reason_ok:presentation.add(no);issue('NONSELECT_REASON_MISMATCH',horse_no=no)
    for err in VALIDATORS['output'].iter_errors(raw):
        path=list(err.absolute_path)
        if path and path[0]=='horses':continue  # row/identity diagnostics above preserve normal rows
        field=str(path[0]) if path else '$'
        top.add(field);issue('OUTPUT_SCHEMA_INVALID',field=field,reason=err.message[:200])
        if field in ('band_ranks','evidence_band_ranks'):bands.update(r.BANDS)
        if field=='public_candidates':public_repair=True
    expected_shortfalls=[]
    if inp['role_quota'] is not None:
        for b in r.BANDS:
            requested=inp['role_quota'][b];eligible=len(orders[b])
            if eligible<requested:expected_shortfalls.append(dict(band=b,requested=requested,eligible=eligible,missing=requested-eligible))
    supplied_shortfalls=raw.get('shortfalls')
    valid_shortfalls=(isinstance(supplied_shortfalls,list) and len(supplied_shortfalls)==len(expected_shortfalls))
    if valid_shortfalls:
        expected_map={x['band']:x for x in expected_shortfalls};seen=set()
        for item in supplied_shortfalls:
            if (not isinstance(item,dict) or not isinstance(item.get('band'),str) or item['band'] in seen
                    or item['band'] not in expected_map or not isinstance(item.get('reason'),str) or not item['reason'].strip()
                    or any(r.json_dumps(item.get(k))!=r.json_dumps(v) for k,v in expected_map[item['band']].items())):
                valid_shortfalls=False;break
            seen.add(item['band'])
    if not valid_shortfalls:
        top.add('shortfalls');issue('SHORTFALL_MISMATCH',expected=expected_shortfalls)
    # Stable diagnostics: the same defect can be reached by the schema and local validators.
    unique={r.json_dumps(x):x for x in errors}
    scope=dict(horses=sorted(horses),rank_bands=[b for b in r.BANDS if b in bands],
        presentation_horses=sorted(presentation),summary_horses=sorted(summary),public_candidates=public_repair,top_fields=sorted(top))
    return list(unique.values()),scope

def effective_orders(view,inp):
    claims=view.get('rank_claims') or {b:rank_claim(view,b) for b in r.BANDS}
    orders={};methods={};sources={}
    for b in r.BANDS:
        orders[b],methods[b],valid=rank_band(view['views'],claims[b],inp,b)
        sources[b]=dict(method=methods[b],source_hash=claims[b]['source_hash'] if methods[b]=='EVIDENCE_BACKUP' else None)
    expected=expected_public(inp,orders);verified_public=set();public_hash=None
    # A complete original declaration can certify equivalent retained rows; their fact hashes stay unchanged.
    if all(m!='VERIFIED_EVIDENCE_FALLBACK' for m in methods.values()):
        for source in reversed(view.get('public_claims') or [source_claim(view)]):
            if not source['full_schema_valid']:continue
            if set(source['views'])!=set(view['views']) or len(source['views'])!=inp['N']:continue
            if any(row_payload(source['views'][no])!=row_payload(h) for no,h in view['views'].items()):continue
            problems,_=diagnose(source,inp,orders,methods)
            if not problems:
                verified_public=set(expected);public_hash=source['hash'];break
    view['rank_sources']=sources;view['public_proof_hash']=public_hash
    return orders,methods,verified_public

def correction_plan(view,inp):
    orders,methods,_=effective_orders(view,inp)
    errors,scope=diagnose(view,inp,orders,methods)
    return dict(needs_correction=bool(errors),repair_scope=scope,errors=errors)

def apply_correction(initial,corrected,scope,inp):
    kept,events=r.keep_correction(initial['views'],corrected['views'],set(scope['horses']),True,
        [h['horse_no'] for h in inp['runners']])
    changed={b for b in r.BANDS if rank_dependencies(kept,b)!=rank_dependencies(initial['views'],b)}
    rank_bands=set(scope['rank_bands'])|changed
    presentation_all=bool(changed or rank_bands or scope['public_candidates'])
    for no,new in corrected['views'].items():
        if no not in kept or not new['row_valid']:continue
        fields=list(RANK_FIELDS) if kept[no]['band'] in rank_bands else []
        if no in scope.get('summary_horses',[]) and not r.review_issues(new.get('family_review'),kept[no]['evidence'],no,inp['source_registry']):
            fields+=['family_review']
        if no in scope.get('summary_horses',[]) and not kept[no]['evidence']:fields+=list(RANK_FIELDS)
        if presentation_all or no in scope['presentation_horses']:fields+=list(PRESENTATION_FIELDS)
        adopted=[]
        for key in fields:
            if kept[no].get(key)!=new.get(key):
                kept[no][key]=copy.deepcopy(new.get(key));adopted.append(key)
        if adopted:
            kept[no].setdefault('field_sources',{}).update({key:corrected['hash'] for key in adopted})
            events.append(dict(horse_no=no,action='METADATA_CORRECTION_ADOPTED',fields=adopted,source=corrected['hash']))
        if not kept[no]['evidence'] and 'family_review' in adopted:
            reviewed=kept[no]['family_review']
            no_support=(not r.review_issues(reviewed,[],no,inp['source_registry'])
                and all(x['status']=='NO_SUPPORT' for x in reviewed.values()))
            kept[no]['tier']='C' if no_support else 'UNVERIFIED'
            kept[no]['eligibility']='NO_EVIDENCE' if no_support else 'UNVERIFIED'
    old_claims=initial.get('rank_claims') or {b:rank_claim(initial,b) for b in r.BANDS}
    claims={}
    for b in r.BANDS:
        old=old_claims[b];new=rank_claim(corrected,b);claims[b]=old
        if b in rank_bands:
            _,new_method,_=rank_band(kept,new,inp,b)
            _,old_method,_=rank_band(kept,old,inp,b)
            if new['rows']==band_payload(kept,b) and new_method!='VERIFIED_EVIDENCE_FALLBACK':
                claims[b]=new
                events.append(dict(action='BAND_RANK_ADOPTED',band=b,source=new['source_hash']))
            elif old_method!='VERIFIED_EVIDENCE_FALLBACK':
                events.append(dict(action='BAND_RANK_RETAINED',band=b,source=old['source_hash']))
        elif new['primary']!=old['primary'] or new['backup']!=old['backup']:
            events.append(dict(action='OUT_OF_SCOPE_RANK_CHANGE_IGNORED',band=b))
    combined=dict(corrected,views=kept,rank_claims=claims,
        public_claims=(initial.get('public_claims') or [source_claim(initial)])+[source_claim(corrected)],
        missing=sorted({h['horse_no'] for h in inp['runners']}-set(kept)))
    return combined,events

def resolve(inp,responses,cancelled,now):
    """Each role: [{text,received_at}, optional correction]. No extra model calls."""
    # Check frozen input at its valid cutoff (computed sources can be later than cutoff).
    validate_input(inp,min(now,inp['time_contract']['reply_deadline'],key=r.timestamp))
    require(isinstance(responses,dict),'responses object')
    role_orders={};role_views={};public={};audit={};received=[]
    for role in ('1st','2nd'):
        x=copy.deepcopy(inp);x['ai_role']=role;x['role_cap'],x['role_quota']=r.role_quota(x['quota'],x['target_count'],role)
        attempts=responses.get(role,[])
        current=None;repair={};log=[];previous_receipt=None
        if not isinstance(attempts,list) or len(attempts)>2:
            audit[role]=dict(status='AI_FAILURE',corrections=[dict(error='invalid attempts: array with at most two entries required')])
            continue
        for i,a in enumerate(attempts):
            try:
                at=r.timestamp(a['received_at'])
                require(r.timestamp(inp['cutoff_at'])<=at<=r.timestamp(inp['time_contract']['reply_deadline']) and at<=r.timestamp(now),'late/invalid receipt')
                require(previous_receipt is None or at>=previous_receipt,'correction received before initial response')
                previous_receipt=at
                require(a.get('complete',True) is True,'incomplete model response')
                v=response_view(a['text'],x);received.append(a['received_at'])
                if i==0:
                    current=v
                    plan=correction_plan(v,x);repair=plan['repair_scope']
                else:
                    if current is None:
                        current=v;repair=correction_plan(v,x)['repair_scope'];continue
                    current,events=apply_correction(current,v,repair,x)
                    log.extend(events)
            except (ValueError,KeyError,TypeError) as exc:
                log.append(dict(attempt=i,error=str(exc)))
        if current is not None:
            order,methods,pub=effective_orders(current,x);role_orders[role]=order;role_views[role]=current['views'];public[role]=pub
            issues,_=diagnose(current,x,order,methods)
            audit[role]=dict(methods=methods,issues=issues,corrections=log,repair_horses=repair.get('horses',[]),
                repair_scope=repair,rank_sources=current['rank_sources'],public_proof_hash=current['public_proof_hash'])
        else:audit[role]=dict(status='AI_FAILURE',corrections=log)
    overlay=r.cancellation_view(inp['runners'],cancelled,inp['mode'])
    merged,_=r.merge_orders(inp['runners'],role_orders,role_views,public)
    active={h['horse_no'] for h in overlay['active_runners']}
    merged={b:[n for n in merged[b] if n in active] for b in r.BANDS}
    selected,assignments,actual=r.select_final(overlay['publication_quota'],merged)
    flags=[]
    if not active:flags.append('ALL_CANCELLED')
    elif not inp['selection_enabled']:flags.append('SELECTION_DISABLED')
    elif not role_orders:flags.append('AI_FAILURE')
    elif len(role_orders)==1:flags.append('SINGLE_AI')
    if len(selected)<overlay['publication_target_count']:flags.append('PARTIAL_COUNT')
    if any(a!=b for a,b,n in assignments):flags.append('ALLOCATION_SHORTFALL')
    if any('VERIFIED_EVIDENCE_FALLBACK' in a.get('methods',{}).values() for a in audit.values()):flags.append('RANK_RECOVERY_FALLBACK')
    if any(a.get('issues') for a in audit.values()):flags.append('PARTIAL_AI_VALIDATION')
    if any(any(z.get('code','').startswith(('PUBLIC_','NONSELECT_')) for z in a.get('issues',[])) for a in audit.values()):
        flags.append('PUBLIC_CANDIDATE_VALIDATION_FAILED')
    candidates=[]
    for req,b,no in assignments:
        explanations={role:r.verified_explanation(v[no],inp['source_registry'],audit[role]['methods'][b]) for role,v in role_views.items() if no in v and v[no]['evidence']}
        candidates.append(dict(horse_no=no,band=b,requested_band=req,explanations=explanations,buy_recommended=False,purchase_status='PENDING_VALIDATION'))
    return dict(prompt_version=inp['prompt_version'],schema_version=inp['schema_version'],race_id=inp['race_id'],snapshot_hash=inp['snapshot_hash'],
        snapshot_N=inp['N'],snapshot_quota=inp['quota'],mode=inp['mode'],display_rating=inp['display_rating'],
        active_count=overlay['active_count'],publication_target_count=overlay['publication_target_count'],publication_quota=overlay['publication_quota'],actual_quota=actual,
        public_candidates=selected,candidates=candidates,status_flags=flags,audit=audit,verified_at=now,
        received_at=max(received,key=r.timestamp) if received else inp['cutoff_at'],buy_recommended=False,learning_stage='COLLECT_ONLY')

def inspect_for_correction(inp,text,received_at):
    """Shared retry diagnostics. Never sends another AI's response."""
    validate_input(inp,received_at)
    try:
        plan=correction_plan(response_view(text,inp),inp)
    except (ValueError,KeyError,TypeError) as exc:
        plan=dict(needs_correction=True,repair_scope=dict(horses=[h['horse_no'] for h in inp['runners']],
            rank_bands=list(r.BANDS),presentation_horses=[],summary_horses=[],public_candidates=True,top_fields=['$']),
            errors=[dict(code='ENVELOPE_INVALID',reason=str(exc))])
    plan['request']='同一snapshotの訂正。horsesは評価修正、rank_bandsは順位関連項目、presentation_horsesは公開フラグと非選出理由、summary_horsesはband/tier/eligibility/採点集計/family_reviewの整合修正。指定外の根拠・採点を変えず、完全なJSONを返す。\n'+r.json_dumps(dict(repair_scope=plan['repair_scope'],errors=plan['errors']))
    return plan
