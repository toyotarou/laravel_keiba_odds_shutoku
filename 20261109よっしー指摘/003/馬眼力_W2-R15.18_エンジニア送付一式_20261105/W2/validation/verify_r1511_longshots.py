"""Trace independently supported outsiders from signed role responses to public candidates."""
import argparse
from copy import deepcopy
from datetime import timedelta
from hashlib import sha256
import json
from pathlib import Path
from tempfile import TemporaryDirectory
from zipfile import ZipFile

from verify_r1510_read import ReadTrial
from verify_r157_receive import dump, require

HERE = Path(__file__).resolve().parent
ROLES = ('1st', '2nd')


def audit_old_method():
    # Results are joined here for retrospective counting only. No selection test reads these results.
    with ZipFile(HERE/'previous_W1.zip') as archive:
        races = json.loads(archive.read('validation/market_inputs.json'))['races']
        outcomes = json.loads(archive.read('validation/outcomes_for_audit_only.json'))
    total = outsiders = disqualified = placed = 0
    for race in races:
        horses = race['runners']
        total += len(horses)
        for horse in horses:
            if horse['rank'] >= 7:
                outsiders += 1
                if horse['qualification'] == 0:
                    disqualified += 1
                    placed += horse['horse'] in outcomes[race['id']]['official_top3']
    require((len(races), total, outsiders, disqualified, placed) == (8, 123, 75, 75, 11),
            (len(races), total, outsiders, disqualified, placed))
    print('OLD 8 selected races, 123 starters: 75 ranked 7th or lower at T-6; 75 old qualification=0, including 11 official top-three finishers.')

    # Join the frozen mode and basic quota only after selection inputs have been kept separate.
    modes = json.loads((HERE/'週末8レース_R3市場判定.json').read_bytes())
    quota = json.loads((HERE.parent/'config/w2_contract.json').read_bytes())['base_quota']
    firm_example = '2026-10-31_京都1R'
    market = next(r for r in modes if r['id'] == firm_example)
    race = next(r for r in races if r['id'] == firm_example)
    third = outcomes[firm_example]['official_top3'][2]
    rank = next(h['rank'] for h in race['runners'] if h['horse'] == third)
    require(market['N']==16 and market['allocation_mode']=='FIRM' and quota['7']['FIRM']==[5,2,0]
            and (third,rank)==(4,12), (market,third,rank))
    print('ARCHIVE Kyoto 1R 2026-10-31: frozen FIRM, base 5/2/0, #4 ranked 12th finished 3rd; D has no initial slot. This is a quota observation, not an actual W2 role response.')


def change_role(row, target):
    begin, body = row['original'].split('\n', 1)
    original, end = body.rsplit('\n', 1)
    reply = json.loads(original)
    for horse in reply['horses']:
        if horse['band'] == 'A':
            continue
        if horse['horse_no'] == target:
            supported = [f for f, v in horse['family_review'].items() if v['status'] == 'SUPPORTED']
            require(supported == ['ability'] and horse['tier'] == 'B' and horse['total_score'] is None and
                    horse['eligibility'] == 'ELIGIBLE' and len(horse['evidence']) == 1, horse)
            horse.update(public_candidate=True, nonselect_reason=None)
            continue
        horse.update(eligibility='UNVERIFIED', tier='UNVERIFIED', rank_basis='NOT_RANKED', evidence=[],
                     evidence_rank_evidence_ids=[], public_candidate=False,
                     nonselect_reason={'code':'UNVERIFIED','reason':'合成試験では好走根拠の確認に必要な資料が不足'})
        for family in horse['family_review']:
            horse['family_review'][family] = {'status':'DATA_MISSING','reason':'合成試験では資料不足','source_refs':[],'evidence_ids':[]}
    for band in ('B', 'D'):
        ids = [h['horse_no'] for h in reply['horses'] if h['band'] == band and h['horse_no'] == target]
        for name in ('band_ranks', 'evidence_band_ranks', 'selection_ranks'):
            reply[name][band] = ids
        reply['expected_reviews'][band] = {'incumbent':None,'challenger':None,'decision':'NO_BOUNDARY'}
    quota = reply['role_quota']
    reply['public_candidates'] = [n for b in 'ABD' for n in reply['selection_ranks'][b][:quota[b]]]
    reply['shortfalls'] = []
    for band, requested in quota.items():
        available = len(reply['band_ranks'][band])
        if available < requested:
            reply['shortfalls'].append({'band':band,'requested':requested,'eligible':available,'missing':requested-available,
                                       'reason':'合成試験では他の馬に確認済み好走根拠がない'})
    raw = begin + '\n' + dump(reply) + '\n' + end
    row.update(original=raw, proof_horses=[n for b in 'ABD' for n in reply['band_ranks'][b][:quota[b]]])
    row['receipt'].update(response_hash=sha256(raw.encode()).hexdigest(), original_approved=True)
    return reply


def exercise(php, controller, directory):
    trial = ReadTrial(php, controller, directory)
    try:
        trial.setup()
        original, packet = trial.packet(trial.start + timedelta(seconds=6))
        require(original['ok'], original)
        frozen = json.loads(packet['roles'][0]['input'])
        with ZipFile(HERE/'synthetic_W2_examples.zip') as archive:
            fixture = json.loads(archive.read('merge.json'))
        require(fixture['public_candidates'] == original['candidates'] == [1,2,3,7,8,10,12], original['candidates'])
        print('PASS 16-horse mixed fixture, both roles: public 1/2/3/7/8/10/12; B=3 plus D=1.')
        for num in range(7,17):
            h = next(h for h in frozen['runners'] if h['horse_no']==num)
            require(h['win_rank']==num, (num,list(h)))
        cases = [(r, n) for r in ROLES for n in range(7, 17)] + [(None, None)]
        for role, target in cases:
            trial.setup()
            p = deepcopy(packet)
            for row in p['roles']:
                reply = change_role(row, target if row['role'] == role else None)
                (directory/(row['role']+'_output.json')).write_text(dump(reply))
            adopted = trial.replay('adopt', p)
            require(adopted['ok'], (role,target,'adopt',adopted))
            p['live']['role_journal'] = adopted['data']['role_journal']
            opened = trial.replay('open', p)
            require(opened['ok'], (role,target,'open',opened))
            selected = opened['data']['result']
            committed = trial.replay('commit', dict(p, selection=selected, finished_at=p['now']))
            require(committed['ok'], (role,target,'commit',committed))
            want = [] if target is None else [target]
            def check(ids):
                require([n for n in ids if n >= 7] == want and len(ids)==3+len(want), (role,target,ids))
            check(selected['public_candidates'])
            require(selected['agreement_bonus'].get(str(target),0) == 0, (role,target,selected))
            written = trial.call('publish',clock=trial.clock)
            require(written['code']=='PUBLISHED', (role,target,written))
            check([h['horse_no'] for h in written['candidates']])
            display = trial.get()['body']
            require(display['state']=='PUBLISHED' and not display['buy_recommended'], (role,target,display))
            check(display['live_state']['available_candidate_nos'])
        print('PASS 20 single-role outsider scenarios: ranks 7 through 16, 1st-only and 2nd-only, one supported family, total_score null, no agreement bonus. W2 commit, submitted publication and readback all retain the horse.')
        print('PASS no supported B/D candidate: all three routes retain only 3 A candidates in a 7-slot race; no favourite fills the 4 vacancies.')
    finally:
        trial.db.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php')
    parser.add_argument('controller',type=Path)
    args = parser.parse_args()
    audit_old_method()
    with TemporaryDirectory() as temp:
        exercise(args.php,args.controller.resolve(),Path(temp))
