import copy
import itertools
import json
import unittest
from pathlib import Path
import reference as r

COUNTS={}
def runners(n=13): return [dict(horse_no=i,win_rank=i,band=r.band(i)) for i in range(1,n+1)]
def evidence(no=7,family='ability',root=None):
    return dict(evidence_id=f'e{no}-{family}',family=family,source_refs=[f'h{no}'],
                facts=[dict(source_ref=f'h{no}',value=2.0)],interpretation='同条件の実績を比較材料とする',
                verified_roots=[root or f'root{no}-{family}'])
def row(no=7,partial=False):
    scores={k:1 for k in r.CAPS}
    if partial: scores['V4']=None
    return dict(horse_no=no,band=r.band(no),evidence=[evidence(no)],scores=scores,
        score_status='partial' if partial else 'complete',total_score=None if partial else 10,
        response_hash='initial',row_valid=True)
def registry():
    return {f'h{no}':dict(source_ref=f'h{no}',race_id='race',value=2.0,
        observed_at='2026-10-17T14:50:00+09:00',available_at='2026-10-17T14:50:01+09:00',
        scope='HORSE',horse_nos=[no],applicable_horse_nos=[],field='past_performance',unit='index',slot_offset=None,
        source_kind='OBSERVED',parents=[],formula_id=None,formula_version=None,computed_at=None,
        dependency_key=f'root{no}',dependency_role='SIGNAL') for no in (7,8)}

CATALOG={'past_performance':dict(unit='index',scope='HORSE',display_label='過去成績指数',
    value_type='number',missing_allowed=True,minimum=0,maximum=None)}

class R7Tests(unittest.TestCase):
    def test_strict_json(self):
        for text in ('{"a":1,"a":2}','{"x":NaN}','{"x":Infinity}','{"x":1e999}'):
            with self.assertRaises(ValueError): r.strict_loads(text)
        self.assertEqual(r.strict_loads('{"x":null}'),{'x':None})

    def test_structural_and_supply_exhaustive(self):
        structures=supplies=0
        for n in range(1,19):
            rs=runners(n); c=r.target_count(n)
            k={b:sum(h['band']==b for h in rs) for b in r.BANDS}
            for mode in ('FIRM','MIXED','VOLATILE'):
                base,q,_=r.structural_quota(rs,mode)
                self.assertEqual(sum(q.values()),c)
                self.assertTrue(all(0<=q[b]<=k[b] for b in r.BANDS))
                for role in ('1st','2nd'):
                    cap,rq=r.role_quota(q,c,role)
                    self.assertEqual(sum(rq.values()),cap)
                    self.assertGreaterEqual(min(rq.values()),0)
                structures+=1
                band_numbers={b:[h['horse_no'] for h in rs if h['band']==b] for b in r.BANDS}
                for amounts in itertools.product(*(range(k[b]+1) for b in r.BANDS)):
                    orders={b:band_numbers[b][:a] for b,a in zip(r.BANDS,amounts)}
                    result,assignments,actual=r.select_final(q,orders)
                    self.assertEqual(len(result),len(set(result)))
                    self.assertLessEqual(len(result),c)
                    self.assertLessEqual(actual['A'],q['A'])
                    for b,a in zip(r.BANDS,amounts):
                        self.assertLessEqual(actual[b],a)
                        self.assertGreaterEqual(actual[b],min(q[b],a))
                    if all(a>=q[b] for b,a in zip(r.BANDS,amounts)): self.assertEqual(actual,q)
                    if amounts[1]+amounts[2]>=q['B']+q['D']:
                        self.assertGreaterEqual(actual['B']+actual['D'],q['B']+q['D'])
                    self.assertFalse(any(a=='A' and req in ('B','D') for req,a,no in assignments))
                    supplies+=1
        COUNTS.update(structural_conditions=structures,supply_conditions=supplies)

    def test_all_tristates(self):
        count=0; recovered=0
        for signals in itertools.product((False,True,None),repeat=7):
            got=r.signal_mode(signals)
            values={sum(a*b for a,b in zip((2,1,1,1,1,1,-2),v)) for v in
                itertools.product(*((0,1) if x is None else (int(x),) for x in signals))}
            self.assertEqual((got['S_min'],got['S_max']),(min(values),max(values)))
            modes={r.mode_value(s) for s in values}
            self.assertEqual(got['mode'],next(iter(modes)) if len(modes)==1 else 'UNKNOWN')
            stars={r.rating_value(s) for s in values}
            self.assertEqual(got['display_rating'],next(iter(stars)) if len(stars)==1 and len(modes)==1 else None)
            if signals.count(None)>=3 and len(modes)==1: recovered+=1
            count+=1
        COUNTS.update(tristate_conditions=count,unknown3_recovered=recovered)

    def test_signals_strict_types(self):
        with self.assertRaises(ValueError): r.signal_mode([0]*7)
        with self.assertRaises(ValueError): r.target_count(True)
        with self.assertRaises(ValueError): r.target_count(19)

    def test_signal_boundaries(self):
        pairs={k:dict(W21=1.2,W6=1.2,P6=1.2) for k in range(1,13)}
        horses=[dict(win_rank=i,P21=2.0,P9=2.0,P6=2.0) for i in range(1,14)]
        similar=dict(definition_valid=True,race_count=50,rate=0.3,baseline=0.2)
        s=r.extract_signals(13,pairs,horses,similar)
        self.assertEqual(s,[False,False,True,False,False,True,False])
        pairs[1]=dict(W21=2.5,W6=2.0,P6=1.49)
        pairs[7]=dict(W21=2.0,W6=2.0,P6=1.5)
        horses[6]['P6']=1.9; horses[7]['P6']=1.94
        s=r.extract_signals(13,pairs,horses,similar)
        self.assertTrue(s[0]);self.assertTrue(s[1]);self.assertTrue(s[3]);self.assertTrue(s[4])
        pairs[1]['W6']=2.0001
        self.assertFalse(r.extract_signals(13,pairs,horses,similar)[0])
        pairs[3]['W6']=None
        self.assertIsNone(r.extract_signals(13,pairs,horses,similar)[2])
        pairs[4]['W6']=1.5
        self.assertFalse(r.extract_signals(13,pairs,horses,similar)[2])

    def test_small_and_empty_signals(self):
        s=r.extract_signals(1,{},[dict(win_rank=1)],None)
        self.assertEqual(s,[False,False,False,False,False,False,False])
        self.assertFalse(r.at_least([],1));self.assertFalse(r.at_least([None],2))
        self.assertIsNone(r.at_least([True,None],2))

    def test_registry_valid(self):
        r.validate_registry(registry(),'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)

    def test_wrong_unit_field_and_timezone_alias(self):
        for key,value in [('unit','percent'),('field','unregistered')]:
            reg=registry();reg['h7'][key]=value
            with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)
        reg=registry();reg['alias']=dict(reg['h7'],source_ref='alias',observed_at='2026-10-17T05:50:00Z')
        with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)

    def test_registry_invalid_owner_time_and_type(self):
        for field,value in [('horse_nos',[True]),('observed_at','2026-10-17T14:55:00+09:00'),
                            ('available_at','2026-10-17T14:55:00+09:00'),('value',float('nan')),
                            ('race_id','wrong'),('slot_offset',21)]:
            reg=registry();reg['h7'][field]=value
            with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)

    def test_registry_alias_cycle_and_lineage(self):
        reg=registry();reg['alias']=dict(reg['h7'],source_ref='alias')
        with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)
        reg=registry();reg['derived']=dict(reg['h7'],source_ref='derived',source_kind='DERIVED',
            parents=['h7'],formula_id='example',formula_version='1',computed_at='2026-10-17T14:50:02+09:00')
        r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)
        self.assertEqual(r.source_roots('derived',reg),{'root7'})
        reg['derived']['parents']=['derived']
        with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)

    def test_wrong_horse_same_value(self):
        e=evidence(8)
        valid,issues=r.verified_evidence([e],7,registry())
        self.assertFalse(valid);self.assertIn('wrong horse or pair',issues)

    def test_wrong_cohort(self):
        reg=registry();reg['h7'].update(scope='COHORT',horse_nos=[],applicable_horse_nos=[8])
        valid,issues=r.verified_evidence([evidence()],7,reg)
        self.assertFalse(valid);self.assertIn('wrong cohort',issues)

    def test_fact_types_duplicate_and_context(self):
        reg=registry();good=evidence()
        for value in (True,'2.0',3.0,None):
            e=copy.deepcopy(good);e['facts'][0]['value']=value
            self.assertFalse(r.verified_evidence([e],7,reg)[0])
        self.assertEqual(len(r.verified_evidence([good],7,reg)[0]),1)
        self.assertFalse(r.verified_evidence([good,good],7,reg)[0])
        reg['h7']['dependency_role']='CONTEXT'
        self.assertFalse(r.verified_evidence([good],7,reg)[0])

    def test_null_is_not_positive_or_zero_score(self):
        reg=registry();reg['h7']['value']=None
        e=evidence();e['facts'][0]['value']=None
        self.assertFalse(r.verified_evidence([e],7,reg)[0])
        out,_=r.recover_scores({'H1':0},{'H1':{'source_refs':['h7']}},reg,7)
        self.assertIsNone(out['scores']['H1'])

    def test_malformed_evidence_is_local(self):
        bad=evidence();bad['interpretation']=None
        good=evidence();good['evidence_id']='good'
        valid,issues=r.verified_evidence([None,bad,good],7,registry())
        self.assertEqual(len(valid),1);self.assertEqual(valid[0]['evidence_id'],'good')

    def test_dependency_duplicates(self):
        e1=evidence(7,'ability','x');e2=evidence(7,'market','x');e3=evidence(7,'gap','y')
        self.assertEqual(r.independent_count([e1,e2,e3]),2)
        e4=evidence(7,'gap','x')
        self.assertEqual(r.independent_count([e1,e2,e3,e4]),1)

    def test_scores_and_local_null(self):
        reg=registry();scores={k:1 for k in r.CAPS};se={k:dict(source_refs=['h7'],reason='fixture') for k in r.CAPS}
        out,_=r.recover_scores(scores,se,reg,7)
        self.assertEqual(out['total_score'],10);self.assertEqual(out['observed_cap'],100)
        for invalid in (True,'1',19,1.5):
            test=dict(scores,H1=invalid);out,_=r.recover_scores(test,se,reg,7)
            self.assertIsNone(out['scores']['H1']);self.assertEqual(out['scores']['H2'],1)
            self.assertIsNone(out['total_score']);self.assertEqual(out['observed_cap'],82)
        out,_=r.recover_scores(None,None,reg,7)
        self.assertEqual(out['observed_cap'],0)

    def test_score_bad_refs(self):
        se={k:dict(source_refs=['h8']) for k in r.CAPS}
        out,_=r.recover_scores({k:1 for k in r.CAPS},se,registry(),7)
        self.assertEqual(out['observed_sum'],0)
        se['H1']={'source_refs':[{}]}
        out,_=r.recover_scores({'H1':1},se,registry(),7)
        self.assertIsNone(out['scores']['H1'])

    def test_one_bad_score_no_band_loss(self):
        rows=[row(i,partial=(i==7)) for i in range(7,11)]
        order,method=r.recover_rank(rows,[7,8,9,10],[8,7,9,10],True)
        self.assertEqual(order,[8,7,9,10]);self.assertEqual(method,'EVIDENCE_BACKUP')
        q=dict(A=2,B=2,D=1)
        result,_,actual=r.select_final(q,dict(A=[1,2,3,4,5,6],B=order,D=[11]))
        self.assertEqual(actual,q);self.assertEqual(len(result),5)

    def test_broken_backup_retains_all_positive(self):
        rows=[row(i,partial=(i==7)) for i in range(7,11)]
        for backup in ([7,7,9,10],[7,8,9],[]):
            order,method=r.recover_rank(rows,[],backup,False)
            self.assertEqual(set(order),{7,8,9,10})
            self.assertEqual(method,'VERIFIED_EVIDENCE_FALLBACK')
        COUNTS['fixed_r3_reproduction']={'before_r3':[2,0,1],'r7_backup':[2,2,1],'r7_fallback':[2,2,1]}

    def test_evidence_order_survives_removal(self):
        rows=[row(8),row(9),row(10)]
        order,method=r.recover_rank(rows,[8,7,9,10],[8,7,9,10],True,'EVIDENCE')
        self.assertEqual(order,[8,9,10]);self.assertEqual(method,'EVIDENCE_BACKUP')

    def test_dual_ai_bad_scores_keep_B_slots(self):
        orders={};views={}
        for role,bad_no in [('1st',7),('2nd',8)]:
            rows=[row(i,partial=(i==bad_no)) for i in range(7,11)]
            recovered,_=r.recover_rank(rows,[],[],False)
            orders[role]=dict(A=[1,2],B=recovered,D=[11])
            views[role]={h['horse_no']:h for h in rows+[row(1),row(2),row(11)]}
        merged,_=r.merge_orders(runners(),orders,views)
        _,_,actual=r.select_final(dict(A=2,B=2,D=1),merged)
        self.assertEqual(actual,dict(A=2,B=2,D=1))

    def test_missing_duplicate_extra_rows_isolated(self):
        raw=[row(i) for i in range(1,14) if i!=7]+[row(8),{'horse_no':99},{'horse_no':True}]
        accepted,missing,issues=r.partition_rows(raw,runners())
        self.assertEqual(set(missing),{7,8});self.assertEqual(len(accepted),11)
        self.assertIn(9,accepted)

    def test_correction_rejected_preserves_initial(self):
        initial={7:row(7,True),8:row(8)}
        result,log=r.keep_correction(initial,{},set(initial),False)
        self.assertEqual(result,initial)

    def test_correction_scope_and_regression(self):
        initial={7:row(7,True),8:row(8)}
        corrected={7:dict(row(7),response_hash='corrected'),8:row(8,True)}
        out,log=r.keep_correction(initial,corrected,{7},True)
        self.assertEqual(out[7]['response_hash'],'corrected');self.assertEqual(out[8],initial[8])
        self.assertIn('OUT_OF_SCOPE_CHANGE_IGNORED',[x['action'] for x in log])
        corrected[7]['evidence']=[]
        out,log=r.keep_correction(initial,corrected,{7},True)
        self.assertEqual(out,initial)

    def test_correction_score_regression(self):
        initial={7:row(7)}
        out,log=r.keep_correction(initial,{7:row(7,True)},{7},True)
        self.assertEqual(out,initial)

    def test_cancel_rank_and_band_frozen(self):
        rs=runners();before=copy.deepcopy(rs)
        view=r.cancellation_view(rs,[2],'VOLATILE')
        self.assertEqual(view['active_count'],12)
        self.assertEqual([h['win_rank'] for h in view['active_runners']],[1]+list(range(3,14)))
        self.assertEqual(next(h['band'] for h in view['active_runners'] if h['horse_no']==7),'B')
        self.assertEqual(next(h['band'] for h in view['active_runners'] if h['horse_no']==11),'D')
        self.assertEqual(rs,before)

    def test_all_cancellation_subsets(self):
        count=0
        rs=runners(13)
        for flags in itertools.product((False,True),repeat=13):
            cancelled=[h['horse_no'] for h,f in zip(rs,flags) if f]
            for mode in ('FIRM','MIXED','VOLATILE'):
                v=r.cancellation_view(rs,cancelled,mode);q=v['publication_quota']
                self.assertEqual(sum(q.values()),r.target_count(v['active_count']))
                self.assertTrue(all(q[b]<=sum(h['band']==b for h in v['active_runners']) for b in r.BANDS))
                count+=1
        COUNTS['cancellation_subset_conditions_N13']=count

    def test_cancel_all_and_unknown(self):
        v=r.cancellation_view(runners(),list(range(1,14)),'VOLATILE')
        self.assertEqual(v['publication_target_count'],0)
        self.assertEqual(v['publication_quota'],dict(A=0,B=0,D=0))
        with self.assertRaises(ValueError): r.cancellation_view(runners(),[99],'VOLATILE')

    def test_cancellation_band_counts_N18(self):
        rs=runners(18);count=0
        by_band={b:[h['horse_no'] for h in rs if h['band']==b] for b in r.BANDS}
        for amounts in itertools.product(range(7),range(5),range(9)):
            active=set(n for b,a in zip(r.BANDS,amounts) for n in by_band[b][:a])
            cancelled=sorted(set(range(1,19))-active)
            for mode in ('FIRM','MIXED','VOLATILE'):
                v=r.cancellation_view(rs,cancelled,mode)
                self.assertEqual(sum(v['publication_quota'].values()),r.target_count(len(active)))
                self.assertTrue(all(v['publication_quota'][b]<=a for b,a in zip(r.BANDS,amounts)))
                count+=1
        COUNTS['cancellation_band_count_conditions_N18']=count

    def test_merge_second_independent_discovery(self):
        orders={'1st':dict(A=[],B=[7],D=[]),'2nd':dict(A=[],B=[8,7],D=[])}
        views={'1st':{7:row(7)},'2nd':{7:row(7),8:row(8)}}
        merged,scores=r.merge_orders(runners(),orders,views)
        self.assertIn(8,merged['B']);self.assertEqual(scores[8][0],100)
        merged,scores=r.merge_orders(runners(),orders,views,{'1st':{7},'2nd':{7}})
        self.assertEqual(scores[7][0],105)

    def test_duplicate_final_rejected(self):
        with self.assertRaises(ValueError): r.select_final(dict(A=1,B=1,D=0),dict(A=[7],B=[7],D=[]))

    def test_explanation_drops_bad_fact(self):
        reg=registry();bad=evidence();bad['facts'][0]['value']=99
        good=evidence();good['evidence_id']='good'
        valid,_=r.verified_evidence([bad,good],7,reg)
        view=dict(row(),evidence=valid,explanation='誤った流入加速の原文')
        display=r.verified_explanation(view,reg)
        self.assertEqual(len(display['facts']),1)
        self.assertNotIn('流入加速',json.dumps(display,ensure_ascii=False))
        self.assertNotIn('99',json.dumps(display))

    def test_counterfacts_reject_wrong_owner(self):
        view=dict(row(),counterevidence=[{'source_refs':['h8','unknown']},{'source_refs':['h7']}])
        display=r.verified_explanation(view,registry())
        self.assertEqual([x['source_ref'] for x in display['counterfacts']],['h7'])

    def test_deadlines_and_atomic_publication(self):
        base=dict(now='2026-10-17T14:57:00+09:00',received_at='2026-10-17T14:56:00+09:00',
            reply_deadline='2026-10-17T14:56:30+09:00',publish_deadline='2026-10-17T14:57:30+09:00',
            live_close='2026-10-17T14:58:00+09:00',snapshot_hash='x',current_snapshot_hash='x',
            state_revision=2,current_state_revision=2,verified_at='2026-10-17T14:56:50+09:00')
        self.assertTrue(r.can_publish(**base))
        for change in [dict(current_snapshot_hash='y'),dict(current_state_revision=3),
                       dict(received_at='2026-10-17T14:56:31+09:00'),dict(now='2026-10-17T14:57:31+09:00'),
                       dict(live_close=base['now']),dict(verified_at='2026-10-17T14:57:01+09:00')]:
            self.assertFalse(r.can_publish(**(base|change)))

    def test_cancel_rejects_bool_float_string(self):
        for value in (True,1.0,'1',None):
            with self.assertRaises(ValueError): r.cancellation_view(runners(),[value],'MIXED')

    def test_signal_rejects_duplicate_missing_and_bool_rank(self):
        hs=[dict(win_rank=i) for i in range(1,14)]
        for bad in (hs[:-1],hs[:-1]+[hs[6]], [dict(win_rank=True)]+hs[1:]):
            with self.assertRaises(ValueError): r.extract_signals(13,{},bad)
        for n in (True,0,19):
            with self.assertRaises(ValueError): r.extract_signals(n,{},hs)
        with self.assertRaises(ValueError): r.extract_signals(13,{True:{}},hs)

    def test_registry_rejects_time_reversal(self):
        reg=registry();reg['h7']['available_at']='2026-10-17T14:49:59+09:00'
        with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)
        reg=registry();reg['derived']=dict(reg['h7'],source_ref='derived',source_kind='DERIVED',
            observed_at='2026-10-17T14:49:59+09:00',parents=['h7'],formula_id='example',
            formula_version='1',computed_at='2026-10-17T14:50:02+09:00')
        with self.assertRaises(ValueError): r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',{7,8},CATALOG)

    def test_merge_rejects_duplicate_and_bad_id(self):
        for order in ([7,7],[True],[7.0],[99]):
            with self.assertRaises(ValueError):
                r.merge_orders(runners(),{'1st':dict(A=[],B=order,D=[])},{'1st':{7:row(7)}})

    def test_validation_flags_must_be_boolean(self):
        for flag in (1,'true',{},None):
            order,basis=r.recover_rank([row(7,True)],[],[7],flag)
            self.assertEqual(basis,'VERIFIED_EVIDENCE_FALLBACK')
            got,log=r.keep_correction({7:row(7,True)},{7:row(7)},[7],flag)
            self.assertEqual(got[7]['score_status'],'partial')
        order,basis=r.recover_rank([row(1,True)],[],[True],True)
        self.assertEqual(order,[1]);self.assertEqual(basis,'VERIFIED_EVIDENCE_FALLBACK')

    def test_publication_rejects_temporal_and_type_faults(self):
        base=dict(now='2026-10-17T14:57:00+09:00',received_at='2026-10-17T14:56:00+09:00',
            reply_deadline='2026-10-17T14:56:30+09:00',publish_deadline='2026-10-17T14:57:30+09:00',
            live_close='2026-10-17T14:58:00+09:00',snapshot_hash='x',current_snapshot_hash='x',
            state_revision=1,current_state_revision=1,verified_at='2026-10-17T14:56:50+09:00')
        for change in (dict(verified_at='2026-10-17T14:55:59+09:00'),dict(state_revision=True),
            dict(current_state_revision=1.0),dict(state_revision=-1,current_state_revision=-1),
            dict(snapshot_hash=None,current_snapshot_hash=None),dict(now='bad'),
            dict(reply_deadline='2026-10-17T14:58:01+09:00')):
            self.assertFalse(r.can_publish(**(base|change)))
        # r7: an advanced close may precede the original publication deadline;
        # publication is allowed only while the new close-minus-margin has not elapsed.
        self.assertTrue(r.can_publish(**(base|dict(live_close=base['publish_deadline'],safety_margin_ms=1000))))
        self.assertTrue(r.can_publish(**(base|dict(verified_at=base['received_at'],now=base['publish_deadline']))))

if __name__=='__main__':
    suite=unittest.defaultTestLoader.loadTestsFromTestCase(R7Tests)
    result=unittest.TextTestRunner(verbosity=2).run(suite)
    payload=dict(unit_tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors),
                 parameter_counts=COUNTS,scope='reference code only; no production PHP/AI/UI execution')
    Path('test_results.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2))
    raise SystemExit(0 if result.wasSuccessful() else 1)
