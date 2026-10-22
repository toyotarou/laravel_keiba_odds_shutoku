#!/bin/bash
# @ANCHOR-20261020-R122-COVERAGE  載せなかった理由を「集計が無い」と「情報上限より後」で分ける
# Mac配置 → サーバ反映 → 確認まで1本で終わります。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php" "$MACAPP/app/Http/Controllers/Api/AiController.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php"
echo "  期待 49328f82e6d689b31dd8463737d04879"
ssh "$SRV" 'mkdir -p ~/r122_v25/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v25/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v25/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v25_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v25_$STAMP/"
cp ~/r122_v25/code/AiController.php "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php; md5sum app/Http/Controllers/Api/AiController.php
echo "期待 21755行 / 49328f82e6d689b31dd8463737d04879"
php -l app/Http/Controllers/Api/AiController.php
php artisan config:clear >/dev/null 2>&1 || true
echo ""; echo "══ 受入試験 ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格|不合格" | tee "$OUT/01_受入試験.txt"
echo ""; echo "══ 送信文の検査 ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -4 | tee "$OUT/02_送信文.txt"
echo ""; echo "══ 本番DBでの組み立てと材料の内訳 ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" \
  --r122-prompt-real --r122-prompt-assume --r122-prompt-sales-offset=60 \
  --r122-prompt-dump="$OUT/送信文" --date=2026-10-18 > "$OUT/03_本番DB.txt" 2>&1
grep -E "対象 |OK  *[0-9]+ レース" "$OUT/03_本番DB.txt" | head -3
F=$(ls "$OUT/送信文"/real_*_1st_input.json 2>/dev/null | head -1)
python3 - "$F" <<'PY' 2>&1 | tee "$OUT/04_材料の内訳.txt"
import json,sys,collections
d=json.load(open(sys.argv[1]))
print("レース:", d["race_id"], "N=", d["N"], " トップレベルのキー数:", len(d))
print("input_freeze_at:", d["input_freeze_at"])
print("\ncoverage:")
for k,v in sorted(d["coverage"].items()):
    if v["status"]=="INCLUDED": print("  %-22s INCLUDED   %d 件" % (k, len(v["source_refs"])))
    else: print("  %-22s UNAVAILABLE %s" % (k, v["reason"]))
c=collections.Counter((e["field"],e["scope"],e["source_kind"]) for e in d["source_registry"].values())
print("\nsource_registry 合計 %d 件" % len(d["source_registry"]))
for (f,s,k),n in sorted(c.items()): print("  %-28s %-7s %-9s %4d 件" % (f,s,k,n))
g=[e for e in d["source_registry"].values() if e["field"]=="odds_gap_pct_21_6"][:2]
print("\n断層の登録の例（由来が1件ずつ辿れること）:")
for e in g: print("  ", e["source_ref"], "値", e["value"], "parents", e["parents"], e["formula_id"])
PY
echo ""; echo "結果: $OUT"
REMOTE
