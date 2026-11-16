"""Update only historical synthetic normal examples: available is not positive support."""
import json

def reviews(inp,reply):
    if isinstance(inp,str):inp=json.loads(inp)
    reg=inp['source_registry']
    for h in reply['horses']:
        n=h['horse_no'];groups={'ability':[f'h{n}_past_performance_None'],
          'gap':[f'r12_gap_{min(n,15)}'],'similar_value':[f'h{n}_similar_top3_rate',f'h{n}_similar_sample_count'],
          'market':[f'h{n}_win_odds_21',f'h{n}_win_odds_6'],'win_place':[f'h{n}_win_odds_6',f'h{n}_place_min_6']}
        for field in ['win_odds','place_min','place_max']:
            refs=[f'h{n}_{field}_{s}' for s in [6,21,12,9,15,18] if reg.get(f'h{n}_{field}_{s}',{}).get('value') is not None]
            if len(refs)>=2:groups['market']=refs[:2];break
        for family,refs in groups.items():
            if h['family_review'][family]['status']=='DATA_MISSING' and all(reg.get(r,{}).get('value') is not None for r in refs):
                h['family_review'][family]=dict(status='NO_SUPPORT',source_refs=refs,evidence_ids=[],reason='合成入力の資料を確認したが肯定根拠として採用しない')
    return reply
