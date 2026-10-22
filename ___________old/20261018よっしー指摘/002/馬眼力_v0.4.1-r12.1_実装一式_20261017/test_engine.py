import copy,json,tempfile,unittest,hashlib
from pathlib import Path
from decimal import Decimal
import reference as r
import engine as e
from store import Store
import fixtures as f
class EngineTests(unittest.TestCase):
 def setUp(self):self.p=e.prepare(f.seed(),f.REQUEST);self.i=self.p['inputs']['1st'];self.res=f.responses(self.p)
 def test_prepare_exact_hash_and_roles(self):
  self.assertEqual(self.i['mode'],'VOLATILE');self.assertEqual(self.i['quota'],dict(A=2,B=2,D=1));self.assertEqual(hashlib.sha256(self.p['common_json'].encode()).hexdigest(),self.i['snapshot_hash']);self.assertEqual(self.i['snapshot_hash'],self.p['inputs']['2nd']['snapshot_hash'])
 def test_full_pipeline_and_display(self):
  out=e.resolve(self.i,self.res,[],f.NOW);self.assertEqual(out['actual_quota'],dict(A=2,B=2,D=1));self.assertEqual(len(out['public_candidates']),5);self.assertFalse(out['buy_recommended']);self.assertFalse(out['status_flags']);self.assertTrue(all(c['explanations'] for c in out['candidates']))
 def test_missing_horse_corrected(self):
  one=f.output(self.i);one['horses']=[h for h in one['horses'] if h['horse_no']!=7]
  res={'1st':[dict(text=f.text(one),received_at=f.RECEIVED),dict(text=f.text(f.output(self.i)),received_at=f.RECEIVED)]}
  out=e.resolve(self.i,res,[],f.NOW);self.assertIn(7,out['public_candidates']);self.assertIn('CORRECTION_ADOPTED',[x.get('action') for x in out['audit']['1st']['corrections']])
 def test_invalid_correction_preserves_initial(self):
  self.res['1st'].append(dict(text='truncated',received_at=f.RECEIVED));self.assertEqual(len(e.resolve(self.i,self.res,[],f.NOW)['public_candidates']),5)
 def test_partial_score_recovers_band(self):
  obj=f.output(self.i);obj['horses'][6]['scores']['V4']=True;self.res['1st'][0]['text']=f.text(obj);self.assertEqual(e.resolve(self.i,self.res,[],f.NOW)['actual_quota'],dict(A=2,B=2,D=1))
 def test_bad_backup_uses_fallback(self):
  obj=f.output(self.i);obj['evidence_band_ranks']['B']=[7,7];res={'1st':[dict(text=f.text(obj),received_at=f.RECEIVED)]};out=e.resolve(self.i,res,[],f.NOW);self.assertIn('RANK_RECOVERY_FALLBACK',out['status_flags']);self.assertEqual(out['actual_quota']['B'],2)
 def test_cancellation_removes_before_selection(self):
  out=e.resolve(self.i,self.res,[7,11],f.NOW);self.assertFalse({7,11}&set(out['public_candidates']));self.assertEqual(out['active_count'],11);self.assertEqual(out['actual_quota'],out['publication_quota'])
 def test_all_cancelled(self):
  out=e.resolve(self.i,self.res,list(range(1,14)),f.NOW);self.assertEqual(out['public_candidates'],[]);self.assertIn('ALL_CANCELLED',out['status_flags'])
 def test_other_ai_failure_preserves_longshots(self):
  self.res['1st'][0]['text']='broken';out=e.resolve(self.i,self.res,[],f.NOW);self.assertIn('SINGLE_AI',out['status_flags']);self.assertEqual(out['actual_quota']['B'],2)
 def test_wrong_snapshot_rejected_locally(self):
  obj=f.output(self.i);obj['snapshot_hash']='wrong';self.res['1st'][0]['text']=f.text(obj);self.assertIn('SINGLE_AI',e.resolve(self.i,self.res,[],f.NOW)['status_flags'])
 def test_late_and_extra_attempts(self):
  self.res['1st'][0]['received_at']='2026-10-17T14:59:01+09:00';self.assertIn('SINGLE_AI',e.resolve(self.i,self.res,[],f.NOW)['status_flags']);self.res['1st']*=3
  out=e.resolve(self.i,self.res,[],f.NOW)
  self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE');self.assertIn('SINGLE_AI',out['status_flags']);self.assertTrue(out['public_candidates'])
 def test_race_only_evidence_does_not_select(self):
  inp=copy.deepcopy(self.i);ref='race-only';s=copy.deepcopy(next(iter(inp['source_registry'].values())));s.update(source_ref=ref,scope='RACE',horse_nos=[],field='race_signal',slot_offset=None,value=1);inp['source_registry'][ref]=s;inp['field_catalog']['race_signal']=dict(unit=s['unit'],scope='RACE',display_label='race',value_type='number',minimum=None,maximum=None,missing_allowed=True);inp['snapshot_hash']=r.canonical_hash(e.common_payload(inp));obj=f.output(inp)
  for h in obj['horses']:
   ev=h['evidence'][0];ev['source_refs']=[ref];ev['facts']=[dict(source_ref=ref,value=1)]
  self.assertEqual(e.resolve(inp,{'1st':[dict(text=f.text(obj),received_at=f.RECEIVED)]},[],f.NOW)['public_candidates'],[])
 def test_live_blocked_and_input_mutation_blocked(self):
  seed=f.seed();seed['learning_stage']='LIVE_VALIDATED'
  with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
  inp=copy.deepcopy(self.i);inp['quota']['B']=0
  with self.assertRaises(ValueError):e.validate_input(inp,f.REQUEST)
 def test_wrong_source_index_and_formula(self):
  seed=f.seed();seed['input_indices']['horse/7/win_odds/6']=['h8_win_odds_6']
  with self.assertRaises(ValueError):e.prepare(seed,f.REQUEST)
  reg=copy.deepcopy(self.i['source_registry']);ref='h7_past_performance_None';reg['d']=dict(reg[ref],source_ref='d',source_kind='DERIVED',parents=[ref],formula_id='unknown',formula_version='1',computed_at=f.REQUEST)
  with self.assertRaises(ValueError):e.formula_check(reg)
 def test_decimal_input_survives_roundtrip(self):
  v=r.strict_loads('{"v":1.49999999999999999}')['v'];self.assertEqual(v,Decimal('1.49999999999999999'));self.assertLess(v,Decimal('1.50'));self.assertEqual(r.strict_loads(r.json_dumps({'v':v}))['v'],v)
 def test_r5_remaining_guards(self):
  from test_reference import row,registry,CATALOG,evidence
  for flag in ('false',1,None):
   out,_=r.keep_correction({7:row(7,True)},{7:dict(row(7),row_valid=flag)},{7},True,[7]);self.assertEqual(out[7]['score_status'],'partial')
  ev=evidence();ev['source_refs']={'h7':'bad'};self.assertFalse(r.verified_evidence([ev],7,registry())[0])
  reg=registry();reg['p']=dict(reg['h7'],source_ref='p',source_kind='DERIVED',parents=['h7'],formula_id='identity',formula_version='1',computed_at='2026-10-17T14:53:00+09:00');reg['c']=dict(reg['p'],source_ref='c',parents=['p'],computed_at='2026-10-17T14:51:00+09:00')
  with self.assertRaises(ValueError):r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',[7,8],CATALOG)
 def test_long_graph_iterative(self):
  from test_reference import registry,CATALOG
  reg=registry();prev='h7'
  for n in range(1100):
   key=f'd{n}';reg[key]=dict(reg['h7'],source_ref=key,source_kind='DERIVED',parents=[prev],formula_id='identity',formula_version='1',computed_at='2026-10-17T14:50:02+09:00');prev=key
  r.validate_registry(reg,'race','2026-10-17T14:54:00+09:00',[7,8],CATALOG);self.assertEqual(r.source_roots(prev,reg),{'root7'})
 def test_N18_and_correction_budget(self):
  p=e.prepare(f.seed(18),f.REQUEST);out=e.resolve(p['inputs']['1st'],f.responses(p),[],f.NOW);self.assertEqual(len(out['public_candidates']),7);self.assertEqual(p['inputs']['2nd']['role_cap'],5)
 def test_atomic_store_idempotency_stale_and_postdeadline_overlay(self):
  with tempfile.TemporaryDirectory() as td:
   clock=[f.NOW];st=Store(td+'/state.sqlite',lambda:clock[0]);st.install(self.i,1);a=st.publish('race',1,0,0,'job1',self.res);self.assertEqual(st.publish('race',1,0,0,'job1',self.res)['publication_id'],a['publication_id'])
   with self.assertRaises(ValueError):st.publish('race',1,0,0,'late-old',self.res)
   st.update_live('race',0,[7]);b=st.publish('race',1,1,1,'job2',self.res);self.assertNotIn(7,b['public_candidates']);clock[0]='2026-10-17T15:01:00+09:00';st.update_live('race',1,[7,8])
   with self.assertRaises(ValueError):st.publish('race',1,2,2,'too-late',self.res)
   read=st.read('race');self.assertEqual(read['publication']['publication_id'],b['publication_id']);self.assertEqual(read['live_state']['cancelled'],[7,8]);st.close()
 def test_distinct_connections_reject_old_revision(self):
  with tempfile.TemporaryDirectory() as td:
   a=Store(td+'/state.sqlite',lambda:f.NOW);b=Store(td+'/state.sqlite',lambda:f.NOW);a.install(self.i,1);a.update_live('race',0,[7])
   with self.assertRaises(ValueError):b.update_live('race',0,[8])
   with self.assertRaises(ValueError):b.publish('race',1,0,0,'old',self.res)
   a.close();b.close()
 def test_export_adapter_decimal_and_duplicate(self):
  from adapter import seed_from_export
  src=f.seed();export={k:copy.deepcopy(src[k]) for k in ('race_id','cutoff_at','time_contract','runners')}
  export.update(odds_rows=[],extra_sources=[],extra_field_catalog={'past_performance':src['field_catalog']['past_performance']},extra_indices={},coverage={key:dict(status='UNAVAILABLE',reason='synthetic example') for key in ('past_runs','OPI','estimated_odds','recovery_statistics','similar_statistics','legacy_qualifications','gap_history')})
  for no in range(1,14):
   for slot in (21,18,15,12,9,6):
    source=src['source_registry'][f'h{no}_win_odds_{slot}']
    row=dict(num=no,minutes_before_start=slot,observed_at=source['observed_at'],available_at=source['available_at'])
    for column,field in [('odds','win_odds'),('fuku_min','place_min'),('fuku_max','place_max')]:row[column]=str(src['source_registry'][f'h{no}_{field}_{slot}']['value'])
    export['odds_rows'].append(row)
   extra=copy.deepcopy(src['source_registry'][f'h{no}_past_performance_None']);extra['value']=str(extra['value']);export['extra_sources'].append(extra)
  prepared=e.prepare(seed_from_export(export),f.REQUEST);self.assertEqual(prepared['inputs']['1st']['mode'],self.i['mode'])
  export['odds_rows'].append(export['odds_rows'][0])
  with self.assertRaises(ValueError):seed_from_export(export)
 def test_unknown_mode_does_not_select(self):
  seed=f.seed()
  for source in seed['source_registry'].values():
   if source['field']!='past_performance':source['value']=None
  p=e.prepare(seed,f.REQUEST);self.assertEqual(p['inputs']['1st']['mode'],'UNKNOWN')
  out=e.resolve(p['inputs']['1st'],f.responses(p),[],f.NOW);self.assertEqual(out['public_candidates'],[]);self.assertIn('SELECTION_DISABLED',out['status_flags'])
 def test_inspection_agrees_with_correction_scope(self):
  obj=f.output(self.i);obj['public_candidates']=[]
  check=e.inspect_for_correction(self.i,f.text(obj),f.RECEIVED);self.assertTrue(check['needs_correction']);self.assertTrue(check['repair_scope']['public_candidates']);self.assertEqual(check['repair_scope']['horses'],[])
  self.assertFalse(e.inspect_for_correction(self.i,f.text(f.output(self.i)),f.RECEIVED)['needs_correction'])
 def test_commit_rechecks_clock(self):
  with tempfile.TemporaryDirectory() as td:
   clock=[f.NOW];st=Store(td+'/state.sqlite',lambda:clock[0]);st.install(self.i,1)
   calls=[0]
   def ticking():
    calls[0]+=1
    return f.NOW if calls[0]==1 else '2026-10-17T15:00:01+09:00'
   st.clock=ticking
   with self.assertRaises(ValueError):st.publish('race',1,0,0,'latecompute',self.res)
   self.assertIsNone(st.read('race')['publication']);st.close()
if __name__=='__main__':
 result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(EngineTests));Path('engine_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors),scope='synthetic local engine and SQLite; no live AI/production DB'),indent=2));raise SystemExit(not result.wasSuccessful())
