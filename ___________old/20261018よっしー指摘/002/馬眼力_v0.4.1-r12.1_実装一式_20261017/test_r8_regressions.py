"""Behavioral requirements added after the r7 audit. Synthetic data only."""
import copy,hashlib,json,os,sys,tempfile,unittest
from pathlib import Path
if os.environ.get('BAGANRIKI_SUBJECT'):sys.path.insert(0,os.environ['BAGANRIKI_SUBJECT'])
import engine as e,fixtures as f,reference as r
from store import Store
from test_audit_regressions import attempts,set_orders

def holes(obj):
    orders=copy.deepcopy(obj['band_ranks']);orders['B']=[10,9,8,7]
    return set_orders(obj,orders)
def deep_text(obj,depth=600):
    return e.BEGIN+'\n'+r.json_dumps(obj)[:-1]+',"extra":'+('['*depth)+'0'+(']'*depth)+'}\n'+e.END

class R8Regressions(unittest.TestCase):
    def setUp(self):
        self.p=e.prepare(f.seed(),f.REQUEST);self.i=self.p['inputs']['1st'];self.normal=holes(f.output(self.i))
    def inspect(self,obj):return e.inspect_for_correction(self.i,f.text(obj),f.RECEIVED)
    def resolve(self,*objects,cancelled=()):return e.resolve(self.i,{'1st':attempts(*objects)},list(cancelled),f.NOW)
    def test_deep_invalid_role_preserves_other_ai(self):
        for depth in (600,900):
            with self.subTest(depth=depth):
                out=e.resolve(self.i,{'1st':[{'text':deep_text(self.normal,depth),'received_at':f.RECEIVED}],
                    '2nd':f.responses(self.p)['2nd']},[],f.NOW)
                self.assertEqual(out['public_candidates'],[1,2,7,8,11])
                self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE');self.assertIn('SINGLE_AI',out['status_flags'])
    def test_deep_failed_correction_preserves_initial_holes(self):
        first=copy.deepcopy(self.normal);first['horses'][0].pop('rank_reason')
        out=e.resolve(self.i,{'1st':attempts(first)+[{'text':deep_text(self.normal),'received_at':f.RECEIVED}]},[],f.NOW)
        self.assertEqual(out['public_candidates'],[1,2,10,9,11]);self.assertNotIn('RANK_RECOVERY_FALLBACK',out['status_flags'])
    def test_parser_depth_boundary_and_quoted_brackets(self):
        value=r.strict_loads('['*64+'0'+']'*64)
        for _ in range(64):value=value[0]
        self.assertEqual(value,0)
        with self.assertRaises(ValueError):r.strict_loads('['*65+'0'+']'*65)
        value='['*1000+'"\\'+'}'*1000;self.assertEqual(r.strict_loads(json.dumps(value)),value)
    def test_integer_precision_boundary(self):
        self.assertEqual(r.strict_loads('9'*128),int('9'*128))
        for token in ('9'*129,'-'+'9'*129):
            with self.subTest(sign=token[0]),self.assertRaises(ValueError):r.strict_loads(token)
    def test_unknown_counterevidence_is_local_and_not_rank_proof(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['counterevidence']=[{'source_refs':['missing'],'reason':'架空の反証'}]
        check=self.inspect(bad);self.assertTrue(check['needs_correction']);self.assertIn(7,check['repair_scope']['horses'])
        view=e.response_view(f.text(bad),self.i);self.assertTrue(view['views'][7]['evidence']);self.assertEqual(view['views'][7]['counterevidence'],[])
        out=self.resolve(bad);self.assertEqual(out['audit']['1st']['methods']['B'],'VERIFIED_EVIDENCE_FALLBACK');self.assertIsNone(out['audit']['1st']['public_proof_hash'])
    def test_other_horse_counterevidence_is_rejected(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['counterevidence']=[{'source_refs':['h1_past_performance_None'],'reason':'別馬の情報'}]
        self.assertIn('COUNTEREVIDENCE_INVALID',[x['code'] for x in self.inspect(bad)['errors']])
    def test_valid_counterevidence_is_preserved(self):
        obj=copy.deepcopy(self.normal);counter=[{'source_refs':['h7_past_performance_None'],'reason':'同馬の条件比較'}];obj['horses'][6]['counterevidence']=counter
        self.assertFalse(self.inspect(obj)['needs_correction']);self.assertEqual(e.response_view(f.text(obj),self.i)['views'][7]['counterevidence'],counter)
        self.assertEqual(self.resolve(obj)['public_candidates'],[1,2,10,9,11])
    def test_counter_correction_restores_holes_and_public_proof(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['counterevidence']=[{'source_refs':['missing'],'reason':'不正な参照'}]
        out=self.resolve(bad,self.normal);self.assertEqual(out['public_candidates'],[1,2,10,9,11]);self.assertNotIn('PARTIAL_AI_VALIDATION',out['status_flags'])
        self.assertEqual(out['audit']['1st']['public_proof_hash'],hashlib.sha256(f.text(self.normal).encode()).hexdigest())
    def test_derived_fields_are_diagnosed_without_regrading(self):
        for field,value in [('band','D'),('tier','A'),('eligibility','NO_EVIDENCE'),('observed_sum',99),('observed_cap',100)]:
            with self.subTest(field=field):
                bad=copy.deepcopy(self.normal);bad['horses'][6][field]=value;check=self.inspect(bad)
                self.assertTrue(check['needs_correction']);self.assertEqual(check['repair_scope']['horses'],[]);self.assertEqual(check['repair_scope']['summary_horses'],[7])
    def test_summary_correction_cannot_regrade_or_reorder_normal_holes(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['observed_sum']=99;changed=copy.deepcopy(self.normal)
        changed['horses'][6]['evidence'][0]['interpretation']='対象外の変更';orders=copy.deepcopy(changed['band_ranks']);orders['B']=[7,8,9,10];set_orders(changed,orders)
        initial=e.response_view(f.text(bad),self.i);scope=e.correction_plan(initial,self.i)['repair_scope']
        merged,_=e.apply_correction(initial,e.response_view(f.text(changed),self.i),scope,self.i)
        self.assertEqual(merged['views'][7]['evidence'],initial['views'][7]['evidence']);self.assertEqual(self.resolve(bad,changed)['public_candidates'],[1,2,10,9,11])
    def test_summary_correction_restores_consensus_proof(self):
        p=e.prepare(f.seed(18),f.REQUEST);i=p['inputs']['1st'];good=holes(f.output(i));bad=copy.deepcopy(good);bad['horses'][6]['observed_sum']=99
        second=f.output(p['inputs']['2nd']);orders=copy.deepcopy(second['band_ranks']);orders['B']=[7,9,10,8];set_orders(second,orders)
        expected=e.resolve(i,{'1st':attempts(good),'2nd':attempts(second)},[],f.NOW)
        actual=e.resolve(i,{'1st':attempts(bad,good),'2nd':attempts(second)},[],f.NOW)
        self.assertEqual(actual['public_candidates'],expected['public_candidates']);self.assertFalse(actual['status_flags'])
    def test_wrong_review_id_and_owner_request_local_metadata_repair(self):
        for key,value in [('evidence_ids',['ability1']),('source_refs',['h1_past_performance_None']),('reason','')]:
            with self.subTest(field=key):
                bad=copy.deepcopy(self.normal);bad['horses'][6]['family_review']['ability'][key]=value;check=self.inspect(bad)
                self.assertTrue(check['needs_correction']);self.assertEqual(check['repair_scope']['horses'],[]);self.assertEqual(check['repair_scope']['summary_horses'],[7])
    def test_review_correction_keeps_rank_and_facts_provenance(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['family_review']['ability']['evidence_ids']=['missing']
        out=self.resolve(bad,self.normal);self.assertEqual(out['public_candidates'],[1,2,10,9,11]);self.assertIsNotNone(out['audit']['1st']['public_proof_hash'])
        self.assertNotIn('PARTIAL_AI_VALIDATION',out['status_flags']);oldhash=hashlib.sha256(f.text(bad).encode()).hexdigest()
        self.assertEqual(out['audit']['1st']['rank_sources']['B']['source_hash'],oldhash)
    def test_no_support_classification_is_not_missing_data(self):
        obj=copy.deepcopy(self.normal);h=obj['horses'][6];h.update(evidence=[],tier='C',eligibility='NO_EVIDENCE',rank_basis='NOT_RANKED',evidence_rank_evidence_ids=[])
        for x in h['family_review'].values():x.update(status='NO_SUPPORT',source_refs=['h7_past_performance_None'],evidence_ids=[],reason='確認したが支持なし')
        orders=copy.deepcopy(obj['band_ranks']);orders['B']=[10,9,8];set_orders(obj,orders);h['nonselect_reason']={'code':'NO_EVIDENCE','reason':'全系統を確認して支持なし'}
        view=e.response_view(f.text(obj),self.i);self.assertEqual(view['views'][7]['tier'],'C');self.assertEqual(view['views'][7]['eligibility'],'NO_EVIDENCE')
        self.assertFalse(self.inspect(obj)['needs_correction'])
    def test_false_shortfalls_are_diagnosed(self):
        bad=copy.deepcopy(self.normal);bad['shortfalls']=[{'band':'B','requested':2,'eligible':0,'missing':2,'reason':'誤った不足'}]
        check=self.inspect(bad);self.assertTrue(check['needs_correction']);self.assertIn('shortfalls',check['repair_scope']['top_fields']);self.assertEqual(check['repair_scope']['horses'],[])
        self.assertNotIn('PARTIAL_AI_VALIDATION',self.resolve(bad,self.normal)['status_flags'])
    def test_missing_shortfall_does_not_remove_supported_horses(self):
        obj=copy.deepcopy(self.normal)
        for h in obj['horses']:
            if h['band']=='B' and h['horse_no']!=10:
                h.update(evidence=[],eligibility='UNVERIFIED',tier='UNVERIFIED',rank_basis='NOT_RANKED',evidence_rank_evidence_ids=[])
                for x in h['family_review'].values():x.update(status='DATA_MISSING',source_refs=[],evidence_ids=[],reason='必要情報なし')
        orders=copy.deepcopy(obj['band_ranks']);orders['B']=[10];set_orders(obj,orders)
        for h in obj['horses']:
            if not h['evidence']:h['nonselect_reason']={'code':'UNVERIFIED','reason':'必要情報なし'}
        self.assertIn('SHORTFALL_MISMATCH',[x['code'] for x in self.inspect(obj)['errors']])
        obj['shortfalls']=[{'band':'B','requested':2,'eligible':1,'missing':1,'reason':'確認済み根拠は1頭'}]
        self.assertFalse(self.inspect(obj)['needs_correction']);self.assertIn(10,self.resolve(obj)['public_candidates'])
    def test_earlier_correction_receipt_is_rejected(self):
        initial=copy.deepcopy(self.normal);initial['horses'][6]['evidence'][0]['facts'][0]['value']=999
        before=self.resolve(initial);responses=attempts(initial,self.normal);responses[1]['received_at']='2026-10-17T14:54:59+09:00'
        after=e.resolve(self.i,{'1st':responses},[],f.NOW);self.assertEqual(after['public_candidates'],before['public_candidates'])
        self.assertTrue(any('before initial' in x.get('error','') for x in after['audit']['1st']['corrections']))
    def test_same_receipt_timestamp_allows_correction(self):
        bad=copy.deepcopy(self.normal);bad['horses'][0].pop('rank_reason');self.assertNotIn('PARTIAL_AI_VALIDATION',self.resolve(bad,self.normal)['status_flags'])
    def test_corrected_holes_survive_cancellation(self):
        bad=copy.deepcopy(self.normal);bad['horses'][6]['family_review']['ability']['evidence_ids']=['missing'];out=self.resolve(bad,self.normal,cancelled=[10])
        self.assertEqual([x['horse_no'] for x in out['candidates'] if x['band']=='B'],[9,8]);self.assertEqual(out['actual_quota'],{'A':2,'B':2,'D':1})
    def test_faulty_role_can_be_published_with_other_ai(self):
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/state.sqlite',lambda:f.NOW);st.install(self.i,1)
            out=st.publish('race',1,0,0,'fault-isolation',{'1st':[{'text':deep_text(self.normal),'received_at':f.RECEIVED}],'2nd':f.responses(self.p)['2nd']})
            self.assertEqual(out['public_candidates'],[1,2,7,8,11]);self.assertEqual(st.read('race')['publication']['publication_id'],out['publication_id']);st.close()

    def test_inverted_frozen_popularity_is_rejected_without_rewriting(self):
        seed=f.seed();seed['runners'][0]['win_rank']=2;seed['runners'][1]['win_rank']=1
        original=copy.deepcopy(seed)
        with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
        self.assertEqual(seed,original)
    def test_equal_win_odds_keep_authoritative_frozen_tie_order(self):
        seed=f.seed();seed['source_registry']['h2_win_odds_6']['value']=seed['source_registry']['h1_win_odds_6']['value']
        p=e.prepare(seed,f.REQUEST);self.assertEqual(p['inputs']['1st']['runners'],seed['runners'])
    def test_missing_win_odds_do_not_hide_other_known_rank_inversion(self):
        seed=f.seed();seed['source_registry']['h2_win_odds_6']['value']=None
        e.prepare(seed,f.REQUEST)
        seed['source_registry']['h1_win_odds_6']['value']=10
        with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)

    def test_unpaired_unicode_cannot_abort_other_ai_publication(self):
        obj=json.loads(r.json_dumps(self.normal));h=obj['horses'][6]
        h['evidence'][0]['evidence_id']='\ud800';h['evidence_rank_evidence_ids']=['\ud800'];h['family_review']['ability']['evidence_ids']=['\ud800']
        text=e.BEGIN+'\n'+json.dumps(obj)+'\n'+e.END
        with tempfile.TemporaryDirectory() as td:
            st=Store(td+'/state.sqlite',lambda:f.NOW);st.install(self.i,1)
            out=st.publish('race',1,0,0,'unicode-isolation',{'1st':[dict(text=text,received_at=f.RECEIVED)],'2nd':f.responses(self.p)['2nd']})
            self.assertEqual(out['public_candidates'],[1,2,7,8,11]);self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE');st.close()
    def test_valid_supplementary_unicode_roundtrips(self):
        text='𠮷・馬・🐎';self.assertEqual(r.strict_loads(json.dumps(text)),text)
        for text in ('"\\ud800"','{"\\udfff":1}'):
            with self.subTest(text=text),self.assertRaises(ValueError):r.strict_loads(text)

    def test_noneligible_rank_metadata_repair_keeps_eligible_order(self):
        good=copy.deepcopy(self.normal);h=good['horses'][6]
        h.update(evidence=[],eligibility='UNVERIFIED',tier='UNVERIFIED',rank_basis='NOT_RANKED',evidence_rank_evidence_ids=[])
        for x in h['family_review'].values():x.update(status='DATA_MISSING',source_refs=[],evidence_ids=[],reason='必要情報なし')
        orders=copy.deepcopy(good['band_ranks']);orders['B']=[10,9,8];set_orders(good,orders)
        h['nonselect_reason']={'code':'UNVERIFIED','reason':'必要情報なし'}
        bad=copy.deepcopy(good);bad['horses'][6]['rank_basis']='EVIDENCE'
        check=self.inspect(bad);self.assertTrue(check['needs_correction']);self.assertEqual(check['repair_scope']['rank_bands'],[])
        out=self.resolve(bad,good);self.assertNotIn('PARTIAL_AI_VALIDATION',out['status_flags']);self.assertEqual(out['public_candidates'],[1,2,10,9,11])

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R8Regressions))
    Path('r8_regression_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    raise SystemExit(not result.wasSuccessful())
