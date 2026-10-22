#!/bin/bash
# @ANCHOR-20261020-R122-CHAIN  異常出力と正常出力を本番の応答処理へ通す（ご指摘6-2・6-3）
#   extract → 封筒照合（予約済みroleを正とする）→ 馬ごとの隔離
# AIへは送りません。DBへ残る書き込みは0件です。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 bcf423c58f8895b6d59e87ff9f7a1358"
ssh "$SRV" 'mkdir -p ~/r122_v32/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v32/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v32/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v32_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v32_$STAMP/"
cp ~/r122_v32/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true
echo ""; echo "══ 異常例32本 ＋ 本番の応答処理への通し ══"
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | tee "$OUT/01_異常例と応答処理.txt" | sed -n '/── 本番の応答処理へ通す/,$p'
echo ""; echo "══ 受入試験（壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"
echo ""; echo "結果: $OUT"
REMOTE
