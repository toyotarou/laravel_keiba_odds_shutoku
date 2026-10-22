#!/bin/bash
# @ANCHOR-20261020-R122-NULLQUOTA  選出停止（UNRESOLVED）の正しい応答を弾いていた不具合
#   ＋ 正常出力は役（1st/2nd）を固定入力に合わせて照合する
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
echo "  期待 AiController ca45108dadc3c6b443df4ed664699d0d"
echo "  期待 Command      b46f980478fc6a073ec8a8286f1024b1"
ssh "$SRV" 'mkdir -p ~/r122_v33/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v33/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v33/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v33_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v33_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v33_$STAMP/"
cp ~/r122_v33/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v33/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
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
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -16
php artisan keiba:ai-analysis-compensate --v04-test-detail 2>&1 | grep -E "選出停止|停止なのに|枠があるのに"
echo ""; echo "══ ② 異常例＋本番の応答処理 ══"
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/02_異常例.txt" | sed -n '/── 本番の応答処理へ通す/,$p'
echo ""; echo "══ ③ そのほか ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 | tail -4
echo ""; echo "結果: $OUT"
REMOTE
