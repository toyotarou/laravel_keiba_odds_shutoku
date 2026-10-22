"""Boundary and signal-role regressions. Set BAGANRIKI_SUBJECT for old-version replay."""
import os,sys
from pathlib import Path
if os.environ.get('BAGANRIKI_SUBJECT'):sys.path.insert(0,os.environ['BAGANRIKI_SUBJECT'])
import copy,json,tempfile,unittest
from decimal import Decimal
import engine as e,reference as r,fixtures as f
from store import Store
from test_r8_regressions import holes

class R11Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.p=e.prepare(f.seed(),f.REQUEST)
    def setUp(self):self.i=copy.deepcopy(self.p['inputs']['1st'])
    def resolve(self,obj,second=False):
        responses={'1st':[dict(text=f.text(obj),received_at=f.RECEIVED)]}
        if second:responses['2nd']=f.responses(self.p)['2nd']
        return e.resolve(self.i,responses,[],f.NOW)
    def test_nested_nonselect_code_does_not_crash_other_role(self):
        for value in ([],{},[1],{'code':'BAND_QUOTA'}):
            with self.subTest(value=value):
                obj=f.output(self.i);obj['horses'][2]['nonselect_reason']['code']=value
                out=self.resolve(obj,True)
                self.assertTrue(out['public_candidates']);self.assertNotIn('AI_FAILURE',out['status_flags'])
                self.assertIn('NONSELECT_REASON_MISMATCH',[x['code'] for x in out['audit']['1st']['issues']])
    def test_nested_nonselect_repair_preserves_initial_holes(self):
        good=holes(f.output(self.i));bad=copy.deepcopy(good);bad['horses'][2]['nonselect_reason']['code']=[]
        plan=e.inspect_for_correction(self.i,f.text(bad),f.RECEIVED)
        self.assertEqual(plan['repair_scope']['horses'],[])
        self.assertEqual(plan['repair_scope']['presentation_horses'],[3])
        corrected=copy.deepcopy(good);corrected['horses'][9]['evidence'][0]['interpretation']='unauthorized unrelated change'
        out=e.resolve(self.i,{'1st':[dict(text=f.text(x),received_at=f.RECEIVED) for x in (bad,corrected)]},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertNotIn('PARTIAL_AI_VALIDATION',out['status_flags'])
        initial=e.response_view(f.text(bad),self.i)
        repaired,_=e.apply_correction(initial,e.response_view(f.text(corrected),self.i),plan['repair_scope'],self.i)
        self.assertEqual(repaired['views'][10]['evidence'],initial['views'][10]['evidence'])
    def test_late_seventh_fractional_digit_cannot_be_adopted(self):
        late=self.i['time_contract']['reply_deadline'].replace('+09:00','.0000001+09:00')
        out=e.resolve(self.i,{'1st':[dict(text=f.text(f.output(self.i)),received_at=late)],'2nd':f.responses(self.p)['2nd']},[],self.i['time_contract']['publish_deadline'])
        self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE');self.assertIn('SINGLE_AI',out['status_flags'])
    def test_late_precise_correction_preserves_valid_initial(self):
        good=holes(f.output(self.i));late=self.i['time_contract']['reply_deadline'].replace('+09:00','.0000001+09:00')
        out=e.resolve(self.i,{'1st':[dict(text=f.text(good),received_at=f.RECEIVED),dict(text=f.text(f.output(self.i)),received_at=late)]},[],self.i['time_contract']['publish_deadline'])
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
        self.assertTrue(out['audit']['1st']['corrections'])
    def test_future_submicrosecond_source_rejected(self):
        seed=f.seed();src=seed['source_registry']['h1_past_performance_None']
        src['observed_at']=src['available_at']=f.CUTOFF.replace('+09:00','.0000001+09:00')
        with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
    def test_datetime_grammar_is_consistent(self):
        invalid=('2026-10-17 14:54:00+09:00','2026-W42-6T14:54:00+09:00',
            '20261017T145400+0900','2026-10-17T14:54:00+09:00:00.1',
            '2026-10-17T14:54:00.0000000+09:00','2026-10-17T14:54+09:00',
            '2026-10-17T14:54:00,1+09:00','2026-02-30T14:54:00+09:00')
        for value in invalid:
            with self.subTest(value=value),self.assertRaises(ValueError):r.timestamp(value)
    def test_valid_timestamp_precision_and_offset_aliases(self):
        a=r.timestamp('2026-10-17T14:54:00.123456+09:00')
        self.assertEqual(a.microsecond,123456)
        self.assertEqual(a,r.timestamp('2026-10-17T05:54:00.123456Z'))
        self.assertEqual(a,r.timestamp('2026-10-17t05:54:00.123456z'))
        self.assertLess(r.timestamp('2026-10-17T14:54:00.123455+09:00'),a)
    def test_exact_reply_deadline_is_still_accepted(self):
        at=self.i['time_contract']['reply_deadline']
        out=e.resolve(self.i,{'1st':[dict(text=f.text(f.output(self.i)),received_at=at)]},[],self.i['time_contract']['publish_deadline'])
        self.assertEqual(out['public_candidates'],[1,2,7,8,11]);self.assertNotIn('status',out['audit']['1st'])
    def test_invalid_receipt_cannot_be_saved(self):
        with tempfile.TemporaryDirectory() as tmp:
            now=[f.REQUEST];s=Store(str(Path(tmp)/'state.sqlite'),lambda:now[0])
            try:
                s.install(self.i,1);grant=s.claim(self.i,1,self.p['prompts']['1st']);now[0]=f.RECEIVED
                at=f.RECEIVED.replace('+09:00','.0000001+09:00')
                with self.assertRaises(ValueError):s.complete(grant['token'],f.text(f.output(self.i)),at,True)
                self.assertEqual(s.saved_responses('race',1)['1st'],[])
                s.complete(grant['token'],f.text(f.output(self.i)),f.RECEIVED,True)
                self.assertEqual(len(s.saved_responses('race',1)['1st']),1)
            finally:s.close()
    def test_invalid_live_close_is_not_persisted(self):
        with tempfile.TemporaryDirectory() as tmp:
            s=Store(str(Path(tmp)/'state.sqlite'),lambda:f.REQUEST)
            try:
                s.install(self.i,1)
                with self.assertRaises(ValueError):s.update_live('race',0,[],f.NOW.replace('+09:00','.0000001+09:00'))
                self.assertEqual(s.state('race')['state_revision'],0)
            finally:s.close()
    def test_prepare_rejects_unreadable_numbers(self):
        for value in (10**128,Decimal('1.'+'1'*128),Decimal('1E309'),Decimal('1E-309')):
            with self.subTest(value=str(value)):
                seed=f.seed();seed['source_registry']['h1_past_performance_None']['value']=value
                with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
    def test_zero_exponent_obeys_same_range(self):
        for value in ('0E309','0E-309','-0E999999'):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):r.strict_loads(value)
                seed=f.seed();seed['source_registry']['h1_past_performance_None']['value']=Decimal(value)
                with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
        for value in ('0E308','0E-308','-0.000000'):
            self.assertEqual(r.strict_loads(value),Decimal(value))
    def test_install_rejects_unreadable_snapshot_without_creating_row(self):
        self.i['source_registry']['h1_past_performance_None']['value']=10**128
        self.i['snapshot_hash']=r.canonical_hash(e.common_payload(self.i))
        with tempfile.TemporaryDirectory() as tmp:
            s=Store(str(Path(tmp)/'state.sqlite'),lambda:f.REQUEST)
            try:
                with self.assertRaises(ValueError):s.install(self.i,1)
                self.assertEqual(s.db.execute('SELECT COUNT(*) FROM race_state').fetchone()[0],0)
            finally:s.close()
    def test_supported_numeric_boundaries_roundtrip_exactly(self):
        for value in (10**128-1,Decimal('1.'+'1'*127),Decimal('1E308'),Decimal('1E-308')):
            with self.subTest(value=str(value)):
                seed=f.seed();seed['source_registry']['h1_past_performance_None']['value']=value
                p=e.prepare(seed,f.REQUEST)
                parsed=r.strict_loads(p['input_json']['1st'])
                self.assertEqual(parsed['source_registry']['h1_past_performance_None']['value'],value)
                e.validate_input(parsed,f.REQUEST)
    def context_input(self):
        seed=f.seed();parent='h10_past_performance_None';ref='h10_derived_context'
        seed['source_registry'][ref]=dict(seed['source_registry'][parent],source_ref=ref,source_kind='DERIVED',
            parents=[parent],formula_id='identity',formula_version='1',computed_at=f.CUTOFF,
            dependency_key=ref,dependency_role='CONTEXT')
        return e.prepare(seed,f.REQUEST)['inputs']['1st'],ref
    def evidence(self,refs,inp):
        ev=f.horse(10)['evidence'][0]
        ev.update(source_refs=refs,facts=[dict(source_ref=ref,value=inp['source_registry'][ref]['value']) for ref in refs])
        return ev
    def test_derived_context_is_not_individual_signal(self):
        inp,ref=self.context_input();valid,issues=r.verified_evidence([self.evidence([ref],inp)],10,inp['source_registry'])
        self.assertEqual(valid,[]);self.assertTrue(issues)
    def test_context_with_real_signal_keeps_supported_hole(self):
        inp,ref=self.context_input();signal='h10_past_performance_None'
        ev=self.evidence([ref,signal],inp);valid,issues=r.verified_evidence([ev],10,inp['source_registry'])
        self.assertEqual(len(valid),1);self.assertEqual(issues,[]);self.assertEqual(r.independent_count(valid),1)
        obj=holes(f.output(inp));h=obj['horses'][9];h['evidence']=[ev];h['family_review']['ability']['source_refs']=[ref,signal]
        out=e.resolve(inp,{'1st':[dict(text=f.text(obj),received_at=f.RECEIVED)]},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11])
    def test_only_bad_context_evidence_is_removed(self):
        inp,ref=self.context_input();good=self.evidence(['h10_past_performance_None'],inp)
        bad=self.evidence([ref],inp);bad['evidence_id']='bad_context';bad['family']='market'
        valid,issues=r.verified_evidence([good,bad],10,inp['source_registry'])
        self.assertEqual([x['evidence_id'] for x in valid],[good['evidence_id']]);self.assertTrue(issues)
    def test_context_does_not_turn_missing_signal_into_known_score(self):
        inp,ref=self.context_input();scores,issues=r.recover_scores({'H1':5},{'H1':dict(source_refs=[ref],reason='context only')},inp['source_registry'],10)
        self.assertIsNone(scores['scores']['H1']);self.assertIn('H1',issues)
        refs=[ref,'h10_past_performance_None'];scores,issues=r.recover_scores({'H1':5},{'H1':dict(source_refs=refs,reason='known signal with context')},inp['source_registry'],10)
        self.assertEqual(scores['scores']['H1'],5);self.assertEqual(issues,[])

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R11Tests))
    Path(os.environ.get('BAGANRIKI_RESULT',str(Path(__file__).with_name('r11_regression_results.json')))).write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    sys.exit(not result.wasSuccessful())
