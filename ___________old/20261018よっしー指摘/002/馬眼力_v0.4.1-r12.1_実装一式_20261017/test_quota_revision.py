"""r12.1 acceptance: C7 MIXED keeps four evidenced longshots through publication.
Synthetic observations only. These tests do not measure real-race returns.
"""
import copy
import json
import unittest
from pathlib import Path

import engine as e
import fixtures as f
import reference as r
from test_audit_regressions import set_orders


def mixed(n=16):
    seed = f.seed(n)
    # Remove the synthetic shrinking top gap. X3 and X4 yield MIXED even with X6 unknown.
    seed['source_registry']['h2_win_odds_21']['value'] = seed['source_registry']['h2_win_odds_6']['value']
    return e.prepare(seed, f.REQUEST)


def without_evidence(inp, excluded):
    obj = f.output(inp)
    orders = {b: [n for n in ns if n not in excluded] for b, ns in obj['band_ranks'].items()}
    set_orders(obj, orders)
    for row in obj['horses']:
        if row['horse_no'] not in excluded:
            continue
        row.update(evidence=[], tier='UNVERIFIED', eligibility='UNVERIFIED',
                   rank_basis='NOT_RANKED', evidence_rank_evidence_ids=[],
                   nonselect_reason={'code': 'UNVERIFIED', 'reason': '合成試験：個別根拠が未取得'})
        for review in row['family_review'].values():
            review.update(status='DATA_MISSING', source_refs=[], evidence_ids=[], reason='合成試験：未取得')
    obj['shortfalls'] = [dict(band=b, requested=inp['role_quota'][b], eligible=len(orders[b]),
                              missing=inp['role_quota'][b]-len(orders[b]), reason='合成試験：根拠不足')
                         for b in r.BANDS if len(orders[b]) < inp['role_quota'][b]]
    return obj


def replies(prepared, excluded=()):
    return {role: [dict(text=f.text(without_evidence(inp, excluded)), received_at=f.RECEIVED)]
            for role, inp in prepared['inputs'].items()}


class QuotaRevisionTests(unittest.TestCase):
    def test_c7_mixed_all_sizes_roles_and_final_publication(self):
        for n in (16, 17, 18):
            with self.subTest(n=n):
                p = mixed(n)
                first, second = p['inputs']['1st'], p['inputs']['2nd']
                self.assertEqual(first['mode'], 'MIXED')
                self.assertEqual(first['quota'], dict(A=3, B=3, D=1))
                self.assertEqual(first['role_quota'], dict(A=3, B=3, D=1))
                self.assertEqual(second['role_quota'], dict(A=1, B=3, D=1))
                self.assertEqual(second['role_cap'], 5)
                self.assertEqual(first['snapshot_hash'], second['snapshot_hash'])
                self.assertEqual(f.output(second)['public_candidates'], [1, 7, 8, 9, 11])
                for inp in (first, second):
                    self.assertEqual(len(f.output(inp)['horses']), n)
                    e.validate_input(inp, f.REQUEST)
                out = e.resolve(first, f.responses(p), [], f.NOW)
                self.assertEqual(out['actual_quota'], dict(A=3, B=3, D=1))
                self.assertEqual(out['public_candidates'], [1, 2, 3, 7, 8, 9, 11])
                self.assertFalse(out['status_flags'])

    def test_other_base_allocations_unchanged(self):
        expected = {
            4: {'FIRM': (3, 1, 0), 'MIXED': (2, 2, 0), 'VOLATILE': (2, 1, 1)},
            5: {'FIRM': (4, 1, 0), 'MIXED': (3, 2, 0), 'VOLATILE': (2, 2, 1)},
            6: {'FIRM': (4, 2, 0), 'MIXED': (3, 2, 1), 'VOLATILE': (3, 2, 1)},
            7: {'FIRM': (5, 2, 0), 'MIXED': (3, 3, 1), 'VOLATILE': (3, 3, 1)},
        }
        self.assertEqual(r.BASE, expected)
        self.assertEqual(e.CONFIG['base_quota'], {str(c): {m: list(q) for m, q in modes.items()}
                                                 for c, modes in expected.items()})

    def test_partial_scores_and_single_evidence_keep_four_holes(self):
        p = mixed()
        for inp in p['inputs'].values():
            for row in f.output(inp)['horses']:
                self.assertEqual(len(row['evidence']), 1)
                self.assertEqual(row['score_status'], 'partial')
                self.assertIsNone(row['total_score'])
        out = e.resolve(p['inputs']['1st'], replies(p), [], f.NOW)
        self.assertEqual(out['actual_quota'], dict(A=3, B=3, D=1))

    def test_d_shortfall_fills_from_b_not_a(self):
        p = mixed()
        out = e.resolve(p['inputs']['1st'], replies(p, range(11, 17)), [], f.NOW)
        self.assertEqual(out['actual_quota'], dict(A=3, B=4, D=0))
        self.assertEqual(out['public_candidates'], [1, 2, 3, 7, 8, 9, 10])

    def test_b_shortfall_fills_from_d_not_a(self):
        p = mixed()
        out = e.resolve(p['inputs']['1st'], replies(p, (8, 9, 10)), [], f.NOW)
        self.assertEqual(out['actual_quota'], dict(A=3, B=1, D=3))
        self.assertEqual(len(out['public_candidates']), 7)

    def test_hole_shortage_does_not_add_fourth_popular_horse(self):
        p = mixed()
        out = e.resolve(p['inputs']['1st'], replies(p, range(8, 17)), [], f.NOW)
        self.assertEqual(out['actual_quota'], dict(A=3, B=1, D=0))
        self.assertEqual(out['public_candidates'], [1, 2, 3, 7])
        self.assertTrue(out['status_flags'])

    def test_one_ai_failure_keeps_hole_allocation(self):
        p = mixed()
        for failed in ('1st', '2nd'):
            res = f.responses(p)
            res[failed][0]['text'] = 'truncated'
            out = e.resolve(p['inputs']['1st'], res, [], f.NOW)
            self.assertEqual(out['actual_quota'], dict(A=3, B=3, D=1))
            self.assertIn('SINGLE_AI', out['status_flags'])

    def test_cancellation_crosses_c7_to_c6_with_frozen_ranks(self):
        p = mixed(16)
        out = e.resolve(p['inputs']['1st'], f.responses(p), [1], f.NOW)
        self.assertEqual(out['active_count'], 15)
        self.assertEqual(out['publication_quota'], dict(A=3, B=2, D=1))
        self.assertEqual(out['actual_quota'], dict(A=3, B=2, D=1))
        self.assertNotIn(1, out['public_candidates'])
        self.assertIn(7, out['public_candidates'])

    def test_cancellation_retaining_c7_keeps_four_holes(self):
        p = mixed(18)
        out = e.resolve(p['inputs']['1st'], f.responses(p), [7], f.NOW)
        self.assertEqual(out['active_count'], 17)
        self.assertEqual(out['actual_quota'], dict(A=3, B=3, D=1))
        self.assertTrue({8, 9, 10}.issubset(out['public_candidates']))

    def test_old_c7_quota_rejected_even_after_hash_recalculation(self):
        inp = copy.deepcopy(mixed()['inputs']['1st'])
        for key in ('base_quota', 'quota', 'role_quota'):
            inp[key] = dict(A=4, B=2, D=1)
        inp['snapshot_hash'] = r.canonical_hash(e.common_payload(inp))
        with self.assertRaises(e.ContractError):
            e.validate_input(inp, f.REQUEST)

    def test_old_prompt_response_rejected(self):
        inp = mixed()['inputs']['1st']
        obj = f.output(inp)
        obj['prompt_version'] = 'baganriki-v041-r8-local-recovery-20261017'
        with self.assertRaisesRegex(e.ContractError, 'envelope prompt_version'):
            e.response_view(f.text(obj), inp)

    def test_release_and_prompts_identify_quota_revision(self):
        self.assertEqual(e.CONFIG['prompt_version'], 'baganriki-v041-r12-1-quota-20261017')
        runtime = json.loads(Path('runtime_contract.template.json').read_text())
        self.assertEqual(runtime['implementation']['release'], 'r12.1')
        self.assertEqual(runtime['prompt_version'], e.CONFIG['prompt_version'])
        for name in ('prompt_1st.txt', 'prompt_2nd.txt'):
            text = Path(name).read_text()
            self.assertIn('C=7・MIXED基本配分はA/B/D=3/3/1', text)
            self.assertIn('2ndは最大5頭の中で1/3/1', text)
            self.assertIn(e.CONFIG['prompt_version'], text)


if __name__ == '__main__':
    result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(QuotaRevisionTests))
    Path('quota_revision_results.json').write_text(json.dumps(dict(
        release='r12.1', tests_run=result.testsRun, failures=len(result.failures), errors=len(result.errors),
        synthetic_only=True, real_model_calls=False, production_deployed=False), indent=2)+'\n')
    raise SystemExit(0 if result.wasSuccessful() else 1)
