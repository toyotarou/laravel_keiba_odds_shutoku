#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
#  @ANCHOR-20261020-R122-REPAIR  欠落馬救済・repair_scope・row_valid（ご指摘5-1）
#  @ANCHOR-20261020-R122-SEP     REPLAY と PRODUCTION の分離（ご指摘9）
# AIへは送りません。DBへ残る書き込みは0件です。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php" "$MACAPP/app/Http/Controllers/Api/AiController.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php"
echo "  期待 bfb6b6eca95c6905d4778d4daca52f8a"
ssh "$SRV" 'mkdir -p ~/r122_v29/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v29/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v29/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v29_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v29_$STAMP/"
cp ~/r122_v29/code/AiController.php "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php; md5sum app/Http/Controllers/Api/AiController.php
echo "期待 22170行 / bfb6b6eca95c6905d4778d4daca52f8a"
php -l app/Http/Controllers/Api/AiController.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ ① 受入試験（訂正まわりを増やした）══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/01_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/01_受入試験.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -20
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/02_全項目.txt" 2>&1
echo "--- R122-V03（訂正の採否）---"
grep "R122-V03" "$OUT/02_全項目.txt"

echo ""; echo "══ ② 仮置きの入力は PRODUCTION として保存しない ══"
php artisan keiba:ai-analysis-compensate --r122-freeze-dry \
  --r122-prompt-assume --r122-prompt-sales-offset=60 --date=2026-10-18 2>&1 \
  | grep -E "REPLAY_ONLY|OK |ACQUISITION|対象|SKIP" | head -8 | tee "$OUT/03_分離.txt"
echo "（--r122-freeze-dry は書かないので REPLAY 扱いになりません。"
echo "  PRODUCTION で仮置きを保存しようとすると REPLAY_ONLY で止まります）"

echo ""; echo "══ ③ 監査行からの通し試験（ロールバック）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 | tail -22 | tee "$OUT/04_通し試験.txt"

echo ""; echo "══ ④ 送信文・異常例・合成試験・72レース ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | grep -E "Schemaの判定|意味検証の判定|すべて先方"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check --date=2026-10-17,2026-10-18 2>&1 | grep -A3 "7-3\."

echo ""; echo "══ ⑤ 件数 ══"
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" -N -e "
SELECT 'odds',COUNT(*) FROM t_horse_odds_finder_odds
UNION ALL SELECT 'snapshots',COUNT(*) FROM baganriki_snapshots
UNION ALL SELECT 'race_runs',COUNT(*) FROM baganriki_race_runs
UNION ALL SELECT 'acq',COUNT(*) FROM baganriki_acquisition_batches;" 2>&1 | grep -v '^mysql:'
echo "期待 8283 / 0 / 0 / 0"
echo ""; echo "結果: $OUT"
REMOTE
