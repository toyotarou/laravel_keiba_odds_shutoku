#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# 10月20日006への是正（本物の不具合8件）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-33ELIG   6-2 33レース比較の資格を「1系統以上」だけにする
# @ANCHOR-20261020-R122-33FILL   6-2 適格馬が足りないときの枠移動を本体の規則で行う
# @ANCHOR-20261020-R122-33LIMIT  6-2 この比較で言えること・言えないことを明記する
# @ANCHOR-20261020-R122-REALTIME  集計2表に computed_at を足します（DBを変えます）
#   ・列は NULL 可。<b>既存行には時刻を入れません</b>（無い時刻は作らないため）。
#   ・今夜の集計（21:10 / 22:00）が1回まわれば本物の時刻が入り、
#     翌日からOPIが載ります。10月24日には間に合います。
#   ・列を足すだけなので、いまの商品の動きは変わりません。
# @ANCHOR-20261020-R122-CANCELSRC   3-1 取消は採用バッチだけから決める
# @ANCHOR-20261020-R122-CANDCANCEL  3-2 不採用バッチの取消を全頭確認に効かせない
# @ANCHOR-20261020-R122-EARLY       3-3 情報上限に達する前の凍結を断る
# @ANCHOR-20261020-R122-REALTIME    4-1 算出時刻の無い集計は使わない
# @ANCHOR-20261020-R122-DERIVEDAT   4-2 派生の時刻を親より過去へ戻さない
# @ANCHOR-20261020-R122-FPFAIL      6-5 指紋の欠け・失敗を合格にしない
# @ANCHOR-20261020-R122-COMPLETE   2-1 未完了応答を選出・順位点から外す
# @ANCHOR-20261020-R122-USEDORDER  2-3 未検証の順位を統合に使わない
# @ANCHOR-20261020-R122-ELIG       2-2 NO_EVIDENCE/UNVERIFIED を統合に入れない
# @ANCHOR-20261020-R122-FROZENK    3-4 取消で凍結K・帯内順位rを変えない
# @ANCHOR-20261020-R122-MICRO      4-3 追加材料の日時をマイクロ秒まで保つ
# @ANCHOR-20261020-R122-DEC        6-1 凍結順位の小数4桁制限を撤去
# @ANCHOR-20261020-R122-CORRKEY    5-1 訂正の保護を正規キー(score_status)で見る
# @ANCHOR-20261020-R122-SCOPEFIELD 5-2 summary_horses も範囲内で訂正できる
# @ANCHOR-20261020-R122-ROWVALID   5-3 row_valid=false の救済を適用しない
# @ANCHOR-20261020-R122-SALVAGE    5-2 採点欄だけの誤りで正常根拠を落とさない
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
# @ANCHOR-20261020-R122-REALTIME  集計側に算出時刻を記録する2本
cp "$HERE/code/SummaryPopularityRankAverage.php"     "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp "$HERE/code/SummaryFukuPopularityRankAverage.php" "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
    "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php" \
    "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
echo "  期待 AiController b479d9757557e3ece0c8c31ba98e611b"
echo "  期待 Command      78ea82cb5570d703a3f0b4d55d570c31"
ssh "$SRV" 'mkdir -p ~/r122_v57/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v57/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v57/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v57_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v57_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v57_$STAMP/"
# @ANCHOR-20261020-R122-REALTIME  集計2本も置換前を退避します（切戻し用）
cp -p "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"     "$BK/before_v57_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php" "$BK/before_v57_$STAMP/"
cp ~/r122_v57/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v57/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp ~/r122_v57/code/SummaryPopularityRankAverage.php     "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp ~/r122_v57/code/SummaryFukuPopularityRankAverage.php "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php \
       app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryPopularityRankAverage.php
php -l app/Console/Commands/SummaryFukuPopularityRankAverage.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ 集計2表に算出時刻の列を足す（NULL可・既存行は触りません）══"
# @ANCHOR-20261020-R122-REALTIME
#   無い時刻は作らないので、既存行は NULL のままにします。
#   今夜の集計（21:10 と 22:00）が1回まわれば、そこから本物の時刻が入ります。
php artisan tinker --execute='
  foreach ([
    "t_horse_odds_finder_popularity_rank_average",
    "t_horse_odds_finder_fuku_popularity_rank_average",
  ] as $t) {
    if (!\Schema::hasTable($t)) { echo "  － $t がありません" . PHP_EOL; continue; }
    if (\Schema::hasColumn($t, "computed_at")) {
      echo "  ○ $t には computed_at がすでにあります" . PHP_EOL;
    } else {
      \DB::statement("ALTER TABLE `$t` ADD COLUMN `computed_at` DATETIME(6) NULL COMMENT \"集計した実時刻（r12.2 時刻A案）\"");
      echo "  ＋ $t に computed_at を足しました（既存行は NULL）" . PHP_EOL;
    }
    $n = \DB::table($t)->whereNotNull("computed_at")->count();
    echo "     算出時刻の入っている行 : $n 行" . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$"

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

echo ""; echo "══ 33レースの候補比較（資格と枠移動を契約どおりに直しました）══"
php artisan keiba:ai-analysis-compensate --mode-check --r122-33races \
  --date=2026-10-17,2026-10-18 2>&1 \
  | grep -E "読んだレース|計算不能|確定|判定できない|対象|候補が|書き出しました|言えること|言えないこと|配分規則|資格は|不足時|統合経路|当時の実予想|全頭評価が無い" \
  | tee "$OUT/11_33レース.txt"

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
