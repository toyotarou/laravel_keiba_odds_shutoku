"""Run against r10 or set BAGANRIKI_SUBJECT to the previous release for red tests."""
import os,sys
from pathlib import Path
if os.environ.get('BAGANRIKI_SUBJECT'):sys.path.insert(0,os.environ['BAGANRIKI_SUBJECT'])
import copy,json,tempfile,threading,unittest
from unittest.mock import patch
from datetime import timedelta
import engine as e,reference as r,fixtures as f
from store import Store

class R10Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.p=e.prepare(f.seed(),f.REQUEST)
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.path=str(Path(self.tmp.name)/'state.sqlite')
        self.now=f.REQUEST;self.s=Store(self.path,lambda:self.now);self.i=self.p['inputs']['1st'];self.s.install(self.i,1)
    def tearDown(self):self.s.close();self.tmp.cleanup()
    def claim(self,role='1st'):
        return self.s.claim(self.p['inputs'][role],1,self.p['prompts'][role])
    def save(self,role='1st',bad=False):
        grant=self.claim(role);self.now=f.RECEIVED;obj=f.output(self.p['inputs'][role])
        if bad:obj['horses'][0]['observed_sum']=99
        self.s.complete(grant['token'],f.text(obj),self.now,True)
    def test_bad_role_container_preserves_other_role(self):
        good=f.responses(self.p)['2nd']
        for bad in (None,True,0,'',{},[{}, {}, {}]):
            with self.subTest(value=bad):
                out=e.resolve(self.i,{'1st':bad,'2nd':good},[],f.NOW)
                self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE')
                self.assertIn('SINGLE_AI',out['status_flags']);self.assertTrue(out['public_candidates'])
    def test_bad_attempt_entries_preserve_other_role(self):
        for bad in (None,True,0,'',[],{},dict(received_at=None,text='x')):
            with self.subTest(value=bad):
                out=e.resolve(self.i,{'1st':[bad],'2nd':f.responses(self.p)['2nd']},[],f.NOW)
                self.assertTrue(out['public_candidates']);self.assertIn('SINGLE_AI',out['status_flags'])
    def test_bad_attempt_cannot_discard_prior_valid_holes(self):
        from test_r7_integration import orders_with
        good=orders_with(f.output(self.i),[10,9,8,7])
        for bad in (None,{},dict(received_at=f.RECEIVED,text='broken'),dict(received_at=f.RECEIVED,text=f.text(f.output(self.i)),complete=False)):
            with self.subTest(value=type(bad).__name__):
                out=e.resolve(self.i,{'1st':[dict(text=f.text(good),received_at=f.RECEIVED),bad]},[],f.NOW)
                self.assertEqual(out['public_candidates'],[1,2,10,9,11])
    def test_generated_input_over_limit_rejected_during_prepare(self):
        seed=f.seed();seed['coverage']['OPI']['reason']='x'*(4*1024*1024-len(r.json_dumps(seed).encode())-10)
        self.assertLess(len(r.json_dumps(seed).encode()),4*1024*1024)
        with self.assertRaisesRegex(ValueError,'input.*size|size.*input'):e.prepare(seed,f.REQUEST)
    def test_direct_oversized_snapshot_rejected_before_install(self):
        inp=copy.deepcopy(self.i);inp['coverage']['OPI']['reason']='x'*(4*1024*1024)
        inp['snapshot_hash']=r.canonical_hash(e.common_payload(inp))
        with self.assertRaisesRegex(ValueError,'input.*size|size.*input'):self.s.install(inp,1)
        self.assertEqual(self.s.state('race')['snapshot_hash'],self.i['snapshot_hash'])
    def test_all_cancelled_does_not_dispatch(self):
        self.s.update_live('race',0,[h['horse_no'] for h in self.i['runners']])
        grant=self.claim();self.assertFalse(grant['granted']);self.assertEqual(grant['reason'],'ALL_CANCELLED')
        self.assertEqual(self.s._rows('race',1),[])
    def test_live_close_rejects_false_values_and_accepts_same_instant(self):
        for value in ('',False,0,[]):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):self.s.update_live('race',0,[],value)
                self.assertEqual(self.s.state('race')['state_revision'],0)
        from datetime import timezone
        alias=r.timestamp(self.i['time_contract']['sales_close_at']).astimezone(timezone.utc).isoformat()
        self.assertEqual(self.s.update_live('race',0,[],alias)['state_revision'],0)
    def test_correction_diagnostics_crossing_deadline_cannot_reserve(self):
        self.save(bad=True);original=e.inspect_for_correction
        def inspect(*args):
            out=original(*args);self.now=(r.timestamp(self.i['time_contract']['reply_deadline'])+timedelta(microseconds=1)).isoformat();return out
        with patch.object(e,'inspect_for_correction',inspect):grant=self.claim()
        self.assertFalse(grant['granted']);self.assertEqual(len(self.s._rows('race',1)),1)
    def _pause_compute(self, name, operation, concurrent):
        entered=threading.Event();release=threading.Event();results=[];original=getattr(e,name)
        def slow(*args,**kwargs):
            entered.set()
            if not release.wait(10):raise RuntimeError('gate timeout')
            return original(*args,**kwargs)
        def worker():
            s=Store(self.path,lambda:self.now)
            try:results.append(operation(s))
            except Exception as exc:results.append(exc)
            finally:s.close()
        self.s.db.execute('PRAGMA busy_timeout=100')
        with patch.object(e,name,slow):
            thread=threading.Thread(target=worker);thread.start()
            try:
                self.assertTrue(entered.wait(10));concurrent()
            finally:release.set();thread.join(15)
        self.assertFalse(thread.is_alive());self.assertEqual(len(results),1)
        return results[0]
    def test_slow_correction_does_not_block_other_role_save(self):
        self.save(bad=True);second=self.claim('2nd')
        out=self._pause_compute('inspect_for_correction',lambda s:s.claim(self.i,1,self.p['prompts']['1st']),
            lambda:self.s.complete(second['token'],f.text(f.output(self.p['inputs']['2nd'])),self.now,True))
        if isinstance(out,Exception):raise out
        self.assertTrue(out['granted']);self.assertEqual(len(self.s.saved_responses('race',1)['2nd']),1)
    def test_slow_publication_allows_response_save_and_rejects_stale_result(self):
        self.save();second=self.claim('2nd');rev=self.s.response_revision('race',1)
        out=self._pause_compute('resolve',lambda s:s.publish_saved('race',1,0,0,rev,'slow'),
            lambda:self.s.complete(second['token'],f.text(f.output(self.p['inputs']['2nd'])),self.now,True))
        self.assertIsInstance(out,ValueError);self.assertIn('stale',str(out))
        self.assertIsNone(self.s.read('race')['publication'])
        fresh=self.s.publish_saved('race',1,0,0,self.s.response_revision('race',1),'fresh')
        self.assertNotIn('SINGLE_AI',fresh['status_flags'])
    def test_slow_publication_allows_cancellation_and_rejects_stale_result(self):
        self.save();rev=self.s.response_revision('race',1)
        out=self._pause_compute('resolve',lambda s:s.publish_saved('race',1,0,0,rev,'slow'),lambda:self.s.update_live('race',0,[1]))
        self.assertIsInstance(out,ValueError);self.assertIn('stale',str(out));self.assertIsNone(self.s.read('race')['publication'])
    def test_slow_publication_honours_new_earlier_close(self):
        self.save();rev=self.s.response_revision('race',1)
        out=self._pause_compute('resolve',lambda s:s.publish_saved('race',1,0,0,rev,'slow'),lambda:self.s.update_live('race',0,[],self.now))
        self.assertIsInstance(out,ValueError);self.assertIsNone(self.s.read('race')['publication'])
    def test_slow_claim_honours_new_all_cancelled_state(self):
        self.save(bad=True)
        out=self._pause_compute('inspect_for_correction',lambda s:s.claim(self.i,1,self.p['prompts']['1st']),
            lambda:self.s.update_live('race',0,[h['horse_no'] for h in self.i['runners']]))
        if isinstance(out,Exception):raise out
        self.assertFalse(out['granted']);self.assertEqual(out['reason'],'ALL_CANCELLED')
    def test_live_count_reflects_cancel_without_republishing(self):
        self.save();self.s.publish_saved('race',1,0,0,2,'initial');self.s.update_live('race',0,[1,2])
        view=self.s.read('race');self.assertEqual(view['publication']['active_count'],13)
        self.assertEqual(view['live_state']['active_count'],11)
        self.assertEqual(view['live_state']['publication_target_count'],5)
    def _compete(self, function, operations):
        barrier=threading.Barrier(2);original=getattr(e,function);results=[]
        def together(*args,**kwargs):
            barrier.wait(timeout=10);return original(*args,**kwargs)
        def worker(operation):
            s=Store(self.path,lambda:self.now)
            try:results.append(operation(s))
            except Exception as exc:results.append(exc)
            finally:s.close()
        with patch.object(e,function,together):
            threads=[threading.Thread(target=worker,args=(op,)) for op in operations]
            for thread in threads:thread.start()
            for thread in threads:thread.join(15);self.assertFalse(thread.is_alive())
        self.assertEqual(len(results),2);return results
    def test_concurrent_same_publication_intent_commits_once(self):
        self.save();operation=lambda s:s.publish_saved('race',1,0,0,2,'same')
        results=self._compete('resolve',[operation,operation])
        for out in results:
            if isinstance(out,Exception):raise out
        self.assertEqual(results[0],results[1]);self.assertEqual(self.s.state('race')['publication_revision'],1)
    def test_concurrent_different_publication_intents_one_wins(self):
        self.save()
        results=self._compete('resolve',[lambda s:s.publish_saved('race',1,0,0,2,'one'),lambda s:s.publish_saved('race',1,0,0,2,'two')])
        self.assertEqual(sum(isinstance(x,dict) for x in results),1)
        failure=next(x for x in results if isinstance(x,Exception));self.assertIsInstance(failure,ValueError);self.assertIn('stale',str(failure))
        self.assertEqual(self.s.state('race')['publication_revision'],1)
    def test_concurrent_corrections_get_one_grant(self):
        self.save(bad=True);operation=lambda s:s.claim(self.i,1,self.p['prompts']['1st'])
        results=self._compete('inspect_for_correction',[operation,operation])
        for out in results:
            if isinstance(out,Exception):raise out
        self.assertEqual(sorted(x['granted'] for x in results),[False,True]);self.assertEqual(len(self.s._rows('race',1)),2)
    def test_exact_input_limit_can_save_and_read_without_truncation(self):
        inp=copy.deepcopy(self.i);inp['coverage']['OPI']['reason']=''
        size=len(r.json_dumps(inp).encode());inp['coverage']['OPI']['reason']='x'*(4*1024*1024-size)
        inp['snapshot_hash']=r.canonical_hash(e.common_payload(inp));self.assertEqual(len(r.json_dumps(inp).encode()),4*1024*1024)
        s=Store(str(Path(self.tmp.name)/'exact.sqlite'),lambda:f.REQUEST)
        try:
            s.install(inp,1);self.assertEqual(s.read('race')['input_audit']['coverage']['OPI']['reason'],inp['coverage']['OPI']['reason'])
        finally:s.close()

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R10Tests))
    Path(os.environ.get('BAGANRIKI_RESULT',str(Path(__file__).with_name('r10_regression_results.json')))).write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    sys.exit(not result.wasSuccessful())
