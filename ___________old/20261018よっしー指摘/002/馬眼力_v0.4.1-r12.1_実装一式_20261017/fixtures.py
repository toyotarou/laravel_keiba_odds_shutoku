"""Synthetic examples, never real race predictions."""
import copy,json
from decimal import Decimal
from pathlib import Path
from datetime import timedelta
import engine as e
import reference as r
CUTOFF='2026-10-17T14:54:00+09:00'
REQUEST='2026-10-17T14:54:01+09:00'
RECEIVED='2026-10-17T14:55:00+09:00'
NOW='2026-10-17T14:56:00+09:00'
def seed(n=13):
    sample=json.loads((Path(__file__).parent/'fixture_input.json').read_text())
    out={k:copy.deepcopy(sample[k]) for k in ('race_id','cutoff_at','time_contract','coverage')}
    out.update(runners=[dict(horse_no=i,win_rank=i,band=r.band(i)) for i in range(1,n+1)],source_registry={},input_indices={},field_catalog={})
    for field in ('win_odds','place_min','place_max','past_performance'):
        out['field_catalog'][field]=dict(unit='odds' if field!='past_performance' else 'index',scope='HORSE',display_label=field,value_type='number',missing_allowed=True,minimum=0,maximum=None)
    def add(no,field,slot,value):
        ref=f'h{no}_{field}_{slot}';observed=(r.timestamp(CUTOFF)-timedelta(minutes=slot-6)).isoformat() if slot else '2026-10-16T12:00:00+09:00'
        out['source_registry'][ref]=dict(source_ref=ref,race_id='race',value=value,scope='HORSE',horse_nos=[no],applicable_horse_nos=[],field=field,unit=out['field_catalog'][field]['unit'],slot_offset=slot,observed_at=observed,available_at=observed,computed_at=None,source_kind='OBSERVED',parents=[],formula_id=None,formula_version=None,dependency_key=f'h{no}_{field}',dependency_role='SIGNAL')
        out['input_indices'][f'horse/{no}/{field}/{slot}']=[ref]
    for no in range(1,n+1):
        for t in (21,18,15,12,9,6):
            win=Decimal('1.1')**no
            if t==21 and no==2:win=Decimal('5')
            low=(Decimal('2')+Decimal(no)/10)*(2 if t in (21,9) else 1)
            add(no,'win_odds',t,win);add(no,'place_min',t,low);add(no,'place_max',t,low+1)
        add(no,'past_performance',None,Decimal('2.0'))
    return out

def horse(no):
    h=json.loads((Path(__file__).parent/'fixture_output.json').read_text())['horses'][0]
    ref=f'h{no}_past_performance_None';eid=f'ability{no}';h['horse_no']=no;h['band']=r.band(no)
    h['evidence']=[dict(evidence_id=eid,family='ability',dependency_group=ref,source_refs=[ref],facts=[dict(source_ref=ref,value=Decimal('2.0'))],observation='架空の過去指数2',interpretation='能力の比較材料')]
    h['family_review']['ability'].update(source_refs=[ref],evidence_ids=[eid]);h['evidence_rank_evidence_ids']=[eid]
    return h

def output(inp):
    out={k:copy.deepcopy(inp[k]) for k in e.TOP_KEYS};rows=[horse(h['horse_no']) for h in inp['runners']]
    orders={b:[h['horse_no'] for h in rows if h['band']==b] for b in r.BANDS}
    selected=[] if inp['role_quota'] is None else [n for b in r.BANDS for n in orders[b][:inp['role_quota'][b]]]
    for h in rows:
        h['public_candidate']=h['horse_no'] in selected;h['nonselect_reason']=None if h['public_candidate'] else dict(code='BAND_QUOTA' if inp['selection_enabled'] else 'SELECTION_DISABLED',reason='架空例の帯枠')
    out.update(horses=rows,band_ranks=orders,evidence_band_ranks=copy.deepcopy(orders),public_candidates=selected,shortfalls=[],validation_issues=[]);return out

def text(out):return e.BEGIN+'\n'+r.json_dumps(out)+'\n'+e.END

def responses(prepared):return {role:[dict(text=text(output(inp)),received_at=RECEIVED)] for role,inp in prepared['inputs'].items()}
