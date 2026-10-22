# -*- coding: utf-8 -*-
"""
馬眼力 v0.4.1-r12.1  「6分前単勝の欠損 ＋ 3分前の取消」修正前後の比較
──────────────────────────────────────────────────────────────────────
2026-10-19 006のご指摘2への回答です。

指摘：
  取消の分岐を「6分前の数値オッズがあるか」で行っていたため、
  6分前単勝が欠損していて取消の根拠が3分前にしかない馬まで
  「凍結前の取消」として出走集合から外し、頭数が縮んでいた。

使い方:
    python3 -I verify_before_after.py <reference.pyのあるディレクトリ> \
        verify_harness_before.php verify_harness.php

verify_harness_before.php は修正前の分岐だけを戻したものです
（ほかは修正後と同じコードです）。
"""
import sys, json, subprocess, importlib.util, os

REF_DIR  = sys.argv[1] if len(sys.argv) > 1 else '.'
BEFORE   = sys.argv[2] if len(sys.argv) > 2 else 'verify_harness_before.php'
AFTER    = sys.argv[3] if len(sys.argv) > 3 else 'verify_harness.php'

here = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('v', os.path.join(here, 'verify_vs_reference.py'))
sys.argv = ['x', REF_DIR, AFTER]
m = importlib.util.module_from_spec(spec)
m.__name__ = 'v'
spec.loader.exec_module(m)

base = m.mk_random(7)
entry = sorted(int(k) for k in base.keys())
victim = max(entry)

only6 = json.loads(json.dumps(base))
del only6[str(victim)]['t']['6']                 # 6分前の単勝だけ欠損
mtc = json.loads(json.dumps(only6))
mtc[str(victim)]['t']['3'] = '取消'               # 取消の根拠は3分前だけ

path = '/tmp/verify_case_miss_then_cancel.json'
with open(path, 'w') as fp:
    json.dump([{'odds': mtc, 'entry': entry, 'declared': len(entry)}], fp)

print('入力：出馬表%d頭。馬番%dの6分前単勝が欠損し、3分前に初めて「取消」。' % (len(entry), victim))
print('期待：凍結前の取消として外さない。%d頭を保ったまま計算不能。' % len(entry))
print('')
ng = 0
for name, h in (('修正前', BEFORE), ('修正後', AFTER)):
    out = json.loads(subprocess.run(['php', h, path],
                                    capture_output=True, text=True, check=True).stdout)[0]
    print('%s: 状態=%s  N=%s  mode=%s  取消(凍結前)=%s  取消(未確定)=%s'
          % (name, out['state'], out['N'], out['mode'],
             out['cancelled'] or '[]', out.get('cancelled_late') or '[]'))
    print('        理由=%s' % (out['reason'] or '（なし）'))
    if name == '修正後':
        ok = (out['state'] == '計算不能'
              and int(out['N']) == len(entry)
              and not out['cancelled']
              and [victim] == [int(x) for x in out.get('cancelled_late', [])])
        print('')
        print('判定: %s' % ('期待どおり' if ok else '不具合'))
        if not ok:
            ng = 1
sys.exit(ng)
