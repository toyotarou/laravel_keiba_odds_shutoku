# -*- coding: utf-8 -*-
"""
馬眼力 v0.4.1-r12.1  X1〜X5・Z の突き合わせ試験
──────────────────────────────────────────────────────────────────────
実装一式の reference.py（正本・Fraction による有理数比較）と、
Laravel側の実装（SummaryAiAnalysisCompensate.php の modeCheckOne・
十進数文字列を1万倍した整数による比較）が、同じ入力に対して
同じ X1〜X5・Z を返すことを確かめます。

使い方:
    python3 -I verify_vs_reference.py <reference.pyのあるディレクトリ> <verify_harness.php>

合否の決め方（2026-10-19 003のご指摘3で全面的に直しました）:
    期待件数・実比較件数・読み飛ばし件数・不一致件数を別々に数えます。
    読み飛ばしが1件でもあれば失敗。
    実比較件数が期待件数に満たなければ失敗。
    PHP側の戻り配列の長さが入力件数と違えば失敗。
    例外の理由も全部出します。
    （直す前は「例外→読み飛ばして続行」「不一致0件なら成功」だったので、
      全件読み飛ばされても成功と出てしまいました。）

比較しているのは N と X1〜X5・Z だけです。
配分・候補・期限・取消処理の一致は、これでは確かめられません。
"""
import sys, json, random, subprocess, os
from fractions import Fraction
from decimal import Decimal

REF_DIR = sys.argv[1] if len(sys.argv) > 1 else '.'
HARNESS = sys.argv[2] if len(sys.argv) > 2 else 'verify_harness.php'
sys.path.insert(0, REF_DIR)
import reference as R

TIMINGS_T = [999, 21, 18, 15, 12, 9, 6]


def build(case):
    """case: {馬番: {'t': {時点: 文字列}, 'f': {時点: 文字列}}} → (n, pairs, horses)

    engine.py と同じ組み方です。
      ・凍結順位 = 6分前の単勝 昇順（同値は馬番昇順）
      ・pairs[k] = {W21, W6, P6}（下÷上。どちらか欠ければ None）
      ・horses[i] = {win_rank, P21, P9, P6}
    正本の finite_number() は str を数値として受けないので Decimal で渡します。
    """
    t6 = {int(k): v['t']['6'] for k, v in case.items() if '6' in v.get('t', {})}
    order = sorted(t6.keys(), key=lambda n: (Fraction(t6[n]), n))
    n = len(order)

    def o(no, kind, tm):
        return case[str(no)].get(kind, {}).get(str(tm))

    def ratio(a, b):
        if a is None or b is None:
            return None
        return Fraction(str(b)) / Fraction(str(a))

    pairs = {}
    for i in range(n - 1):
        a, b = order[i], order[i + 1]
        pairs[i + 1] = {
            'W21': ratio(o(a, 't', 21), o(b, 't', 21)),
            'W6':  ratio(o(a, 't', 6),  o(b, 't', 6)),
            'P6':  ratio(o(a, 'f', 6),  o(b, 'f', 6)),
        }

    horses = []
    for i, no in enumerate(order):
        h = {'horse_no': no, 'win_rank': i + 1}
        for key, tm in (('P21', 21), ('P9', 9), ('P6', 6)):
            v = o(no, 'f', tm)
            if v is not None:
                h[key] = Decimal(str(v))
        horses.append(h)
    return n, pairs, horses


def tag(v):
    return 'T' if v is True else 'F' if v is False else 'U'


def php_run(cases, name):
    path = os.path.join('/tmp', 'verify_cases_%s.json' % name)
    with open(path, 'w') as fp:
        json.dump(cases, fp)
    out = subprocess.run(['php', HARNESS, path], capture_output=True, text=True)
    if out.returncode != 0:
        print('  PHPが異常終了しました: %s' % out.stderr.strip()[:400])
        return None
    return json.loads(out.stdout)


def mk_random(seed):
    """無作為な入力。欠損を6〜10%混ぜます。"""
    rnd = random.Random(seed)
    n = rnd.choice([4, 5, 6, 7, 8, 10, 12, 14, 16, 18])
    case = {}
    base = sorted(round(rnd.uniform(1.1, 180.0), 1) for _ in range(n))
    for i in range(n):
        w6 = base[i]
        t, f = {}, {}
        for tm in TIMINGS_T:
            if rnd.random() < 0.06:
                continue
            v = w6 if tm == 6 else round(max(1.1, w6 * rnd.uniform(0.7, 1.4)), 1)
            t[str(tm)] = '%.1f' % v
        t['6'] = '%.1f' % w6                      # 6分前の単勝は凍結順位の土台なので必須
        for tm in TIMINGS_T:
            if rnd.random() < 0.10:
                continue
            f[str(tm)] = '%.1f' % round(max(1.0, w6 * rnd.uniform(0.15, 0.45)), 1)
        case[str(i + 1)] = {'t': t, 'f': f}
    return case


def mk_boundary(seed):
    """境界ちょうどを狙った入力。
    2.0倍・1.5倍ちょうど、単勝の21→6短縮率0.20ちょうど、
    複勝の短縮率0.05・0.03ちょうどを多めに混ぜます。
    float で比較していると、ここで判定がずれ得ます。
    """
    rnd = random.Random(10000 + seed)
    n = rnd.choice([7, 8, 10, 12, 14, 16, 18])
    case = {}
    w = round(rnd.uniform(1.1, 9.9), 1)
    base = []
    for _ in range(n):
        base.append(w)
        w = round(w * rnd.choice([2.0, 1.5, 1.0, 2.0, 1.5]), 1)
    for i in range(n):
        w6 = base[i]
        t = {'6': '%.1f' % w6}
        t['21'] = '%.1f' % round(w6 * rnd.choice([1.25, 1.0, 1.25, 0.8]), 1)
        for tm in (999, 18, 15, 12, 9):
            if rnd.random() < 0.08:
                continue
            t[str(tm)] = '%.1f' % round(max(1.1, w6 * rnd.uniform(0.8, 1.3)), 1)
        p6 = round(max(1.0, w6 * 0.3), 1)
        f = {'6': '%.1f' % p6}
        f['21'] = '%.1f' % round(p6 / 0.95, 1)
        f['9']  = '%.1f' % round(p6 / 0.97, 1)
        if rnd.random() < 0.10:
            del f['21']
        if rnd.random() < 0.10:
            del f['9']
        case[str(i + 1)] = {'t': t, 'f': f}
    return case


def compare(name, cases):
    """正本と突き合わせます。期待・実比較・読み飛ばし・不一致を別々に数えます。"""
    expected = len(cases)
    php = php_run(cases, name)
    if php is None:
        print('%s: PHPを実行できませんでした → 失敗' % name)
        return False
    if len(php) != expected:
        print('%s: PHPの戻りが %d件、入力は %d件 → 失敗'
              % (name, len(php), expected))
        return False

    compared = 0
    skipped = []
    mismatched = []
    unknown_cases = 0
    for idx, case in enumerate(cases):
        try:
            n, pairs, horses = build(case)
            x1, x2, x3, x4, x5, x6, z = R.extract_signals(n, pairs, horses, None)
        except Exception as e:
            skipped.append((idx, repr(e)))
            continue
        compared += 1
        if any(v is None for v in (x1, x2, x3, x4, x5, z)):
            unknown_cases += 1
        exp = {'N': n, 'X1': tag(x1), 'X2': tag(x2), 'X3': tag(x3),
               'X4': tag(x4), 'X5': tag(x5), 'Z': tag(z)}
        got = php[idx]
        diff = [k for k, v in exp.items() if got.get(k) != v]
        if diff:
            mismatched.append((idx, diff, exp, got))

    for idx, why in skipped[:10]:
        print('  読み飛ばし case=%d  %s' % (idx, why))
    for idx, diff, exp, got in mismatched[:10]:
        for k in diff:
            print('  不一致 case=%d %s  正本=%s Laravel=%s' % (idx, k, exp[k], got.get(k)))

    ok = (compared == expected and not skipped and not mismatched)
    print('%s: 期待 %d件 / 実比較 %d件 / 読み飛ばし %d件 / 不一致 %d件 '
          '（UNKNOWNを含む %d件） → %s'
          % (name, expected, compared, len(skipped), len(mismatched),
             unknown_cases, '合格' if ok else '失敗'))
    return ok


def check_entry_set():
    """出走馬集合の照合が効いているか。

    2026-10-19 004のご指摘3を受けて、出馬表（entry）・宣言頭数（declared）・
    オッズ（odds）を独立した入力にして試します。

    ①欠損なし                        → 計算できる
    ②6分前の単勝だけ欠ける            → 計算不能・頭数は縮まない・理由に馬番
    ③オッズ側から馬キーごと抜ける      → 同上
    ④出馬表に無い馬のオッズが混ざる    → 計算不能
    ⑤宣言頭数と出馬表の件数が合わない  → 計算不能
    ⑥出馬表はあるがオッズが全件ない    → 計算不能
    ⑦凍結前の取消（全時点が「取消」）→ 取消馬を外した頭数で計算できる
    ⑧凍結後の取消（6分前は実オッズで、3分前から「取消」）
      → 凍結順位・頭数・信号・modeを作り直さない。取消は別に記録するだけ。

    ⑨6分前単勝が欠損で、取消の根拠が3分前にしかない
      → 取消へ振り替えず、頭数を保ったまま計算不能
    ⑩取消の時点より後に実オッズがある → 入力不整合として計算不能
    ⑪馬キーの欠落と取消矛盾が重なる → 早く止めるときも頭数は出馬表で返る

    ⑧の根拠（2026-10-19 005のご指摘3）:
      config.json  after_cutoff_band_policy = 'frozen_win_rank'
      server_contract.txt 75行「取消で人気を詰め直さない」
      同117行「取消だけで正式予想を再選出しない」
    ⑨⑩の根拠（2026-10-19 006のご指摘2）:
      「6分前の数値が無い」を取消時点の代用にしない。
      凍結前の取消として外すには、凍結前または凍結時点の取消記録が要る。
    """
    base_odds = mk_random(7)
    entry = sorted(int(k) for k in base_odds.keys())
    victim = max(entry)                                 # いちばん人気薄の馬
    n_all = len(entry)

    def case(odds, ent=None, dec=None):
        return {'odds': odds,
                'entry': entry if ent is None else ent,
                'declared': n_all if dec is None else dec}

    only6 = json.loads(json.dumps(base_odds))
    del only6[str(victim)]['t']['6']                    # 6分前の単勝だけ落とす

    gone = json.loads(json.dumps(base_odds))
    del gone[str(victim)]                               # 馬キーごと消す

    extra = json.loads(json.dumps(base_odds))
    extra['99'] = json.loads(json.dumps(base_odds[str(entry[0])]))   # 出馬表に無い馬番

    # 正式な取消（本番DBは全時点のオッズ列に「取消」と入れています）
    cancel_all = json.loads(json.dumps(base_odds))
    cancel_all[str(victim)] = {'t': {str(tm): '取消' for tm in TIMINGS_T}, 'f': {}}

    # 凍結後の取消（6分前までは実オッズがあり、3分前から「取消」になる）
    cancel_late = json.loads(json.dumps(base_odds))
    cancel_late[str(victim)]['t']['3'] = '取消'

    # 6分前単勝が欠損で、取消の根拠が3分前にしかない（ご指摘006-2の再現）
    miss_then_cancel = json.loads(json.dumps(only6))
    miss_then_cancel[str(victim)]['t']['3'] = '取消'

    # 取消の時点より後に実オッズがある（入力不整合）
    conflict = json.loads(json.dumps(base_odds))
    conflict[str(victim)]['t']['21'] = '取消'      # 21分前に取消なのに6分前に数値

    # 馬キーの欠落 ＋ 別の馬の取消矛盾（ご指摘007-3）
    #   出馬表は10頭のまま、別の馬のオッズキーを丸ごと落とす。
    #   早く止めるときも頭数は出馬表（10頭）で返ること。
    other = sorted(int(k) for k in base_odds.keys())[-2]
    conflict_gone = json.loads(json.dumps(conflict))
    del conflict_gone[str(other)]

    cases = [
        ('①欠損なし',                     case(base_odds),                None,              'calc'),
        ('②6分前単勝だけ欠ける',           case(only6),                    str(victim),       'stop'),
        ('③オッズ側から馬キーごと抜ける',   case(gone),                     str(victim),       'stop'),
        ('④出馬表に無い馬のオッズが混ざる', case(extra),                   '99',              'stop'),
        ('⑤宣言頭数が合わない',            case(base_odds, dec=n_all + 1), str(n_all + 1),    'stop'),
        ('⑥オッズが全件ない',              case({}),                       None,              'stop'),
        ('⑦凍結前の取消が1頭（全時点）',    case(cancel_all),               None,              'pre'),
        ('⑧凍結後の取消（3分前から）',      case(cancel_late),              None,              'post'),
        ('⑨6分前欠損＋3分前に取消',         case(miss_then_cancel),         str(victim),       'late'),
        ('⑩取消の後に実オッズ（不整合）',    case(conflict),                 str(victim),       'stop'),
        ('⑪馬キー欠落＋取消矛盾',           case(conflict_gone),            str(victim),       'stop'),
    ]
    php = php_run([c for _, c, _, _ in cases], 'entryset')
    if php is None:
        print('出走馬集合の照合: PHPを実行できませんでした → 失敗')
        return False
    # 返却件数が期待件数と一致すること（ご指摘006-3）
    if len(php) != len(cases):
        print('出走馬集合の照合: PHPの戻りが %d件、入力は %d件 → 失敗'
              % (len(php), len(cases)))
        return False

    ok = 0
    for i, (label, _, want_in_reason, kind) in enumerate(cases):
        r = php[i]
        if kind == 'calc':
            good = (r['state'] != '計算不能')
            base_state = (r['state'], r['N'], r['mode'],
                          r['S0min'], r['S0max'], r['Smin'], r['Smax'])
            base_sig = (r['X1'], r['X2'], r['X3'], r['X4'], r['X5'], r['Z'])
            base_frozen = r['frozen']
            base_bands = r['bands']
        elif kind == 'pre':
            # 凍結前の取消：外した頭数で計算でき、凍結前リストに入る
            good = (r['state'] != '計算不能'
                    and int(r['N']) == n_all - 1
                    and [victim] == [int(x) for x in r['cancelled']]
                    and not r['cancelled_post'])
        elif kind == 'post':
            # 凍結後の取消：頭数・mode・S だけでなく
            # 個別信号・馬番別の凍結順位・帯も取消前と一致すること（ご指摘006-3）
            good = ((r['state'], r['N'], r['mode'],
                     r['S0min'], r['S0max'], r['Smin'], r['Smax']) == base_state
                    and (r['X1'], r['X2'], r['X3'], r['X4'], r['X5'], r['Z']) == base_sig
                    and r['frozen'] == base_frozen
                    and r['bands'] == base_bands
                    and int(r['N']) == n_all
                    and [victim] == [int(x) for x in r['cancelled_post']]
                    and not r['cancelled'])
        elif kind == 'late':
            # 6分前欠損＋凍結後の取消：取消へ振り替えず、頭数を保って止まる
            good = (r['state'] == '計算不能'
                    and int(r['N']) == n_all
                    and not r['cancelled']
                    and [victim] == [int(x) for x in r['cancelled_late']]
                    and str(victim) in r['reason'])
        else:
            good = (r['state'] == '計算不能' and int(r['N']) == n_all)
            if good and want_in_reason is not None:
                good = want_in_reason in r['reason']
        print('  %s: 状態=%s N=%s mode=%s 取消(前)=%s 取消(後)=%s 取消(未確定)=%s 理由=%s → %s'
              % (label, r['state'], r['N'], r['mode'],
                 r['cancelled'] or '[]', r['cancelled_post'] or '[]',
                 r['cancelled_late'] or '[]',
                 r['reason'] or '（なし）', '期待どおり' if good else '不具合'))
        if good:
            ok += 1

    n = len(cases)
    print('出走馬集合の照合: %d/%d 件が期待どおり → %s'
          % (ok, n, '合格' if ok == n else '失敗'))
    return ok == n


def self_check():
    """合否判定そのものの試験（2026-10-19 003-3）。

    わざと全件で例外を起こし、「読み飛ばしがあるのに合格」と出ないことを見ます。
    """
    saved = R.extract_signals

    def boom(*a, **k):
        raise RuntimeError('意図的な例外（合否判定の試験）')

    R.extract_signals = boom
    try:
        result = compare('【自己試験】全件で例外', [mk_random(i) for i in range(3)])
    finally:
        R.extract_signals = saved
    good = (result is False)
    print('合否判定の自己試験: %s（読み飛ばしがあるのに合格と出ないこと）'
          % ('期待どおり' if good else '不具合'))
    return good


if __name__ == '__main__':
    results = []
    results.append(('無作為400件',      compare('無作為', [mk_random(i) for i in range(400)])))
    results.append(('境界ちょうど2000件', compare('境界ちょうど', [mk_boundary(i) for i in range(2000)])))
    results.append(('出走馬集合の照合11件', check_entry_set()))
    results.append(('合否判定の自己試験', self_check()))
    print('──────────────────────────────')
    for name, ok in results:
        print('  %-22s %s' % (name, '合格' if ok else '失敗'))
    bad = sum(1 for _, ok in results if not ok)
    print('失敗した試験: %d件' % bad)
    sys.exit(1 if bad else 0)
