#!/bin/bash
# @ANCHOR-20261020-R122-33R  33レースの候補比較（機械比較・再評価なし）
# 読み取りだけ。AI呼び出し0回、DBへの書き込み0件。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 c3f4b867cf8daeb78dcaa80e824de531"
ssh "$SRV" 'mkdir -p ~/r122_v35/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v35/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v35/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v35_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v35_$STAMP/"
cp ~/r122_v35/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true
echo ""; echo "══ 33レースの候補比較 ══"
php artisan keiba:ai-analysis-compensate --r122-33races --date=2026-10-17,2026-10-18 2>&1 \
  | tee "$OUT/01_33レース.txt"
CSV=$(ls -t "$APP"/storage/logs/r122_33races_*.csv 2>/dev/null | head -1)
cp -p "$CSV" "$OUT/02_33レース.csv" 2>/dev/null || true
echo ""; echo "--- CSVの先頭5行 ---"
head -8 "$CSV" 2>/dev/null
echo ""; echo "══ 受入試験（壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"
echo ""; echo "結果: $OUT"
REMOTE
