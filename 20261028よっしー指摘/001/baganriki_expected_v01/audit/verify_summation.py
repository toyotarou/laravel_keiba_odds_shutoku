"""Independent checks for v0.1.4 summation; does not execute PHP or Dart."""
import json
import math
import random
from decimal import Decimal, localcontext


def compensated(values):
    total = 0.0
    correction = 0.0
    for value in values:
        following = total + value
        if total >= value:
            correction += (total - following) + value
        else:
            correction += (value - following) + total
        total = following
    return total + correction


def ordinary(values):
    total = 0.0
    for value in values:
        total += value
    return total


def main():
    boundary = []
    for name, w, below in [
        ('just_below', 0.09999999999999, True),
        ('at_threshold', 0.1, False),
        ('just_above', 0.10000000000001, False),
    ]:
        values = [w] * 100
        with localcontext() as ctx:
            ctx.prec = 400
            exact = sum((Decimal.from_float(v) for v in values), Decimal(0))
        actual = compensated(values)
        assert (actual < 10.0) == below == (exact < 10)
        assert actual == math.fsum(values) == float(exact)
        boundary.append({'case': name, 'each_weight': w, 'count': 100,
                         'ordinary_sum': ordinary(values), 'compensated_sum': actual,
                         'exact_binary_input_sum': str(exact), 'below_10': below})
    assert boundary[1]['ordinary_sum'] < 10.0
    assert compensated([]) == 0.0

    rng = random.Random(20261026)
    permutations = 0
    for _ in range(500):
        weights = [10.0 ** rng.uniform(-280, 0) for _ in range(rng.randint(1, 200))]
        outcomes = [rng.randrange(3) for _ in weights]
        lam = 10.0 ** rng.uniform(-280, 280)
        total = compensated(weights)
        maximum = max(weights)
        scaled = [w / maximum for w in weights]
        ss = compensated(scaled)
        neff = ss * ss / compensated([w * w for w in scaled])
        with localcontext() as ctx:
            ctx.prec = 400
            dw = [Decimal.from_float(w) for w in weights]
            dl = Decimal.from_float(lam)
            ds = sum(dw, Decimal(0))
            dneff = ds * ds / sum((w * w for w in dw), Decimal(0))
            expected_sum = float(ds)
            assert math.isclose(neff, float(dneff), rel_tol=3e-13, abs_tol=0.0)
            for k, p0 in [(0, 0.1), (1, 0.2)]:
                local = compensated([w for w, y in zip(scaled, outcomes) if y <= k]) / ss
                actual = total / (total + lam) * local + lam / (total + lam) * p0
                da = sum((w for w, y in zip(dw, outcomes) if y <= k), Decimal(0))
                expected = float((da + dl * Decimal.from_float(p0)) / (ds + dl))
                assert math.isclose(actual, expected, rel_tol=3e-13, abs_tol=0.0)
        for order in [weights, list(reversed(weights)), sorted(weights)]:
            actual_sum = compensated(order)
            assert math.isclose(actual_sum, expected_sum, rel_tol=3e-15, abs_tol=0.0)
            assert math.isclose(actual_sum, math.fsum(order), rel_tol=3e-15, abs_tol=0.0)
            permutations += 1

    print(json.dumps({
        'audit_date_jst': '2026-10-26',
        'implementation_version': 'baganriki-expected-impl-v0.1.4',
        'threshold_cases_passed': len(boundary),
        'boundary_details': boundary,
        'random_numeric_cases_passed': 500,
        'summation_order_checks_passed': permutations,
        'empty_sum_passed': True,
        'oracle': '400-digit Decimal of exact binary inputs, plus math.fsum',
        'php_dart_flutter_execution_performed': False,
        'scope': 'Python numerical reproduction and counterpart; PHP runtime checks remain required.'
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
