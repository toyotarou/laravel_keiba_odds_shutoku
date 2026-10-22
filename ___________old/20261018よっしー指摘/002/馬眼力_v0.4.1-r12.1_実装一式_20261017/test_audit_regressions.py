"""Assertions describe required behavior. Regression requirements from the six r6 findings.
Run from this directory after installing requirements-test.txt.
"""
import copy
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path[:0] = [str(ROOT), str(ROOT.parent / 'schema_deps')]
import engine as e
import fixtures as f
import reference as r
from adapter import seed_from_export
from store import Store

def set_orders(obj, orders):
    obj['band_ranks'] = copy.deepcopy(orders)
    obj['evidence_band_ranks'] = copy.deepcopy(orders)
    obj['public_candidates'] = [n for b in r.BANDS for n in orders[b][:obj['role_quota'][b]]]
    for row in obj['horses']:
        selected = row['horse_no'] in obj['public_candidates']
        row['public_candidate'] = selected
        row['nonselect_reason'] = None if selected else {'code': 'BAND_QUOTA', 'reason': '合成試験の帯枠'}
    return obj

def attempts(*objects):
    return [{'text': f.text(obj), 'received_at': f.RECEIVED} for obj in objects]

class R7AuditRegressions(unittest.TestCase):
    def setUp(self):
        self.prepared = e.prepare(f.seed(), f.REQUEST)
        self.inp = self.prepared['inputs']['1st']

    def test_R1_correction_preserves_unrelated_band_order(self):
        seed = f.seed()
        for no in (7, 8, 9, 10):
            seed['source_registry'][f'h{no}_past_performance_None']['value'] = no
        inp = e.prepare(seed, f.REQUEST)['inputs']['1st']
        corrected = f.output(inp)
        for row in corrected['horses']:
            if row['band'] == 'B':
                no = row['horse_no']
                row['evidence'][0]['facts'][0]['value'] = no
                row['evidence'][0]['observation'] = f'同条件の能力指数{no}'
                row['evidence_rank_reason'] = f'確認済みの同条件能力指数{no}を同帯で比較'
        orders = copy.deepcopy(corrected['band_ranks'])
        orders['B'] = [10, 9, 8, 7]
        set_orders(corrected, orders)
        initial = copy.deepcopy(corrected)
        initial['horses'][0].pop('rank_reason')
        scope = e.inspect_for_correction(inp, f.text(initial), f.RECEIVED)['repair_scope']['horses']
        self.assertEqual(scope, [1])
        before = e.resolve(inp, {'1st': attempts(initial)}, [], f.NOW)
        after = e.resolve(inp, {'1st': attempts(initial, corrected)}, [], f.NOW)
        b_candidates = lambda out: [x['horse_no'] for x in out['candidates'] if x['band'] == 'B']
        self.assertEqual(b_candidates(before), [10, 9])
        self.assertEqual(b_candidates(after), b_candidates(before), 'An A-band correction must not replace unchanged B-band candidates')

    def test_R2_complete_band_cannot_claim_evidence_mode(self):
        obj = f.output(self.inp)
        for row in obj['horses']:
            if row['band'] != 'B':
                continue
            no = row['horse_no']
            ref = f'h{no}_past_performance_None'
            row['scores'] = {k: cap - (no - 7) for k, cap in r.CAPS.items()}
            row['score_evidence'] = {k: {'source_refs': [ref], 'reason': '合成試験の採点参照'} for k in r.CAPS}
            row.update(score_status='complete', total_score=sum(row['scores'].values()),
                       observed_sum=sum(row['scores'].values()), observed_cap=100)
        orders = copy.deepcopy(obj['band_ranks'])
        orders['B'] = [10, 9, 8, 7]
        set_orders(obj, orders)
        # All B rows pass current score verification. Only mode/order contradict the prompt.
        view = e.response_view(f.text(obj), self.inp)
        self.assertTrue(all(h['score_status'] == 'complete' for h in view['views'].values() if h['band'] == 'B'))
        out = e.resolve(self.inp, {'1st': attempts(obj)}, [], f.NOW)
        self.assertEqual(out['audit']['1st']['methods']['B'], 'SCORE')
        self.assertEqual([x['horse_no'] for x in out['candidates'] if x['band'] == 'B'], [7, 8])

    def test_R3_public_candidate_flag_mismatch_requests_correction(self):
        prepared = e.prepare(f.seed(18), f.REQUEST)
        obj = f.output(prepared['inputs']['1st'])
        self.assertIn(1, obj['public_candidates'])
        obj['horses'][0]['public_candidate'] = False
        check = e.inspect_for_correction(prepared['inputs']['1st'], f.text(obj), f.RECEIVED)
        self.assertTrue(check['needs_correction'], 'A public list/flag mismatch must not be called valid')
        self.assertTrue(check['errors'], 'Correction diagnostics must identify the public flag inconsistency')

    def test_R4_live_close_keeps_configured_margin(self):
        clock = [f.NOW]
        with tempfile.TemporaryDirectory() as td:
            st = Store(td + '/state.sqlite', lambda: clock[0])
            try:
                st.install(self.inp, 1)
                st.update_live('race', 0, [], '2026-10-17T14:59:00.500000+09:00')
                clock[0] = '2026-10-17T14:59:00+09:00'
                self.assertEqual(self.inp['time_contract']['safety_margin_ms'], 1000)
                with self.assertRaises(ValueError, msg='500 ms remaining cannot satisfy a 1000 ms publication margin'):
                    st.publish('race', 1, 1, 0, 'margin-test', f.responses(self.prepared))
                self.assertIsNone(st.read('race')['publication'])
            finally:
                st.close()

    def test_R5_sales_close_anchor_matches_frozen_close(self):
        seed = f.seed()
        self.assertEqual(seed['time_contract']['anchor_kind'], 'SALES_CLOSE_TIME')
        seed['time_contract']['sales_close_at'] = '2026-10-17T15:30:00+09:00'
        with self.assertRaises(ValueError, msg='A sales-close anchor at 15:00 cannot describe a frozen close at 15:30'):
            e.prepare(seed, f.REQUEST)

    def test_R6_coverage_manifest_survives_adapter_and_snapshot(self):
        seed = f.seed()
        export = {k: copy.deepcopy(seed[k]) for k in ('race_id', 'cutoff_at', 'time_contract', 'runners')}
        categories = ('past_runs', 'OPI', 'estimated_odds', 'recovery_statistics',
                      'similar_statistics', 'legacy_qualifications', 'gap_history')
        export.update(odds_rows=[], coverage={k: {'status': 'UNAVAILABLE', 'reason': '未取得'} for k in categories})
        adapted = seed_from_export(export)
        self.assertEqual(adapted.get('coverage'), export['coverage'], 'The mandatory availability/reason manifest is discarded')
        prepared = e.prepare(adapted, f.REQUEST)
        self.assertEqual(prepared['inputs']['1st'].get('coverage'), export['coverage'])

if __name__ == '__main__':
    import json
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(R7AuditRegressions))
    Path('audit_regression_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors)),indent=2))
    raise SystemExit(not result.wasSuccessful())
