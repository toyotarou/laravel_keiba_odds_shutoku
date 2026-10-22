#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# 10月20日006への是正（本物の不具合8件）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v65：007の残りのうち、実AIなしで直せるものを全部入れました
# @ANCHOR-20261020-R122-RESUME     007の6-1 上限到達後にやり直せるようにする
#   ・TOO_EARLY で断ったときは空回答をキャッシュしません
#     （残すと次のアクセスがキャッシュ返却で終わり、やり直せません）
#   ・キャッシュを返すときも、固定入力が無ければ応答を返したあとに
#     1回だけ作り直します（画面は遅くなりません）。公開の起動もします
#   ・試験 X-31〜X-36
# @ANCHOR-20261020-R122-SWEEPWATCH 007の6-2 応答期限から公開期限の30秒を取りこぼさない
#   ・--r122-sweep に --r122-sweep-watch=55 --r122-sweep-interval=5 を足しました
#   ・1分おきのcronでも、巡回の間隔は最大5秒になります
#   ・コマンドの最後に cron の1行を出します
# @ANCHOR-20261020-R122-NUMEXACT   007の7 応答側の数値を丸めて通さない
#   ・数値トークンごとに原表現とPHPで持てる値を突き合わせます
#   ・1.99999999999999999999 や 20桁整数、2e308 は受け取りません
#   ・「別値へ変えない」ので、丸めた値を黙って通すことはしません
#   ・試験 R122-J03（14項目）
# ══════════════════════════════════════════════════════════════════════
# v64 で直したのは、v63 で落ちた4件の<b>期待値の側</b>です
#   R-18  対象外の馬を「初回と同じまま再掲する」訂正を拒否する動きを
#         合格にしていました。再掲は通すのが契約なので、試験のほうを
#         「本当に中身を変える訂正」に差し替えました。
#   P-39  この資料の正常出力はもともと採点が全部 null です。
#         「null の数」では見られないので、「応答にあった採点と
#         1項目も変わっていないこと」を見る形にしました。
#   P-46・P-47  予備根拠順位（evidence_band_ranks）が生きているときは、
#         明示縮退の<b>前に</b>そちらへ切り替わります（設計書10.2の順）。
#         期待値を EVIDENCE_RANK に直し、予備根拠順位も壊した場合に
#         明示縮退まで落ちることを P-53〜P-57 で別に見ます。
#   あわせて、訂正の中身が空のときは row_valid を要求しないようにしました
#   （時刻だけを見る呼び方のためです）。
# ══════════════════════════════════════════════════════════════════════
# 10月20日007への是正（なるの最小セットA〜F。実AIは呼びません）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-ONEBUILD  007の2（最優先）送信用と保存用の二重組み立て
#   送信用と保存用で入力を2回組み立てていたので、派生の計算時刻が変わり
#   snapshot_hash が一致せず、自分で自分の送信を拒否していました。
#   組み立ては1回だけにし、保存した本文をそのまま送ります（設計書7.2）。
#   作り直す側は r122AssembleFresh に分けました（送信には使いません）。
#   新しい通し試験 --r122-onebuild-test（Y-01〜Y-20）。
# @ANCHOR-20261020-R122-BANDKEEP   007の3-1 needs_fix ひとつで全帯の順位を捨てない
#   帯ごとに見て、指摘の無い帯はそのまま使い、使えない帯だけを
#   旧順位 → 予備根拠順位 → 明示縮退（独立根拠群数→馬番）の順に落とします。
#   P-34〜P-52。前回の P-34〜P-38 は「全帯を捨てる」動きを合格にしていたので
#   期待値のほうを直しました。
# @ANCHOR-20261020-R122-SCOREKEY   007の3-2 採点を全部 null にしない
#   おかしい項目だけ null にし、合計は残った項目から計算し直します。
#   observed_sum だけの誤りなら採点は1項目も消えません。
# @ANCHOR-20261020-R122-RESTATE    007の4 全頭返す正常な訂正を拒否しない
#   未変更の正常行の再掲と、対象外の書き換えを分けます（R122-V05）。
# @ANCHOR-20261020-R122-RESCUESCOPE 007の4 救済馬も horses の対象か明示検査
# @ANCHOR-20261020-R122-ROWVALIDREQ 007の4 row_valid を渡さない呼び方を断る
# @ANCHOR-20261020-R122-COMMITFIRST 007の5 未commitバッチの取消を活動集合に混ぜない
# @ANCHOR-20261020-R122-PAYSET      007の5 原ペイロードの重複・未知馬・取消併存を検出
# @ANCHOR-20261020-R122-WIRE2       007の6-4 設定検査を2ndの送信入口にも入れる
# @ANCHOR-20261020-R122-FPFLAG      --r122-fingerprint が値なしで素通りしていた
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-USEDORDER 2-3 未検証順位の試験を足す
#   なるが出した2つの例を候補生成まで通します（P-34〜P-40）。
#     ①1頭の採点集計だけを壊し、同時に対象外のB帯の並びを入れ替える
#     ②A帯に馬番999を混ぜる（順位集合不正）
#   コードは v57 までに直っていましたが、試験が無かったので足しました。
# @ANCHOR-20261020-R122-DIGITS2 6-1 桁を落とす最後の2か所を直す
#   ① PHPの数値で正確に持てない値を source_registry から<b>落としていた</b>のを、
#      元の十進数の文字列のまま登録する形へ。Schemaのvalueは string も可。
#      信号の計算は文字列をそのまま多倍長のたすき掛けで比べるので桁が落ちません。
#   ② numToDecimalString が number_format(12桁) で13桁目から下を落としていたのを、
#      json_encode（元へ戻せる最短表記）＋指数表記の自前展開へ。
#   ③ scale()（小数4桁制限）が判定経路から消えていることを試験で見張る。
#   試験 R122-E08（12項目）。
# v60 の変更点（v59の出力で見つけた注記の食い違いを1か所だけ直しました）
#   取消の表の脚注に「999=最初の取得」と書いていましたが、この比較は
#   999 を読んでいません（読むのは 21・18・15・12・9・6・3・確定 の8つ）。
#   表のcancel_slotsを直接数えた値と並びが違う理由も脚注に書きました。
# @ANCHOR-20261020-R122-33CANCEL 6-3 取消の説明をコードと実データに揃える
#   ・京都9R7番の取消は確定(-999)の行にしかありません。6分前は実オッズ39.8です。
#   ・よって「6分前だから残す」ではなく「期限までに正式取消が無いから残る」です。
#   ・取消があった3件（10/17東京5R5番・10/18新潟5R3番・10/18京都9R7番）の
#     cancel_slots・6分前の生値・分類・頭数を実データで出します。
#   ・この表には取得時刻の列が無いことも明記します（時刻では示せません）。
# @ANCHOR-20261020-R122-REALTIME 「列が無い」と「まだ値が無い」を区別（新規）
#   v58 の出力で computed_at の列を足したのに「列なし」と出ていたのを直します。
# @ANCHOR-20261020-R122-WIRE    6-4 実送信の後へ検証・訂正・公開を接続する（新規）
#   ・1st …応答を返したあと（app()->terminating()）に 検証→訂正→解決→公開
#   ・2nd …その場で 検証→訂正→解決→公開（両roleが終端になるので公開まで）
#   ・公開は両roleが終端になってから。応答期限を過ぎたら待たない
#   ・PROMPT=1 で QUEUE=0 という組み合わせを設定で作れないようにした
#   ・--r122-sweep で、止まったレースの訂正判定と公開だけやり直せる
#   ・呼出経路そのものを Reflection で見る試験を入れた（X-24〜X-30）
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
echo "  期待 AiController 4ab485b3152a12c121c08497f581d8ef"
echo "  期待 Command      acfb84333280a4eca5a3a210c8778792"
ssh "$SRV" 'mkdir -p ~/r122_v65/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v65/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v65/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v65_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v65_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v65_$STAMP/"
# @ANCHOR-20261020-R122-REALTIME  集計2本も置換前を退避します（切戻し用）
cp -p "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"     "$BK/before_v65_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php" "$BK/before_v65_$STAMP/"
cp ~/r122_v65/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v65/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp ~/r122_v65/code/SummaryPopularityRankAverage.php     "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp ~/r122_v65/code/SummaryFukuPopularityRankAverage.php "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
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

echo ""; echo "══ キューと永続予約＋送信口＋旗の段＋実送信の後への接続（67＋30件）══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"

echo ""; echo "══ 実送信の後への接続だけ抜き出し（006の6-4。X-01〜X-30）══"
grep -E "X-[0-9]|⑬" "$OUT/01_キュー.txt" | tee "$OUT/12_接続.txt"

echo ""; echo "══ 固定入力をそのまま送る（007の2。Y-01〜Y-20）══"
php artisan keiba:ai-analysis-compensate --r122-onebuild-test --date=2026-10-18 2>&1 \
  | tee "$OUT/14_固定入力.txt"

echo ""; echo "══ 止まったレースのやり直し（初回の送信はしません）══"
php artisan keiba:ai-analysis-compensate --r122-sweep 2>&1 | tee "$OUT/13_やり直し.txt"

echo ""; echo "══ 期限での起動（1分cronでも最大5秒おきに回せること）══"
# 試験としては短く10秒だけ回します。本番の cron は 55秒／5秒です。
php artisan keiba:ai-analysis-compensate --r122-sweep \
  --r122-sweep-watch=10 --r122-sweep-interval=5 2>&1 | tee "$OUT/15_期限起動.txt"

echo ""; echo "══ 応答検証と局所回復（正常14本＋局所回復＋訂正＋全頭再掲）══"
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt"

echo ""; echo "══ 統合・公開・intent・revision（40＋17件）══"
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
  | tee "$OUT/11_33レース.txt"
# ※ ログには全文を残します（grepで行が欠けると、なるが生の表と突き合わせた
#   ときに「説明が足りない」と見えるためです）。端末には要約だけ出します。
grep -E "読んだレース|計算不能|確定|対象|候補が|書き出しました|取消があったレース|cancelPre|cancelPost|cancelLate|言えること|言えないこと" \
  "$OUT/11_33レース.txt"

echo ""; echo "══ 投票締切の正本（発走時刻から出した期限の並び）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-CL0" \
  | tee "$OUT/06_締切.txt"

echo ""; echo "══ 凍結が壊れていないこと（監査行→凍結保存→読戻し）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | grep -E "○|×|ロールバック後|通りました|ありません"

echo ""; echo "══ JSONの厳密読込・数値の正確な保持・桁落ちの是正 ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "R122-J02|R122-J03|R122-E08" \
  | tee "$OUT/10_厳密JSONと桁.txt" | head -60

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

# ── 提出用にログとCSVを1つにまとめます ────────────────────────────
#   33レースのCSVは storage/logs に出るので、結果フォルダへ入れてから固めます。
CSV="$(ls -t /var/www/horse_odds_finder/storage/logs/r122_33races_*.csv 2>/dev/null | head -1)"
if [ -n "$CSV" ]; then cp "$CSV" "$OUT/05_33レース候補比較.csv"; fi
tar czf ~/r122_v65_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "まとめました: ~/r122_v65_logs.tgz"
REMOTE

# ── ログを手元へ持ってきます（提出物の組み上げに使います）────────────
scp -q "$SRV:~/r122_v65_logs.tgz" "$HERE/" \
  && echo "" \
  && echo "ログを取ってきました: $HERE/r122_v65_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v65_logs.tgz があります）"
