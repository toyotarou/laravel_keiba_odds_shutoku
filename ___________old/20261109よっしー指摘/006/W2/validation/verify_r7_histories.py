"""Verify historical revisions and horse-level odds against independently authored eligibility and E3/E5."""
from copy import deepcopy
from datetime import datetime, time, timedelta
import json
import math
import random
import subprocess

from verify_r6_packets import DAY, TOKYO, PROBE, calculate, fixture


def run(php, seed):
    rng = random.Random(seed)
    cutoff = datetime.combine(DAY, time(0), TOKYO)
    before = (cutoff - timedelta(microseconds=1)).isoformat()
    def read(target, rows):
        request = json.dumps(dict(current=target, history=rows, calibration=None), separators=(',', ':'))
        response = subprocess.run([php, '-n', str(PROBE)], input=request, text=True, capture_output=True, check=True)
        return json.loads(response.stdout)
    checked = 0
    for case in range(36):
        n = (4, 8, 12, 16, 18, 6)[case % 6]
        current, target = fixture(rng, 'today-'+str(case), 0, rng.sample(range(1, 19), n), False)
        payload, admitted, diagnostics = [], [], {}
        for mode in range(9):
            key = f'h-{case:02}-{mode}'
            race, ref = fixture(rng, key, rng.randint(1, 90), rng.sample(range(1, 19), n), True)
            payload.append(race)
            all_nos = sorted(ref['early'])
            unavailable, ambiguous, issue = [], [], None
            if mode == 1:
                # Several quotes for known horses. A second reading must not silently become canonical.
                ambiguous = sorted(rng.sample(all_nos, min(3, n)))
                unavailable = ambiguous.copy()
                for no in ambiguous:
                    for value in ('9.91','21.55'):
                        race['batches']['21']['quotes'].append(dict(horse_id=f'{key}-horse-{no:02d}', win=value))
            elif mode == 2:
                unavailable = rng.sample(all_nos, 1)
                refid = f'{key}-horse-{unavailable[0]:02d}'
                race['batches']['21']['quotes'] = [q for q in race['batches']['21']['quotes'] if q['horse_id'] != refid]
            elif mode == 3:
                unavailable, issue = all_nos, 'UNKNOWN_QUOTE_OWNER'
                race['batches']['21']['quotes'].append(dict(horse_id='outside-official-roster', win='3.3'))
            elif mode == 4:
                rev = deepcopy(race); rev['result_available_at'] = before
                rev[rng.choice(['post_at','jurisdiction','results','N'])] = None
                payload.append(rev); continue
            elif mode == 5:
                rev = deepcopy(race); rev['post_at'] = None
                rev['result_available_at'] = cutoff.isoformat() if case % 2 else None
                payload.append(rev)
            elif mode == 6:
                race['batches']['6']['quotes'].append(dict(race['batches']['6']['quotes'][0])); continue
            elif mode == 7:
                del race['batches']['21']
                unavailable, issue = all_nos, 'MISSING_BATCH'
            elif mode == 8:
                race['post_freeze_scratch'] = True; continue
            for no in unavailable:
                del ref['early'][no]
            if unavailable:
                diagnostics[key] = dict(race_id=key, unavailable_odds21_horse_nos=sorted(unavailable),
                                        ambiguous_odds21_horse_nos=ambiguous, odds21_batch_issue=issue)
            admitted.append(ref)
        rng.shuffle(payload)
        got = read(current, payload)
        for race in payload:
            for batch in race['batches'].values():
                batch['quotes'].reverse()
            race['roster'].reverse()
            if isinstance(race.get('results'), list):
                race['results'].reverse()
        if got != read(current, list(reversed(payload))):
            raise AssertionError(('history order changed result or audit', case))
        ids = sorted(r['id'] for r in admitted)
        if (not got['proven'] or got['ids'] != ids or [diagnostics[k] for k in sorted(diagnostics)] != got['partial']
                or len(got['rows']) != n or {r['horse_no'] for r in got['rows']} != set(target['order'])):
            raise AssertionError(('history or audit membership', case))
        for item in got['rows']:
            no = item['horse_no']; rank = target['order'].index(no)
            status, basis, near, expected = calculate(target, admitted, rank, None)
            if (item['formula_rank'] != rank+1 or item['status'] != status or item['basis'] != basis
                    or got['near'][str(no)] != near):
                raise AssertionError(('horse status or neighbours', case, no))
            for k, value in expected.items():
                if not math.isclose(value, item[k], abs_tol=3e-12, rel_tol=3e-13):
                    raise AssertionError(('history value', case, no, k))
            checked += 1
    print(f'PASS R7 independent historical corrections: 36 timelines / {checked} horses, including reversed rows, diagnostics, exact selected neighbours, M/K and E3/E5 (seed {seed}).')
