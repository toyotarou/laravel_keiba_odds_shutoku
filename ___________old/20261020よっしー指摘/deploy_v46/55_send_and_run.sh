#!/bin/bash
# @ANCHOR-20261020-R122-MARKET  OPI と推定確定オッズを source_registry へ
#   当日を含む集計・情報上限より後の集計は載せません（理由つきUNAVAILABLE）
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
echo "  期待 AiController afab9ee3f282bf99105018e7924edc0d"
echo "  期待 Command      3f64961d9f94d2cc0bd6412560c961ae"
ssh "$SRV" 'mkdir -p ~/r122_v46/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v46/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v46/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v46_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v46_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v46_$STAMP/"
cp ~/r122_v46/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v46/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ キューと永続予約＋送信口＋旗の段（67件）══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"

echo ""; echo "══ 応答検証と局所回復（正常14本＋局所回復＋訂正）══"
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt"

echo ""; echo "══ 統合・公開・intent・revision ══"
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt"

echo ""; echo "══ 読取API ══"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt"

echo ""; echo "══ 本番の経路で作った入力（Schema＋coverage＋過去走）══"
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 \
  | tee "$OUT/07_本番入力.txt"

echo ""; echo "══ 集計の期間が終わっている日（10/25想定）で OPI が載るか ══"
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" \
  --date=2026-10-25 2>&1 | grep -E "──|N=|coverage|OPI|estimated_odds|レースがありません" | head -12

echo ""; echo "══ 投票締切の正本（発走時刻から出した期限の並び）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-CL0" \
  | tee "$OUT/06_締切.txt"

echo ""; echo "══ 凍結が壊れていないこと（監査行→凍結保存→読戻し）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | grep -E "○|×|ロールバック後|通りました|ありません"

echo ""; echo "══ 受入試験（壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"

echo ""; echo "══ いまの .env と、そこから決まる段 ══"
grep -E "^BAGANRIKI_" "$APP/.env" 2>/dev/null || echo "  （BAGANRIKI_ の行はまだありません）"
echo ""
php artisan tinker --execute='
  // @ANCHOR-20261020-R122-SHADOW  これらのクラスは AiController.php の中にあるので、
  //   先に親ファイルを読ませないと autoload が見つけられません。
  class_exists(\App\Http\Controllers\Api\AiController::class);
  $P = \App\Http\Controllers\Api\BaganrikiPrompt::mode();
  $Q = \App\Http\Controllers\Api\BaganrikiWorker::mode();
  $D = var_export(\App\Http\Controllers\Api\BaganrikiTime::salesCloseDerive(), true);
  $O = \App\Http\Controllers\Api\BaganrikiTime::salesCloseOffsetSec();
  $M = \App\Http\Controllers\Api\AiController::r122PastRunsMax();
  echo "  本文の段            $P" . PHP_EOL;
  echo "  キューの段          $Q" . PHP_EOL;
  echo "  締切を導出するか    $D" . PHP_EOL;
  echo "  締切の前倒し(秒)    $O" . PHP_EOL;
  echo "  過去走の上限(走)    $M" . PHP_EOL;
' 2>&1 | grep -vE "^Psy|^$"

echo ""; echo "結果: $OUT"
REMOTE
