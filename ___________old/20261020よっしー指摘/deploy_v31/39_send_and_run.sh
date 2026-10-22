#!/bin/bash
# r122NumValue の往復判定を直す（2.1 が数値にならなかった件）
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
echo "  期待 4b7b40c6ef628d7cb96536d6c7ea74e2"
ssh "$SRV" 'mkdir -p ~/r122_v31/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v31/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v31/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v31_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v31_$STAMP/"
cp ~/r122_v31/code/AiController.php "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php; md5sum app/Http/Controllers/Api/AiController.php
php -l app/Http/Controllers/Api/AiController.php
php artisan config:clear >/dev/null 2>&1 || true
echo ""; echo "══ ① 受入試験 ══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/01_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/01_受入試験.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -16
php artisan keiba:ai-analysis-compensate --v04-test-detail 2>&1 | grep "R122-E07"
echo ""; echo "══ ② 監査行からの通し試験（ロールバック）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 | tail -26 | tee "$OUT/02_通し試験.txt"
echo ""; echo "══ ③ 送信文・異常例・合成試験・72レース ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | grep -E "Schemaの判定|意味検証の判定|すべて先方"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check --date=2026-10-17,2026-10-18 2>&1 | grep -A3 "7-3\."
echo ""; echo "══ ④ 元値の指紋（前回と突き合わせ）══"
PREV=$(ls -t "$APP"/storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)
php artisan keiba:ai-analysis-compensate --r122-fingerprint="$PREV" 2>&1 | tail -18 | tee "$OUT/03_指紋.txt"
echo ""; echo "結果: $OUT"
REMOTE
