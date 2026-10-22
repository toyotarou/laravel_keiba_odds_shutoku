"""Restart, concurrent dispatch and journal-only publication regressions."""
import copy
import json
import multiprocessing
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import engine as e
import fixtures as f
import reference as r
from store import Store
from test_r7_integration import orders_with

def competing_claim(path, inp, system, ready, start, result):
    store=Store(path,lambda:f.REQUEST)
    ready.put(True)
    if not start.wait(15):raise RuntimeError('start timeout')
    try:result.put(store.claim(inp,1,system)['granted'])
    except Exception as exc:result.put(str(exc))
    finally:store.close()

class JournalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.p=e.prepare(f.seed(),f.REQUEST)
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.path=str(Path(self.tmp.name)/'state.sqlite')
        self.now=f.REQUEST;self.s=Store(self.path,lambda:self.now)
        self.i=copy.deepcopy(self.p['inputs']['1st']);self.s.install(self.i,1)
    def tearDown(self):self.s.close();self.tmp.cleanup()
    def claim(self, role='1st'):
        return self.s.claim(self.p['inputs'][role],1,self.p['prompts'][role])
    def initial(self, role='1st', bad=False, complete=True):
        grant=self.claim(role);obj=orders_with(f.output(self.p['inputs'][role]),[10,9,8,7])
        if bad:obj['horses'][0]['observed_sum']=99
        self.now=f.RECEIVED;raw=f.text(obj)
        self.s.complete(grant['token'],raw,self.now,complete)
        return grant,raw
    def publish(self, job='job'):
        st=self.s.state('race');rev=self.s.response_revision('race',1)
        return self.s.publish_saved('race',1,st['state_revision'],st['publication_revision'],rev,job)

    def test_duplicate_delivery_cannot_regrant_initial(self):
        first=self.claim();second=self.claim()
        self.assertTrue(first['granted']);self.assertFalse(second['granted'])
        self.assertEqual(len(self.s._rows('race',1)),1)
    def test_normal_initial_does_not_spend_correction(self):
        self.initial();self.assertEqual(self.claim()['reason'],'COMPLETE')
    def test_initial_survives_store_restart(self):
        _,raw=self.initial(bad=True);self.s.close();self.s=Store(self.path,lambda:self.now)
        self.assertEqual(self.s.worker_state(self.i,1)['attempts'][0]['text'],raw)
        self.assertEqual(self.claim()['attempt_no'],1)
    def test_crash_after_committed_initial_recovers_correction(self):
        self._crash('initial')
        self.now=f.RECEIVED
        self.assertEqual(len(self.s.worker_state(self.i,1)['attempts']),1)
        self.assertEqual(self.claim()['attempt_no'],1)
    def test_crash_during_correction_keeps_initial_without_regrant(self):
        self._crash('correction');self.now=f.RECEIVED
        self.assertFalse(self.claim()['granted'])
        self.assertEqual(len(self.s.worker_state(self.i,1)['attempts']),1)
        self.assertEqual(self.publish()['public_candidates'],[1,2,10,9,11])
    def _crash(self,boundary):
        code="""
import os,sys
import engine as e,fixtures as f
from store import Store
from test_r7_integration import orders_with
p=e.prepare(f.seed(),f.REQUEST);s=Store(sys.argv[1],lambda:f.REQUEST)
g=s.claim(p['inputs']['1st'],1,p['prompts']['1st'])
s.clock=lambda:f.RECEIVED
o=orders_with(f.output(p['inputs']['1st']),[10,9,8,7]);o['horses'][0]['observed_sum']=99
s.complete(g['token'],f.text(o),f.RECEIVED,True)
if sys.argv[2]=='correction':s.claim(p['inputs']['1st'],1,p['prompts']['1st'])
os._exit(71)
"""
        proc=subprocess.run([sys.executable,'-c',code,self.path,boundary],cwd=Path(__file__).parent,capture_output=True,text=True,timeout=20)
        self.assertEqual(proc.returncode,71,proc.stderr)
    def test_two_processes_get_one_call_grant(self):
        ctx=multiprocessing.get_context('spawn');ready=ctx.Queue();result=ctx.Queue();start=ctx.Event()
        processes=[ctx.Process(target=competing_claim,args=(self.path,self.i,self.p['prompts']['1st'],ready,start,result)) for _ in range(2)]
        try:
            for p in processes:p.start()
            for _ in processes:self.assertTrue(ready.get(timeout=20))
            start.set();values=[result.get(timeout=20) for _ in processes]
            self.assertEqual(sorted(values),[False,True])
            for p in processes:p.join(10);self.assertEqual(p.exitcode,0)
        finally:
            for p in processes:
                if p.is_alive():p.terminate();p.join()
    def test_expired_unknown_reservation_is_not_regranted(self):
        self.claim();self.now='2026-10-17T15:01:00+09:00'
        self.assertFalse(self.claim()['granted'])
        self.assertTrue(self.s.worker_state(self.i,1)['slots'][0]['outcome_unknown'])
    def test_failed_initial_cannot_be_retried_or_corrected(self):
        grant=self.claim();self.s.fail(grant['token'],'PROVIDER_ERROR')
        self.assertFalse(self.claim()['granted'])
    def test_failed_correction_preserves_original_holes(self):
        self.initial(bad=True);grant=self.claim();self.s.fail(grant['token'],'PROVIDER_ERROR')
        self.assertEqual(self.publish()['public_candidates'],[1,2,10,9,11])
        self.assertFalse(self.claim()['granted'])
    def test_two_calls_total_across_restarts(self):
        self.initial(bad=True);grant=self.claim();self.s.complete(grant['token'],'broken',self.now,True)
        self.s.close();self.s=Store(self.path,lambda:self.now)
        self.assertEqual(self.claim()['reason'],'BUDGET_EXHAUSTED')
    def test_receipt_save_is_idempotent_and_immutable(self):
        grant,raw=self.initial();self.s.complete(grant['token'],raw,self.now,True)
        self.assertEqual(self.s.response_revision('race',1),2)
        with self.assertRaises(ValueError):self.s.complete(grant['token'],raw+' ',self.now,True)
        with self.assertRaises(ValueError):self.s.fail(grant['token'],'PROVIDER_ERROR')
    def test_failure_is_idempotent_and_terminal(self):
        grant=self.claim();self.s.fail(grant['token'],'PROVIDER_ERROR');self.s.fail(grant['token'],'PROVIDER_ERROR')
        with self.assertRaises(ValueError):self.s.complete(grant['token'],'x',self.now,True)
    def test_incomplete_raw_retained_but_cannot_select(self):
        _,raw=self.initial(complete=False)
        self.assertEqual(self.s.worker_state(self.i,1)['attempts'][0]['text'],raw)
        self.assertEqual(self.publish()['public_candidates'],[])
        grant=self.claim();self.assertTrue(grant['granted'])
        self.s.complete(grant['token'],raw,self.now,True)
        self.assertEqual(self.publish('fixed')['public_candidates'],[1,2,10,9,11])
    def test_incomplete_correction_does_not_replace_initial(self):
        self.initial(bad=True);grant=self.claim()
        self.s.complete(grant['token'],f.text(f.output(self.i)),self.now,False)
        self.assertEqual(self.publish()['public_candidates'],[1,2,10,9,11])
    def test_roles_are_independent_and_do_not_share_model_text(self):
        self.initial(bad=True);grant=self.claim('2nd')
        self.assertTrue(grant['granted']);self.assertEqual(grant['attempt_no'],0)
        self.assertNotIn('INITIAL_RESPONSE',grant['user'])
        self.assertEqual(self.s.worker_state(self.p['inputs']['2nd'],1)['attempts'],[])
    def test_wrong_generation_system_or_input_rejected(self):
        with self.assertRaises(ValueError):self.s.claim(self.i,2,self.p['prompts']['1st'])
        with self.assertRaises(ValueError):self.s.claim(self.i,1,self.p['prompts']['1st']+'legacy')
        bad=copy.deepcopy(self.i);bad['snapshot_hash']='a'*64
        with self.assertRaises(ValueError):self.s.claim(bad,1,self.p['prompts']['1st'])
        self.assertEqual(self.s._rows('race',1),[])
    def test_bad_token_time_flag_and_size_rejected(self):
        grant=self.claim()
        for token,at,flag,raw in [('unknown',self.now,True,'x'),(grant['token'],f.CUTOFF,True,'x'),
             (grant['token'],f.RECEIVED,True,'x'),(grant['token'],self.now,1,'x'),
             (grant['token'],self.now,True,'x'*(4*1024*1024+1)),(grant['token'],self.now,True,'\ud800')]:
            with self.subTest(at=at,flag=flag,length=len(raw)):
                with self.assertRaises((ValueError,UnicodeError)):self.s.complete(token,raw,at,flag)
        self.assertEqual(self.s._rows('race',1)[0]['status'],'RESERVED')
    def test_late_reply_is_audited_but_never_published(self):
        grant=self.claim();self.now=(r.timestamp(self.i['time_contract']['reply_deadline'])+__import__('datetime').timedelta(microseconds=1)).isoformat()
        self.s.complete(grant['token'],f.text(f.output(self.i)),self.now,True)
        self.assertEqual(len(self.s.worker_state(self.i,1)['attempts']),1)
        self.assertEqual(self.publish()['public_candidates'],[])
    def test_saved_publication_rejects_stale_response_revision(self):
        self.initial();rev=self.s.response_revision('race',1);self.claim('2nd')
        with self.assertRaises(ValueError):self.s.publish_saved('race',1,0,0,rev,'old')
        self.assertEqual(self.s.state('race')['publication_revision'],0)
    def test_publication_retry_remains_idempotent_after_other_role_arrives(self):
        self.initial();one=self.s.publish_saved('race',1,0,0,2,'one')
        self.initial('2nd')
        again=self.s.publish_saved('race',1,0,0,2,'one');self.assertEqual(one,again)
        both=self.publish('both');self.assertNotIn('SINGLE_AI',both['status_flags'])
        self.assertEqual(both['publication_revision'],2)
    def test_manual_aggregation_cannot_drop_saved_good_role(self):
        self.initial();self.publish()
        with self.assertRaises(ValueError):self.s.publish('race',1,0,1,'loss',{})
        self.assertEqual(self.s.read('race')['publication']['public_candidates'],[1,2,10,9,11])
    def test_cancel_overlay_and_saved_republication(self):
        self.initial();self.publish('one');self.s.update_live('race',0,[10])
        out=self.publish('cancel');self.assertNotIn(10,out['public_candidates']);self.assertIn(8,out['public_candidates'])
    def test_earlier_close_stops_dispatch_and_publication(self):
        self.initial();self.s.update_live('race',0,[],self.now)
        self.assertFalse(self.claim('2nd')['granted'])
        with self.assertRaises(ValueError):self.publish()
    def test_read_exposes_revisions_and_status_without_tokens_or_raw(self):
        self.initial();view=self.s.read('race');encoded=r.json_dumps(view)
        self.assertEqual(view['worker_state']['response_revision'],2)
        self.assertEqual(view['live_state']['publication_revision'],0)
        self.assertNotIn('response_text',encoded);self.assertNotIn('token',encoded)
    def test_malformed_first_role_does_not_block_saved_second_role(self):
        grant=self.claim();self.now=f.RECEIVED
        self.s.complete(grant['token'],'['*900+'0'+']'*900,self.now,True)
        self.initial('2nd');out=self.publish()
        self.assertTrue(out['public_candidates']);self.assertEqual(out['audit']['1st']['status'],'AI_FAILURE')
    def test_transport_container_can_exceed_single_response_limit(self):
        grant=self.claim();self.now=f.RECEIVED
        # Two MiB of controls expands to twelve MiB as a JSON string.
        raw='\x00'*(2*1024*1024)
        request=dict(action='complete',token=grant['token'],text=raw,received_at=self.now,complete=False)
        path=Path(self.tmp.name)/'request.json';path.write_text(json.dumps(request))
        proc=subprocess.run([sys.executable,str(Path(__file__).with_name('cli.py')),'--db',self.path,'--request-file',str(path)],capture_output=True,text=True,timeout=20)
        self.assertEqual(proc.returncode,0,proc.stdout+proc.stderr)
        self.assertTrue(json.loads(proc.stdout)['ok'])
        self.assertEqual(self.s.worker_state(self.i,1)['attempts'][0]['text'],raw)
    def test_combined_publication_larger_than_one_response_roundtrips(self):
        seed=f.seed();large='x'*(2*1024*1024+10000);ref='h1_large_fact'
        seed['field_catalog']['large_fact']=dict(unit='text',scope='HORSE',display_label='合成長文',value_type='string',missing_allowed=False,minimum=None,maximum=None)
        source=copy.deepcopy(seed['source_registry']['h1_past_performance_None'])
        source.update(source_ref=ref,field='large_fact',unit='text',value=large,dependency_key=ref)
        seed['source_registry'][ref]=source
        prepared=e.prepare(seed,f.REQUEST)
        store=Store(str(Path(self.tmp.name)/'large.sqlite'),lambda:self.now)
        try:
            store.install(prepared['inputs']['1st'],1)
            for role in ('1st','2nd'):
                inp=prepared['inputs'][role];grant=store.claim(inp,1,prepared['prompts'][role]);obj=f.output(inp)
                row=obj['horses'][0];ev=row['evidence'][0]
                ev.update(source_refs=[ref],dependency_group=ref,facts=[dict(source_ref=ref,value=large)])
                row['family_review']['ability']['source_refs']=[ref]
                raw=f.text(obj);self.assertLess(len(raw.encode()),4*1024*1024)
                self.now=f.RECEIVED;store.complete(grant['token'],raw,self.now,True)
            result=store.publish_saved('race',1,0,0,4,'large')
            self.assertGreater(len(r.json_dumps(result).encode()),4*1024*1024)
            self.assertEqual(store.read('race')['publication'],result)
            self.assertEqual(store.publish_saved('race',1,0,0,4,'large'),result)
        finally:store.close()

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(JournalTests))
    Path(__file__).with_name('journal_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    sys.exit(not result.wasSuccessful())
