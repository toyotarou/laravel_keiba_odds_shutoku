"""Synthetic SQL/Queue/GET checks of actual inspection and authorized corrections; no live provider."""
from pathlib import Path
from tempfile import TemporaryDirectory
from datetime import timedelta
from copy import deepcopy
import json,sys

ROOT=Path(__file__).resolve().parent
sys.path.insert(0,str(ROOT))
from verify_r1518_candidates import Test,doc,pack

php=sys.argv[1];controller=Path(sys.argv[2]).resolve();results=[]
def project(p,inp):
    p['selection_ranks']=deepcopy(p['band_ranks']);p['public_candidates']=[];p['shortfalls']=[]
    for b in ['A','B','D']:
        order=p['band_ranks'][b];q=inp['role_quota'][b]
        p['public_candidates']+=order[:q]
        p['expected_reviews'][b]=dict(incumbent=None,challenger=None,decision='NO_BOUNDARY') if q==0 or len(order)<=q else dict(incumbent=order[q-1],challenger=order[q],decision='E_UNAVAILABLE')
        if len(order)<q:p['shortfalls'].append(dict(band=b,requested=q,eligible=len(order),missing=q-len(order),reason='確認済み根拠のある馬に限定'))
    for h in p['horses']:
        h['public_candidate']=h['horse_no'] in p['public_candidates']
        code='BAND_QUOTA' if h['eligibility']=='ELIGIBLE' else 'UNVERIFIED'
        if h['public_candidate']:h['nonselect_reason']=None
        elif not isinstance(h.get('nonselect_reason'),dict) or h['nonselect_reason'].get('code')!=code:h['nonselect_reason']=dict(code=code,reason='選出枠または確認資料の条件')
    return p

def bad_response(raw,inp,case):
    head,p,tail=doc(raw)
    if case=='false_missing':
        h=next(h for h in p['horses'] if h['horse_no']==5)
        h.update(evidence=[],evidence_rank_evidence_ids=[],tier='UNVERIFIED',eligibility='UNVERIFIED',rank_basis='NOT_RANKED')
        for family in h['family_review']:h['family_review'][family]=dict(status='DATA_MISSING',source_refs=[],evidence_ids=[],reason='資料があるのに不足と申告')
        for k in ['band_ranks','evidence_band_ranks']:p[k]['D']=[n for n in p[k]['D'] if n!=5]
    elif case=='mixed_evidence':
        h=next(h for h in p['horses'] if h['horse_no']==5)
        refs=[f'h5_win_odds_{slot}' for slot in [12,9,6]]
        assert len({inp['source_registry'][r]['value'] for r in refs})==1
        h['evidence'].append(dict(evidence_id='false_market5',family='market',dependency_group='h5_market',source_refs=refs,
            facts=[dict(source_ref=r,value=inp['source_registry'][r]['value']) for r in refs],observation='支持加速（12→9→6分）',
            interpretation='比較資料を引用',market_claim=dict(kind='ACCELERATION',metric='win_odds')))
        h['family_review']['market']=dict(status='SUPPORTED',source_refs=refs,evidence_ids=['false_market5'],reason='比較資料を引用')
        h['tier']='A';h['evidence_rank_evidence_ids'].append('false_market5')
    elif case in ['false_acceleration','wrong_quote']:
        _,source,_=doc((ROOT/'r1519_fixtures'/f'{inp["ai_role"]}_false_acceleration.txt').read_text())
        row=next(h for h in source['horses'] if h['horse_no']==4)
        if case=='wrong_quote':row['evidence'][0]['facts'][0]['value']=999
        p['horses']=[deepcopy(row) if h['horse_no']==4 else h for h in p['horses']]
        for k in ['band_ranks','evidence_band_ranks']:p[k]['D']=[4]+[n for n in p[k]['D'] if n!=4]
    else:
        h=next(h for h in p['horses'] if h['horse_no']==5)
        h['evidence_rank_reason']=' ' if case=='ascii_blank' else '\u3000'
    return pack(head,project(p,inp),tail)

with TemporaryDirectory() as tmp:
    t=Test(php,controller,Path(tmp));t.cache={}
    try:
        for role in ['1st','2nd']:
            for case,repair in [('false_missing',None),('false_acceleration',None),('mixed_evidence',None),('fullwidth',None),
                                ('false_missing','good'),('fullwidth','good'),('wrong_quote','false_acceleration'),('ascii_blank','fullwidth')]:
                if len(sys.argv)>3 and case!=sys.argv[3]:continue
                t.configure(role)
                inp=json.loads(t.role_rows[role]['input']);assert all(r['E3'] is None and r['E5'] is None for r in inp['expected_v01']['rows'])
                good=t.role_rows[role]['original'];broken=bad_response(good,inp,case);t.role_rows[role]['original']=broken
                for r in ['1st','2nd']:t.send(r)
                t.now=t.start+timedelta(seconds=3,microseconds=200)
                for i,r in enumerate(['1st','2nd']):assert t.receive(r,at=t.start+timedelta(seconds=3,microseconds=i*50))['ok']
                before=t.inspect(role,with_grant=True)
                assert before['diagnostics']['errors'],(role,case,before)
                codes=[x['code'] for x in before['diagnostics']['errors']]
                if case=='false_missing':assert any(x.startswith('W19_FALSE_MISSING') for x in codes),codes
                if case in ['false_acceleration','mixed_evidence']:assert 'W19_FALSE_ACCELERATION' in codes,codes
                after=None
                if repair:
                    t.now=t.start+timedelta(seconds=6)
                    fixed=good if repair=='good' else bad_response(good,inp,repair)
                    t.correction(role,fixed)
                    after=t.inspect(role,with_grant=True)
                    if repair=='good':assert after['code']=='FIX_APPLIED',(role,case,after)
                    else:assert after['code']!='FIX_APPLIED',(role,case,after)
                for r in ['1st','2nd']:t.settle(r)
                t.now=t.limit;pub=t.request('when_ready');view=t.count_get()
                assert pub['code'] in ['PUBLISHED','ALREADY'],pub
                nums=[h['horse_no'] for h in pub['candidates']]
                assert nums==[h['horse_no'] for h in view['publication']['candidates']]
                assert 4 not in nums,(role,case,'false acceleration published',nums)
                if repair=='good' or case=='mixed_evidence':assert 5 in nums,(role,case,'valid 12th favourite lost',nums,before,pub)
                assert not view['buy_recommended']
                counts=t.committed();again=t.request('when_ready');assert again['code']=='ALREADY' and t.committed()==counts
                row=dict(role=role,case=case,repair=repair,initial_codes=codes,after_code=after['code'] if after else None,candidates=nums,duplicate_side_effects=0)
                results.append(row);print(json.dumps(row,ensure_ascii=False),flush=True)
    finally:t.db.close()

print('PASS',len(results),'SQL/Queue/GET semantic cases',flush=True)
