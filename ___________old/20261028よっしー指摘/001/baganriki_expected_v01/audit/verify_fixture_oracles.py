"""Recompute successful fixture answers independently; never executes PHP/Dart.

Run with Python 3.9+ from any working directory. Decimal uses the documented
equations directly, rather than the numerically rearranged PHP expression.
Rejection fixtures remain for the PHP/Dart runtimes; they are not passed here.
"""
import contextlib
import io
import json
import math
import runpy
from decimal import Decimal, ROUND_HALF_UP, localcontext
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
D = lambda value: Decimal(str(value))


def baseline(args):
    m = args['m']
    return {
        'm': m, 'b3': args['b3'], 'b5': args['b5'],
        'p0_3': (D(args['b3']) + 1) / (D(m) + 2) if m else None,
        'p0_5': (D(args['b5']) + 1) / (D(m) + 2) if m else None,
    }


def calculation(args):
    base = baseline(args)
    rows = args['samples']
    weights = [D(row['w']) for row in rows]
    total = sum(weights, D(0))
    squares = sum((w * w for w in weights), D(0))
    lam = D(args['lambda'])
    weighted = {
        k: sum((w * row['y' + k] for w, row in zip(weights, rows)), D(0))
        for k in ['3', '5']
    }
    count = sum(w > 0 for w in weights)
    neff = total * total / squares if squares else D(0)
    calibrated = bool(args['calibration'] is not None and args['m'] and total)
    out = {
        'baseline': base, 'W': total, 'A3': weighted['3'], 'A5': weighted['5'],
        'n_eff': neff, 'neighbour_count': count,
        'local_weight_share': total / (total + lam),
        'comparison_only': True, 'validated_for_live': False,
        'calibration_applied': calibrated,
    }
    if not args['m']:
        status, basis = 'UNAVAILABLE_BASELINE', 'UNAVAILABLE'
    elif not total:
        status, basis = 'BASELINE_ONLY', 'RAW_UNCALIBRATED'
    else:
        low = args['m'] < 100 or count < 20 or total < 10 or neff < 15
        status = 'LOW_SUPPORT' if low else 'COMPARISON_READY'
        basis = 'CALIBRATION_APPLIED' if calibrated else 'RAW_UNCALIBRATED'
    out.update(support_status=status, score_basis=basis)
    for k in ['3', '5']:
        p0 = base['p0_' + k]
        raw = (weighted[k] + lam * p0) / (total + lam) if p0 is not None else None
        cal = None
        if calibrated:
            clipped = max(D('0.000001'), min(D('0.999999'), raw))
            c = args['calibration']
            z = D(c['a']) * (clipped / (1 - clipped)).ln() + D(c['b'])
            cal = 1 / (1 + (-z).exp())
        selected = cal if calibrated else raw
        out['p' + k + '_raw'] = raw
        out['p' + k + '_cal'] = cal
        out['trial_score_' + k] = None if selected is None else (
            (100 * selected).quantize(D('0.1'), rounding=ROUND_HALF_UP)
        )
    return out


def distance(args):
    c, h = args['current'], args['historical']
    co, ho = [D(x) for x in c['odds6_by_rank']], [D(x) for x in h['odds6_by_rank']]
    n, r = len(co), args['rank'] - 1
    profile = sum((
        ((co[i + 1] / co[i]).ln() - (ho[i + 1] / ho[i]).ln()) ** 2
        for i in range(n - 1)
    ), D(0)) / (n - 1) / D('0.35') ** 2 if n > 1 else D(0)
    price = ((co[r] / ho[r]).ln() / D('0.70')) ** 2
    movement = (((D(c['odds21_at_rank']) / co[r]).ln()
                 - (D(h['odds21_at_rank']) / ho[r]).ln()) / D('0.35')) ** 2
    metres = ((D(c['distance_metres']) - D(h['distance_metres'])) / 400) ** 2
    mismatch = int(c['class_group'] != h['class_group'])
    return (profile + price + movement + metres + mismatch) / 5


def weight(args):
    exponent = -D(args['distance_squared']) / (2 * D(args['bandwidth']) ** 2)
    exponent -= D(2).ln() * D(args['age_days']) / D(args['half_life_days'])
    # exp(-1000) is already below binary64's minimum positive value.
    return D(0) if exponent < -1000 else exponent.exp()


def compare(actual, expected, label):
    if isinstance(expected, dict):
        for key, value in expected.items():
            assert key in actual, (label, key)
            compare(actual[key], value, label + '.' + key)
    elif type(expected) in (int, float):
        assert type(actual) in (int, float, Decimal), (label, actual)
        value = float(actual)
        assert math.isfinite(value) and math.isclose(
            value, expected, rel_tol=1e-9, abs_tol=0.0
        ), (label, value, expected)
    else:
        assert type(actual) is type(expected) and actual == expected, (label, actual, expected)


def main():
    fixture = json.loads((ROOT / 'test_vectors.json').read_text())
    methods = {'baseline': baseline, 'calculate': calculation,
               'distanceSquared': distance, 'weight': weight}
    checked = []
    with localcontext() as ctx:
        ctx.prec = 100
        for case in fixture['cases']:
            if 'expected' not in case:
                continue
            actual = methods[case['method']](case['arguments'])
            compare(actual, case['expected'], case['id'])
            checked.append(case['id'])

        # Reuse the separate transport-contract check, explicitly not Dart code.
        captured = io.StringIO()
        with contextlib.redirect_stdout(captured):
            previous = runpy.run_path(str(ROOT / 'audit/verify_python.py'))
        existing = json.loads(captured.getvalue())
        display = json.loads((ROOT / 'display/display_test_vectors.json').read_text())
        projected = []
        for case in display['server_cases']:
            if not case['expected_accept']:
                continue
            payload = case['expected']
            previous['check_client'](payload, case['identity'])
            if payload['expectation_value'] is not None:
                calc = calculation(case['math_arguments']) if 'math_arguments' in case else case['calculation']
                for k, field in [('3', 'top3_expectation_value'), ('5', 'expectation_value')]:
                    compare(calc['trial_score_' + k], payload[field], case['id'] + '.' + field)
            projected.append(case['id'])

    print(json.dumps({
        'math_fixture_answers_checked': len(checked),
        'math_fixture_answer_ids': checked,
        'php_rejection_cases_not_executed': len(fixture['cases']) - len(checked),
        'server_expected_payloads_checked_against_python_contract': len(projected),
        'server_expected_payload_ids': projected,
        'repeated_independent_checks': existing,
        'php_dart_flutter_execution_performed': False,
        'note': 'This verifies fixture answers and Python equations, not execution of the supplied PHP or Dart programs.'
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
