#!/bin/bash
# @ANCHOR-20261020-R122-SCOREEV  採点と出典の結びつき（005の5章末・10-1）
#   採点の算数（満点・合計・observed_sum/cap・total_score）は前から見ていました。
#   見ていなかったのは「その点数が何を根拠に付いたか」です。
#   点が付いているのに出典が空／採点していないのに理由が空／出典が実在しない
#   ・この馬のものでない、を見ます。
# @ANCHOR-20261020-R122-REFCHECK  参照根拠の意味検証（005の5章末）
#   ※ v51 で R-34・R-35 が × だったのは配線漏れです。ref_stats を外へ
#     渡していませんでした（判定そのものは R-26〜R-33 で通っていました）。
#   これまで各馬の事実出典hashは「記録」するだけで、その参照IDが
#   固定入力に実在するかを見ていませんでした。AIが参照IDを作り話で
#   書いても気づきません。実在・所有馬・自分のSIGNALの3つを見ます。
#   ①②に汚れがあっても、自分のSIGNALが1系統あれば行は落としません。
# @ANCHOR-20261020-R122-STRICTJSON  応答JSONの厳密読込（005のご指摘7-3）
#   extract は通常の json_decode でした。重複キーは後勝ちで黙って片方が消え、
#   128桁超は float へ丸まり、1e400 は INF になります。
#   走査（BaganrikiJson::strict）と値の組み立て（json_decode）を分けました。
#   送る前の自分の本文も同じ検査を通します（selfCheck）。
# @ANCHOR-20261020-R122-NATIVE / ANCHORMATCH / ROLLOVER
#   005の指摘のうち4件が直っていなかったので直しました。
#     ・合成729件＋境界4件を本体へ直接通す（これまでCommand側の検証用関数でした）
#     ・取消で目標数Cが変わる例（16→15・14→13・9→8・全頭取消）の本体試験
#     ・日付・時刻の繰上がり検査（2026-02-30 が3月2日として通っていました）
#     ・申告された予定時刻・情報上限が正本の発走時刻と合うかの検査
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
echo "  期待 AiController df729f21bb3968fbb19ba34c03f289fe"
echo "  期待 Command      11fd4c2d785669b0ea9403bdbc50e33f"
ssh "$SRV" 'mkdir -p ~/r122_v52/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v52/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v52/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v52_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v52_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v52_$STAMP/"
cp ~/r122_v52/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v52/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
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

echo ""; echo "══ 合成729件＋境界4件を本体へ直接通す／取消でCが変わる4例／繰上がり／正本一致 ══"
php artisan keiba:ai-analysis-compensate --r122-native-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/09_本体直通.txt"

echo ""; echo "══ 検証用関数の側（これまでどおり。本体の試験とは分けて出します）══"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 \
  | grep -E "mode_cases|acquisition_boundary|合格|失敗" | head -6

echo ""; echo "══ 回収率の理由が「当日を含む集計」に変わったか ══"
grep -E "recovery_statistics|similar_statistics" "$OUT/07_本番入力.txt" | head -6

echo ""; echo "══ OPI と推定確定オッズの通し試験（載らない側と載る側の両方）══"
php artisan keiba:ai-analysis-compensate --r122-market-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/08_OPI.txt"

echo ""; echo "══ 投票締切の正本（発走時刻から出した期限の並び）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-CL0" \
  | tee "$OUT/06_締切.txt"

echo ""; echo "══ 凍結が壊れていないこと（監査行→凍結保存→読戻し）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | grep -E "○|×|ロールバック後|通りました|ありません"

echo ""; echo "══ JSONの厳密読込（新しい25件）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-J02" \
  | tee "$OUT/10_厳密JSON.txt" | head -30

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
