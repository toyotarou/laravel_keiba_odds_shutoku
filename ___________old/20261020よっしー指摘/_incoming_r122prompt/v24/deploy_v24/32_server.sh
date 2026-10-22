#!/bin/bash
# @ANCHOR-20261020-R122-COVERAGE  追加材料3区分の接続を確かめる
# AIへは送りません。DBへ残る書き込みも0件です。
set -u
APP=/var/www/horse_odds_finder
SRC=~/r122_v24
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$SRC/result_$STAMP"; mkdir -p "$OUT"
say(){ echo ""; echo "══ $* ══"; }

say "0. 控えと置き換え"
mkdir -p "$BK/before_v24_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v24_$STAMP/"
cp "$SRC/code/AiController.php" "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP" && wc -l app/Http/Controllers/Api/AiController.php && md5sum app/Http/Controllers/Api/AiController.php
echo "期待 21727行 / b3a1ffa5ca6a387a6f51c6ef7ac5f16c"
php -l "$APP/app/Http/Controllers/Api/AiController.php"
php artisan config:clear >/dev/null 2>&1 || true

say "1. 受入試験（壊れていないこと）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "判定件数|合格|不合格" | tee "$OUT/01_受入試験.txt"

say "2. 送信文の検査（参照入力14本が変わっていないこと）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -8 | tee "$OUT/02_送信文の検査.txt"

say "3. 異常例32本"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | tail -8 | tee "$OUT/03_異常例.txt"

say "4. 本番DBで追加材料が載るか（投票締切と監査を仮置き・送信はしません）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$HOME/r122_v23/mat" --r122-prompt-real \
  --r122-prompt-assume --r122-prompt-sales-offset=60 \
  --r122-prompt-dump="$OUT/送信文" --date=2026-10-18 2>&1 \
  | tail -22 | tee "$OUT/04_本番DB.txt"

say "5. 載った材料の内訳（1レース分を数える）"
F=$(ls "$OUT/送信文"/real_*_1st_input.json 2>/dev/null | head -1)
if [ -n "${F:-}" ]; then
  python3 - "$F" <<'PY' 2>&1 | tee "$OUT/05_材料の内訳.txt"
import json,sys,collections
d=json.load(open(sys.argv[1]))
print("レース:", d["race_id"], " N=",d["N"])
print("coverage:")
for k,v in sorted(d["coverage"].items()):
    if v["status"]=="INCLUDED":
        print("  %-22s INCLUDED  source_refs %d 件" % (k, len(v["source_refs"])))
    else:
        print("  %-22s UNAVAILABLE  %s" % (k, v["reason"]))
c=collections.Counter((e["field"],e["scope"],e["source_kind"]) for e in d["source_registry"].values())
print("\nsource_registry 合計 %d 件" % len(d["source_registry"]))
for (f,s,k),n in sorted(c.items()):
    print("  %-28s %-7s %-9s %4d 件" % (f,s,k,n))
print("\nfield_catalog:", ", ".join(sorted(d["field_catalog"])))
print("入れ子の深さ・キー数:", len(d))
PY
else
  echo "送信文が書き出されていません"
fi

say "6. スキーマ適合（本番DBで作った入力）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | grep -A4 "§4" | tee "$OUT/06_スキーマ.txt"

say "おわり"
echo "結果: $OUT"
ls -l "$OUT"
