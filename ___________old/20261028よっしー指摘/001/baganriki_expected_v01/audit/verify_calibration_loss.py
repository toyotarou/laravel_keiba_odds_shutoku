"""Validate the documented stable calibration objective, not PHP or model fitting.

Run: python3 audit/verify_calibration_loss.py
Only synthetic boundary inputs are used. No coefficients are trained or changed.
"""
import itertools
import json
import math
from decimal import Decimal, localcontext


def stable_loss(p, a, b, y):
    x = min(0.999999, max(0.000001, p))
    z = a * math.log(x / (1.0 - x)) + b
    return max(z, 0.0) - y * z + math.log1p(math.exp(-abs(z)))


def independent_decimal_loss(p, a, b, y):
    # Preserve the exact floating-point input values being checked.
    x = Decimal.from_float(min(0.999999, max(0.000001, p)))
    with localcontext() as context:
        context.prec = 120
        z = Decimal.from_float(a) * (x / (1 - x)).ln() + Decimal.from_float(b)
        q = 1 / (1 + (-z).exp())
        return float(-y * q.ln() - (1 - y) * (1 - q).ln())


def main():
    p, a, b = 0.99, 10.0, 10.0
    z = a * math.log(p / (1 - p)) + b
    q = 1 / (1 + math.exp(-z))
    assert q == 1.0
    examples = []
    for y in [0, 1]:
        try:
            direct = -y * math.log(q) - (1 - y) * math.log(1 - q)
        except ValueError:
            direct = 'math domain error: log(0)'
        assert isinstance(direct, str)
        examples.append({'y': y, 'direct_float_loss': direct,
                         'stable_float_loss': stable_loss(p, a, b, y)})

    probabilities = [0.0, 1e-12, 1e-6, 0.001, 0.01, 0.1, 0.5,
                     0.9, 0.99, 0.999, 1 - 1e-6, 1 - 1e-12, 1.0]
    count = 0
    for p, a, b, y in itertools.product(
        probabilities, [0.1, 1.0, 10.0], [-10.0, 0.0, 10.0], [0, 1]
    ):
        got = stable_loss(p, a, b, y)
        expected = independent_decimal_loss(p, a, b, y)
        assert math.isfinite(got) and got > 0
        assert math.isclose(got, expected, rel_tol=3e-12, abs_tol=0.0), (
            p, a, b, y, got, expected
        )
        count += 1

    print(json.dumps({
        'audit_date_jst': '2026-10-26',
        'test_origin': 'fourth_review',
        'boundary_loss_cases_passed': count,
        'oracle': '120-digit Decimal using the direct mathematical objective',
        'relative_tolerance': 3e-12,
        'absolute_tolerance': 0.0,
        'reproduction': {
            'p_raw': 0.99, 'a': 10.0, 'b': 10.0,
            'z': z, 'float_sigmoid': q, 'outcomes': examples,
        },
        'coefficient_training_performed': False,
        'php_dart_flutter_execution_performed': False,
        'reference_implementation_executed': False,
        'implementation_version': 'baganriki-expected-impl-v0.1.4',
        'scope': 'Numerical implementation guidance for the future calibration training module.'
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
