"""Live-state validation, clock order, transport facts and release consistency."""
import os,sys
from pathlib import Path
if os.environ.get('BAGANRIKI_SUBJECT'):sys.path.insert(0,os.environ['BAGANRIKI_SUBJECT'])
import copy,json,tempfile,unittest
from decimal import Decimal
import engine as e,reference as r,fixtures as f
from store import Store
from test_r8_regressions import holes

class R12Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.p=e.prepare(f.seed(),f.REQUEST)
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.i=copy.deepcopy(self.p['inputs']['1st']);self.now=f.REQUEST
        self.s=Store(str(Path(self.tmp.name)/'state.sqlite'),lambda:self.now);self.s.install(self.i,1)
    def tearDown(self):self.s.close();self.tmp.cleanup()
    def initial(self):
        grant=self.s.claim(self.i,1,self.p['prompts']['1st']);self.now=f.RECEIVED
        obj=holes(f.output(self.i));obj['horses'][0]['observed_sum']=99
        self.s.complete(grant['token'],f.text(obj),self.now,True)
    def test_unrepresentable_live_deadline_does_not_poison_state(self):
        before=self.s.read('race')
        with self.assertRaises(ValueError):self.s.update_live('race',0,[1],'0001-01-01T00:00:00Z')
        self.assertEqual(self.s.read('race'),before)
        self.assertEqual(self.s.db.execute('SELECT COUNT(*) FROM events').fetchone()[0],0)
    def test_deadline_overflow_is_controlled(self):
        with self.assertRaises(ValueError):r.publication_deadline(f.NOW,'0001-01-01T00:00:00Z',1000)
        with self.assertRaises(ValueError):r.publication_deadline(f.NOW,f.NOW,10**128)
    def test_earlier_past_close_still_valid_and_stops_calls(self):
        self.s.update_live('race',0,[],f.CUTOFF)
        self.assertEqual(self.s.read('race')['live_state']['live_close'],f.CUTOFF)
        self.assertFalse(self.s.claim(self.i,1,self.p['prompts']['1st'])['granted'])
    def test_non_array_cancellation_is_rejected(self):
        for bad in ({},(),{1:'cancelled'},'',None,False):
            with self.subTest(value=bad),self.assertRaises(ValueError):r.cancellation_view(self.i['runners'],bad,self.i['mode'])
    def test_bad_cancellation_update_keeps_previous_state(self):
        for bad in ({},(),{1:'cancelled'},''):
            with self.subTest(value=bad):
                with self.assertRaises(ValueError):self.s.update_live('race',0,bad)
                self.assertEqual(self.s.read('race')['live_state']['state_revision'],0)
    def test_valid_cancellation_keeps_frozen_bands_and_holes(self):
        self.initial();self.s.update_live('race',0,[1,1])
        out=self.s.publish_saved('race',1,1,0,2,'cancel')
        self.assertNotIn(1,out['public_candidates']);self.assertIn(10,out['public_candidates'])
        self.assertIn(9,out['public_candidates']);self.assertEqual(out['actual_quota'],out['publication_quota'])
    def test_commit_clock_rollback_is_not_published(self):
        ticks=iter([f.NOW,f.NOW,f.CUTOFF]);self.s.clock=lambda:next(ticks)
        with self.assertRaises(ValueError):self.s.publish('race',1,0,0,'clock',f.responses(self.p))
        self.assertEqual(self.s.state('race')['publication_revision'],0)
        self.assertEqual(self.s.db.execute('SELECT COUNT(*) FROM publications').fetchone()[0],0)
    def test_verification_clock_rollback_is_not_published(self):
        ticks=iter([f.NOW,f.RECEIVED,f.RECEIVED]);self.s.clock=lambda:next(ticks)
        with self.assertRaises(ValueError):self.s.publish('race',1,0,0,'clock',f.responses(self.p))
        self.assertEqual(self.s.state('race')['publication_revision'],0)
    def test_clock_rollback_cannot_reserve_correction(self):
        self.initial();self.now=f.REQUEST
        grant=self.s.claim(self.i,1,self.p['prompts']['1st'])
        self.assertFalse(grant['granted']);self.assertEqual(grant['reason'],'CLOCK_ROLLBACK')
        self.assertEqual(len(self.s._rows('race',1)),1)
        self.now=f.NOW;self.assertTrue(self.s.claim(self.i,1,self.p['prompts']['1st'])['granted'])
    def test_forward_clock_and_identical_time_can_publish(self):
        for at in (f.NOW,'2026-10-17T14:56:00.000001+09:00'):
            with self.subTest(at=at):
                ticks=iter([f.NOW,f.NOW,at]);self.s.clock=lambda:next(ticks)
                revision=self.s.state('race')['publication_revision']
                out=self.s.publish('race',1,0,revision,at,f.responses(self.p))
                self.assertTrue(out['public_candidates'])
    def test_large_and_precise_fact_values_roundtrip_through_store(self):
        seed=f.seed();values={1:Decimal('9E308'),2:10**80+1,7:Decimal('1.000000000000000001')}
        for no,value in values.items():seed['source_registry'][f'h{no}_past_performance_None']['value']=value
        p=e.prepare(seed,f.REQUEST);s=Store(str(Path(self.tmp.name)/'numbers.sqlite'),lambda:f.NOW)
        try:
            s.install(p['inputs']['1st'],1);responses=f.responses(p)
            for role in ('1st','2nd'):
                obj=f.output(p['inputs'][role])
                for row in obj['horses']:
                    if row['horse_no'] in values:row['evidence'][0]['facts'][0]['value']=values[row['horse_no']]
                responses[role][0]['text']=f.text(obj)
            first=s.publish('race',1,0,0,'numbers',responses);again=s.read('race')['publication']
            self.assertEqual(first,again)
            facts={x['horse_no']:x['explanations']['1st']['facts'][0] for x in again['candidates']}
            for no,value in values.items():
                self.assertEqual(facts[no]['value'],value);self.assertEqual(facts[no]['value_type'],'number')
        finally:s.close()
    def test_text_numeric_and_boolean_fact_types_remain_distinct(self):
        ref='h1_past_performance_None';signal='h1_win_odds_6'
        for value,kind in [('1.000000000000000001','string'),(True,'boolean'),(None,'null')]:
            with self.subTest(kind=kind):
                registry=copy.deepcopy(self.i['source_registry']);registry[ref]['value']=value
                row=f.horse(1);ev=row['evidence'][0]
                ev.update(source_refs=[ref,signal],facts=[dict(source_ref=x,value=registry[x]['value']) for x in (ref,signal)])
                row['evidence'],issues=r.verified_evidence([ev],1,registry);self.assertFalse(issues)
                row['counterevidence']=[dict(source_refs=[ref,signal],reason='comparison')]
                out=r.verified_explanation(row,registry)
                self.assertEqual(out['facts'][0]['value'],value);self.assertEqual(out['facts'][0]['value_type'],kind)
                self.assertEqual(out['counterfacts'][0]['value_type'],kind)

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R12Tests))
    Path(os.environ.get('BAGANRIKI_RESULT',str(Path(__file__).with_name('r12_regression_results.json')))).write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    sys.exit(not result.wasSuccessful())
