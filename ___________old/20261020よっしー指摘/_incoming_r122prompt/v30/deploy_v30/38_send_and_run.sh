#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
#  @ANCHOR-20261020-R122-DIGITS  小数4桁制限の撤廃・float代替経路の分離（ご指摘7）
#  @ANCHOR-20261020-R122-FP      元値の指紋の作り直し（ご指摘8）
#  ＋ v29 の2件の直し（受入試験の期待値・通し試験の読戻し世代）
# AIへは送りません。DBへ残る書き込みは0件です。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"            "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 AiController 82d169e844de43f108003baa0c969a08"
echo "  期待 Command      cf9b5bf0a62210dd45741554a69022f8"
ssh "$SRV" 'mkdir -p ~/r122_v30/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v30/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v30/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v30_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v30_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v30_$STAMP/"
cp ~/r122_v30/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v30/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
md5sum app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ ① 受入試験 ══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/01_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/01_受入試験.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -20
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/02_全項目.txt" 2>&1
echo "--- R122-E07（桁数と代替経路）---"
grep "R122-E07" "$OUT/02_全項目.txt"

echo ""; echo "══ ② 監査行からの通し試験（ロールバック）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 | tail -26 | tee "$OUT/03_通し試験.txt"

echo ""; echo "══ ③ 元値の指紋（新方式）══"
php artisan keiba:ai-analysis-compensate --r122-fingerprint= 2>&1 | tee "$OUT/04_指紋.txt"

echo ""; echo "══ ④ 送信文・異常例・合成試験・72レース ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | grep -E "Schemaの判定|意味検証の判定|すべて先方"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check --date=2026-10-17,2026-10-18 2>&1 | grep -A3 "7-3\."
echo ""; echo "結果: $OUT"
REMOTE
