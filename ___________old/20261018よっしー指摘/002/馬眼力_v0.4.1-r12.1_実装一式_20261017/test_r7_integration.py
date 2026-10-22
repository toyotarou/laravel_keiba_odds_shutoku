"""Behavioral regressions across correction, publication proof, time and persistence."""
import copy
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
import engine as e
import fixtures as f
import reference as r
from adapter import seed_from_export
from store import Store
from jsonschema.exceptions import ValidationError
from test_audit_regressions import set_orders, attempts

def orders_with(obj,b_order):
    orders=copy.deepcopy(obj['band_ranks']);orders['B']=b_order
    return set_orders(obj,orders)

def paired(n=18):
    p=e.prepare(f.seed(n),f.REQUEST)
    first=orders_with(f.output(p['inputs']['1st']),[10,8,9,7])
    second=orders_with(f.output(p['inputs']['2nd']),[7,9,10,8])
    return p,first,second

def empty_export():
    seed=f.seed()
    out={k:copy.deepcopy(seed[k]) for k in ('race_id','cutoff_at','time_contract','runners')}
    out.update(odds_rows=[],coverage=copy.deepcopy(seed['coverage']))
    return out

class R7IntegrationTests(unittest.TestCase):
    def setUp(self):
        self.p=e.prepare(f.seed(),f.REQUEST);self.i=self.p['inputs']['1st']

    def test_unrelated_correction_retains_actual_rank_provenance(self):
        corrected=orders_with(f.output(self.i),[10,9,8,7])
        initial=copy.deepcopy(corrected);initial['horses'][0].pop('rank_reason')
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        old_hash=hashlib.sha256(f.text(initial).encode()).hexdigest()
        new_hash=hashlib.sha256(f.text(corrected).encode()).hexdigest()
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertEqual(out['audit']['1st']['rank_sources']['B']['source_hash'],old_hash)
        facts=next(h for h in out['candidates'] if h['horse_no']==10)['explanations']['1st']['facts']
        self.assertTrue(all(x['response_hash']==old_hash for x in facts))
        self.assertEqual(out['audit']['1st']['public_proof_hash'],new_hash)

    def test_same_band_cosmetic_repair_keeps_order(self):
        corrected=orders_with(f.output(self.i),[10,9,8,7])
        initial=copy.deepcopy(corrected);initial['horses'][6].pop('rank_reason')
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertNotIn('RANK_RECOVERY_FALLBACK',out['status_flags'])

    def test_changed_evidence_can_use_new_proof_with_retained_other_rows(self):
        initial=f.output(self.i);initial['horses'][6]['evidence'][0]['facts'][0]['value']=999
        corrected=orders_with(f.output(self.i),[10,9,8,7])
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertEqual(out['audit']['1st']['methods']['B'],'EVIDENCE_BACKUP')
        self.assertEqual(out['audit']['1st']['rank_sources']['B']['source_hash'],hashlib.sha256(f.text(corrected).encode()).hexdigest())

    def test_partial_score_only_correction_cannot_reorder_evidence_band(self):
        initial=orders_with(f.output(self.i),[10,9,8,7]);initial['horses'][6]['scores']['V4']=True
        corrected=f.output(self.i);h=corrected['horses'][6]
        h['scores']['V4']=1;h['score_evidence']['V4']={'source_refs':['h7_past_performance_None'],'reason':'合成検証の比較材料'}
        h['observed_sum']=1;h['observed_cap']=6
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertEqual(out['audit']['1st']['methods']['B'],'EVIDENCE_BACKUP')

    def test_noneligible_score_repair_cannot_reorder_positive_horses(self):
        initial=orders_with(f.output(self.i),[10,9,8]);h=initial['horses'][6]
        h.update(evidence=[],eligibility='UNVERIFIED',tier='UNVERIFIED',rank_basis='NOT_RANKED',
            evidence_rank_evidence_ids=[],nonselect_reason={'code':'UNVERIFIED','reason':'合成試験では根拠なし'})
        h['scores']['V4']=True
        corrected=copy.deepcopy(initial);h=corrected['horses'][6]
        h['scores']['V4']=1;h['score_evidence']['V4']={'source_refs':['h7_past_performance_None'],'reason':'合成試験の確認済み参照'}
        h['observed_sum']=1;h['observed_cap']=6
        orders_with(corrected,[8,9,10]);corrected['horses'][6]['nonselect_reason']={'code':'UNVERIFIED','reason':'合成試験では根拠なし'}
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertNotIn('RANK_RECOVERY_FALLBACK',out['status_flags'])

    def test_ignored_correction_keeps_initial_consensus_bonus(self):
        p,first,second=paired();responses={'1st':attempts(first),'2nd':attempts(second)}
        before=e.resolve(p['inputs']['1st'],responses,[],f.NOW)
        bad=copy.deepcopy(first);bad['horses'][6]['evidence'][0]['facts'][0]['value']=999
        responses['1st']=attempts(first,bad)
        after=e.resolve(p['inputs']['1st'],responses,[],f.NOW)
        self.assertEqual(after['public_candidates'],before['public_candidates'])
        self.assertEqual(after['audit']['1st']['public_proof_hash'],before['audit']['1st']['public_proof_hash'])

    def test_public_flag_correction_restores_dual_ai_candidates(self):
        p,first,second=paired();inp=p['inputs']['1st']
        before=e.resolve(inp,{'1st':attempts(first),'2nd':attempts(second)},[],f.NOW)
        bad=copy.deepcopy(first);bad['horses'][0]['public_candidate']=False
        check=e.inspect_for_correction(inp,f.text(bad),f.RECEIVED)
        self.assertEqual(check['repair_scope']['horses'],[])
        self.assertEqual(check['repair_scope']['presentation_horses'],[1])
        unfixed=e.resolve(inp,{'1st':attempts(bad),'2nd':attempts(second)},[],f.NOW)
        self.assertIn('PUBLIC_CANDIDATE_VALIDATION_FAILED',unfixed['status_flags'])
        fixed=e.resolve(inp,{'1st':attempts(bad,first),'2nd':attempts(second)},[],f.NOW)
        self.assertEqual(fixed['public_candidates'],before['public_candidates'])
        self.assertFalse(fixed['status_flags'])

    def test_public_list_repair_does_not_authorize_evidence_changes(self):
        initial=f.output(self.i);initial['public_candidates']=[]
        corrected=f.output(self.i);corrected['horses'][6]['evidence'][0]['interpretation']='対象外の評価変更'
        view=e.response_view(f.text(initial),self.i);scope=e.correction_plan(view,self.i)['repair_scope']
        self.assertEqual(scope['horses'],[])
        merged,_=e.apply_correction(view,e.response_view(f.text(corrected),self.i),scope,self.i)
        self.assertEqual(merged['views'][7]['evidence'],view['views'][7]['evidence'])

    def test_wrong_rank_basis_only_authorizes_rank_metadata(self):
        initial=f.output(self.i);initial['horses'][6]['rank_basis']='SCORE'
        scope=e.inspect_for_correction(self.i,f.text(initial),f.RECEIVED)['repair_scope']
        self.assertEqual(scope['horses'],[]);self.assertEqual(scope['rank_bands'],['B'])

    def test_missing_top_field_is_correctable_without_regrading_horses(self):
        corrected=f.output(self.i);initial=copy.deepcopy(corrected);del initial['validation_issues']
        check=e.inspect_for_correction(self.i,f.text(initial),f.RECEIVED)
        self.assertTrue(check['needs_correction']);self.assertEqual(check['repair_scope']['horses'],[])
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertNotIn('PARTIAL_AI_VALIDATION',out['status_flags'])
        self.assertIsNotNone(out['audit']['1st']['public_proof_hash'])

    def test_nonselect_reason_mismatch_is_diagnosed(self):
        obj=f.output(self.i);obj['horses'][2]['nonselect_reason']={'code':'NO_EVIDENCE','reason':'誤記'}
        check=e.inspect_for_correction(self.i,f.text(obj),f.RECEIVED)
        self.assertTrue(check['needs_correction'])
        self.assertIn('NONSELECT_REASON_MISMATCH',[x['code'] for x in check['errors']])
        self.assertEqual(check['repair_scope']['presentation_horses'],[3])

    def test_out_of_scope_rank_change_does_not_replace_other_band(self):
        initial=orders_with(f.output(self.i),[10,9,8,7]);initial['horses'][0].pop('rank_reason')
        corrected=f.output(self.i)
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertIn('OUT_OF_SCOPE_RANK_CHANGE_IGNORED',[x['action'] for x in out['audit']['1st']['corrections']])

    def test_cancellation_after_correction_preserves_remaining_hole_order(self):
        corrected=orders_with(f.output(self.i),[10,9,8,7]);initial=copy.deepcopy(corrected)
        initial['horses'][0].pop('rank_reason')
        out=e.resolve(self.i,{'1st':attempts(initial,corrected)},[10],f.NOW)
        self.assertEqual([h['horse_no'] for h in out['candidates'] if h['band']=='B'],[9,8])
        self.assertEqual(out['actual_quota'],{'A':2,'B':2,'D':1})

    def test_shortened_close_boundary_and_prior_publication(self):
        clock=[f.NOW]
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/s.sqlite',lambda:clock[0]);st.install(self.i,1)
            st.update_live('race',0,[],'2026-10-17T14:57:00+09:00')
            clock[0]='2026-10-17T14:56:59+09:00'
            pub=st.publish('race',1,1,0,'at-boundary',f.responses(self.p))
            self.assertEqual(pub['effective_publish_deadline'],clock[0])
            clock[0]='2026-10-17T14:56:59.000001+09:00'
            with self.assertRaises(ValueError):st.publish('race',1,1,1,'after-boundary',f.responses(self.p))
            self.assertEqual(st.read('race')['publication']['publication_id'],pub['publication_id']);st.close()

    def test_commit_crossing_live_margin_rolls_back(self):
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/s.sqlite',lambda:f.NOW);st.install(self.i,1)
            st.update_live('race',0,[],'2026-10-17T14:57:00+09:00')
            ticks=iter(['2026-10-17T14:56:58+09:00','2026-10-17T14:56:58.999+09:00','2026-10-17T14:56:59.000001+09:00'])
            st.clock=lambda:next(ticks)
            with self.assertRaises(ValueError):st.publish('race',1,1,0,'cross-margin',f.responses(self.p))
            self.assertIsNone(st.read('race')['publication']);self.assertEqual(st.state('race')['publication_revision'],0);st.close()

    def test_sales_close_anchor_accepts_timezone_alias(self):
        seed=f.seed();seed['time_contract']['anchor_at']='2026-10-17T06:00:00Z'
        self.assertEqual(e.prepare(seed,f.REQUEST)['inputs']['1st']['N'],13)

    def test_post_time_is_not_forced_equal_to_sales_close(self):
        seed=f.seed();seed['time_contract'].update(anchor_kind='POST_TIME',sales_close_at='2026-10-17T14:59:30+09:00')
        self.assertEqual(e.prepare(seed,f.REQUEST)['inputs']['1st']['time_contract']['anchor_kind'],'POST_TIME')

    def test_coverage_reason_roundtrips_and_changes_hash(self):
        export=empty_export();seed=seed_from_export(export);one=e.prepare(seed,f.REQUEST)
        export['coverage']['OPI']['reason']='取得失敗:再試行対象'
        two=e.prepare(seed_from_export(export),f.REQUEST)
        self.assertNotEqual(one['snapshot_hash'],two['snapshot_hash'])
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/s.sqlite',lambda:f.NOW);st.install(two['inputs']['1st'],1)
            exported=copy.deepcopy(export['coverage']);export['coverage']['OPI']['reason']='後から変更'
            self.assertEqual(st.read('race')['input_audit']['coverage'],exported);st.close()

    def test_coverage_rejects_wrong_containers_and_duplicate_refs(self):
        base=f.seed();ref='h7_past_performance_None'
        for refs in (ref,{ref:True},[],[ref,ref],['unknown']):
            seed=copy.deepcopy(base);seed['coverage']['OPI']={'status':'INCLUDED','source_refs':refs}
            with self.assertRaises((ValueError,ValidationError)):
                e.prepare(seed,f.REQUEST)

    def test_coverage_included_refs_survive_save_and_read(self):
        seed=f.seed();seed['coverage']['past_runs']={'status':'INCLUDED','source_refs':['h7_past_performance_None']}
        p=e.prepare(seed,f.REQUEST)
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/s.sqlite',lambda:f.NOW);st.install(p['inputs']['1st'],1)
            self.assertEqual(st.read('race')['input_audit']['coverage']['past_runs'],seed['coverage']['past_runs']);st.close()

    def test_coverage_missing_is_rejected_without_invented_reason(self):
        seed=f.seed();del seed['coverage']
        with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)

    def test_old_version_is_not_silently_installed(self):
        inp=copy.deepcopy(self.i);inp['schema_version']='baganriki-v041-r6-1'
        with self.assertRaises(ValidationError):e.validate_input(inp,f.REQUEST)

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R7IntegrationTests))
    Path('r7_integration_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    raise SystemExit(not result.wasSuccessful())
