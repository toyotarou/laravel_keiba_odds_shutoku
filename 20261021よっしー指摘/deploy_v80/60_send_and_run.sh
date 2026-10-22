#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v80 ＝ 10月21日010（なる）のご指摘のうち、こちらで直せるもの全部
# ══════════════════════════════════════════════════════════════════════
# このサーバの artisan は <b>PHP 8.0.30 ／ Laravel 8.83.29</b> です。
# なるの設計回答は Laravel 12.x の worker 仕様を引用しているので、
# 環境資料（r122_env.tgz）を添えて版を伝え直します。
#
# ── 先に、こちらの手違いを1つ ───────────────────────────────────
# @ANCHOR-20261021-R122-PHP80  readonly は PHP 8.1 からです
#   値オブジェクト（BaganrikiDecimal）を
#     public readonly string $raw
#   で書いていました。PHP 8.0.30 では<b>構文エラー</b>になります。
#   サーバには届いていません（当て直していなかったためです）。
#   コンストラクタのプロモーション（8.0 で使える）＋読み出し用メソッド
#   に書き替えました。あわせて 8.1〜8.4 の書き方を名指しで探す検査
#   （qt/lint_php80.py）をビルド毎に通すようにしました。
#   このコンテナの php は 8.3 なので、php -l では捕まりません。
#
# ── 010の2-4  早すぎる公開（並列化の競合）─────────────────────
# @ANCHOR-20261021-R122-SETTLE
#   ご指摘：
#     「TERMINALにはRECEIVEDが含まれます。初回応答をRECEIVEDにした後に
#       r122AfterReceiveで診断・訂正判断が始まります。このため、単純に
#       2roleを並列にすると、片方が『受領済みだが訂正の要否をまだ判定して
#       いない』間に、もう片方が両role完了とみなして公開へ進む競合が
#       起こり得ます。HTTP受領の終端と、診断・訂正・採否の確定は別に
#       管理してください。」
#   そのとおりです。いまの数え方は「HTTPが返った」までしか見ていません。
#   【いま】2つを分けました。
#     受領の終端 … baganriki_ai_attempts.status が TERMINAL
#     採否の確定 … baganriki_audit_events に kind='R122_ROLE_SETTLED'
#   印は「snapshot・role・応答の版」と結び付けます。版は、その role の
#   初回と訂正の (attempt_no, status, response_hash, complete) から作る
#   SHA-256 です。印を付けたあとに訂正が届けば版が変わり、印は効かなく
#   なって、もう一度待ちに戻ります。
#   <b>新しい表は作りません。</b>baganriki_audit_events は既にあります。
#   試験 X-22b〜X-22i（印なしでは公開しない／版が変われば待ちへ戻る）。
#
# ── 010の2-3 末尾  期限ぴったりの1回 ──────────────────────────
# @ANCHOR-20261021-R122-ATDEADLINE
#   ご指摘：
#     「現行 r122PublishWhenReady は $now > reply の判定なので、期限
#       ぴったりの1回でWAITINGになって終わらないよう、期限直後の
#       再判定まで含めて設計してください。」
#   `>` だと、期限と同じマイクロ秒に来た1回が WAITING で終わります。
#   `>=` にしました。旧式はコメントで残しています。試験 X-23b。
#
# ── 010の5章③  E-58e が順位変更がないことを証明していない ────
# @ANCHOR-20261021-R122-BANDPROVE
#   ご指摘：
#     「E-58eの説明は『他の帯の順位は保たれている』ですが、判定式は
#       band_ranksの件数が1以上かを見るだけです。これでは順位変更が
#       ないことを証明しません。対象外の帯ごとに初回の配列と一致するか
#       確認してください。」
#   そのとおりです。しかも初回（$broken）と訂正（$out1）は band_ranks が
#   同じ中身でした。保持できていなくても気づけない試験でした。
#   【いま】<b>訂正の側で、訂正の対象ではない帯の並びをわざと逆順</b>に
#   します。どの帯が対象かは実際の repair_scope から決めます（決め打ち
#   しません）。そのうえで、対象外の帯を1つずつ初回の配列と突き合わせ、
#   出どころが PREVIOUS_KEPT であること、訂正が違う並びを返していたことが
#   記録に残ることまで見ます。試験 E-58e・E-58e-0〜E-58e-4。
#
# ── 010の5章①  古い説明と、算出時刻のNULL埋め ────────────────
# @ANCHOR-20261021-R122-COMMENTFIX / @ANCHOR-20261021-R122-NOFILL
#   ご指摘：
#     「古い説明コメント『NULLならverified_atと同時刻を入れる』が
#       残っているため、現在の仕様と区別して整理すると誤読を防げます。」
#   コメントだけでなく、<b>コードもまだ埋めていました。</b>
#     $firstTime = DB::table($tbl)->whereNull('computed_at')
#                    ->update(['computed_at' => $computedAt]);
#   これは「いつ算出したか」をこちらで作ってしまいます。
#   【いま】埋めるのをやめ、<b>件数だけ数えて報告</b>します。
#   古い説明は消さずにコメントアウトし、いまの作りを並べて書きました。
#   computed_at は v71 の実行で既に入っているので、見た目は変わりません。
#   変えたのは「これから先も埋めない」という点です。
#
# ══════════════════════════════════════════════════════════════════════
# 置き換えるファイル（4本）
#   AiController.php                      9baecd3d3f1564ab9a48ab70ee89ae8e
#   SummaryAiAnalysisCompensate.php       fbdb1e22839114d2fa0be0fbc2441ba2
#   SummaryPopularityRankAverage.php      5d9648d932b0b4bffd119a05650f90a1
#   SummaryFukuPopularityRankAverage.php  ffe28f51db5f80c636bf342e4b2f29da
#
# 旗は動かしません（BAGANRIKI_R122_PROMPT=shadow／R122_QUEUE=0 のまま）。
# 実AIへの通信は0回です。crontab は1文字も触りません。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"                 "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"      "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php"     "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php"                      "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php"       "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$HERE/code/SummaryPopularityRankAverage.php"      "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp "$HERE/code/SummaryFukuPopularityRankAverage.php"  "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
    "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php" \
    "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
echo "  期待 AiController 9baecd3d3f1564ab9a48ab70ee89ae8e"
echo "  期待 Command      fbdb1e22839114d2fa0be0fbc2441ba2"
echo "  期待 集計(単勝)   5d9648d932b0b4bffd119a05650f90a1"
echo "  期待 集計(複勝)   ffe28f51db5f80c636bf342e4b2f29da"

ssh "$SRV" 'mkdir -p ~/r122_v80/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v80/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v80/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v80_$STAMP"
for F in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Console/Commands/SummaryPopularityRankAverage.php \
         app/Console/Commands/SummaryFukuPopularityRankAverage.php ; do
  cp -p "$APP/$F" "$BK/before_v80_$STAMP/"
done
cp ~/r122_v80/code/AiController.php                      "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v80/code/SummaryAiAnalysisCompensate.php       "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp ~/r122_v80/code/SummaryPopularityRankAverage.php      "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp ~/r122_v80/code/SummaryFukuPopularityRankAverage.php  "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"

echo ""; echo "══ 置き換え ══"
md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php \
       app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryPopularityRankAverage.php
php -l app/Console/Commands/SummaryFukuPopularityRankAverage.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ この artisan の版（なるへ伝え直す値）══"
php -v | head -1 | tee "$OUT/00_版.txt"
php artisan --version 2>&1 | tee -a "$OUT/00_版.txt"
echo "-- 8.1以降の書き方が1つも無いこと（readonly / enum / never / array_is_list）--"
grep -nE '(^|[^[:alnum:]_$>])readonly[^[:alnum:]_]|^[[:space:]]*enum[[:space:]]|\)[[:space:]]*:[[:space:]]*never\b|array_is_list[[:space:]]*\(' \
  app/Http/Controllers/Api/AiController.php \
  app/Console/Commands/SummaryAiAnalysisCompensate.php \
  | grep -vE ':[[:space:]]*(//|\*|#)' | tee -a "$OUT/00_版.txt" \
  || echo "  ○ 1つもありません（コメント内の言及だけです）" | tee -a "$OUT/00_版.txt"

echo ""; echo "══ 元値の指紋（試験の前）══"
php artisan keiba:ai-analysis-compensate --r122-fingerprint 2>&1 \
  | tee "$OUT/20_指紋_前.txt"
FP_BEFORE="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
if [ -n "${FP_BEFORE:-}" ]; then cp "$FP_BEFORE" "$OUT/20_指紋_前.json"; fi

echo ""; echo "══ 集計2表のいま（算出時刻は入っています。もう埋めません）══"
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    $r = \DB::table($t)->selectRaw("COUNT(*) c, SUM(computed_at IS NOT NULL) h,
            SUM(verified_at IS NOT NULL) v, MAX(computed_at) mx, MAX(verified_at) vx,
            MIN(start_date) sd, MAX(end_date) ed, SUM(`count`) sc,
            MD5(GROUP_CONCAT(CONCAT(popularity_rank,\":\",odds_average,\":\",`count`) ORDER BY popularity_rank SEPARATOR \"|\")) sig")->first();
    echo sprintf("  %-8s %2d行 算出時刻 %s 行（最新 %s）／確認時刻 %s 行（最新 %s）",
        $n, $r->c, $r->h, (string) $r->mx, $r->v, (string) $r->vx) . PHP_EOL;
    echo sprintf("           期間 %s〜%s／件数合計 %s／平均と件数の指紋 %s",
        substr((string) $r->sd, 0, 10), substr((string) $r->ed, 0, 10),
        (string) $r->sc, (string) $r->sig) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/24_集計2表.txt"
# ══════════════════════════════════════════════════════════════════════
# 【コメントアウトのまま】集計2本の手実行はしません。
#   この2本は「新規データなし」で終わるとき WebPush の開発者通知（SKIP）を
#   1通ずつ送ります。v70〜v75 の6回で12通が朝に飛びました。
#   確認時刻（verified_at）は毎晩の cron（21:10 / 22:00）が更新します。
# php artisan keiba:summaryPopularityRankAverage     2>&1 | tail -8
# php artisan keiba:summaryFukuPopularityRankAverage 2>&1 | tail -8
# ══════════════════════════════════════════════════════════════════════

echo ""; echo "══ キューと永続予約＋採否確定の印（010の2-4）══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"
echo ""; echo "-- 010の2-4 と 2-3 に当たるところだけ抜き出し --"
grep -E "X-2[0-3]" "$OUT/01_キュー.txt" | tee "$OUT/12b_採否確定.txt"

echo ""; echo "══ 送信口を偽応答に差し替えて実経路を通す（E群。帯の保持は010の5章③）══"
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/16_実経路.txt"
echo ""; echo "-- 帯の順位の保持だけ抜き出し（E-58e 群）--"
grep -E "E-58|対象外の帯の突き合わせ" "$OUT/16_実経路.txt" | tee "$OUT/16b_帯の保持.txt"

echo ""; echo "══ 固定入力をそのまま送る（Y群）══"
php artisan keiba:ai-analysis-compensate --r122-onebuild-test --date=2026-10-18 2>&1 \
  | tee "$OUT/14_固定入力.txt"

echo ""; echo "══ 止まったレースのやり直し ══"
php artisan keiba:ai-analysis-compensate --r122-sweep 2>&1 | tee "$OUT/13_やり直し.txt"

echo ""; echo "══ 期限での起動（試験は10秒だけ。本番の cron は 55秒／5秒）══"
php artisan keiba:ai-analysis-compensate --r122-sweep \
  --r122-sweep-watch=10 --r122-sweep-interval=5 2>&1 | tee "$OUT/15_期限起動.txt"

echo ""; echo "══ 応答検証と局所回復 ══"
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt"

echo ""; echo "══ 統合・公開・intent・revision ══"
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt"

echo ""; echo "══ 読取API ══"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt"

echo ""; echo "══ 本番の経路で作った入力 ══"
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 \
  | tee "$OUT/07_本番入力.txt"

echo ""; echo "══ 合成729件＋境界4件を本体へ直通／取消でCが変わる4例／繰上がり ══"
php artisan keiba:ai-analysis-compensate --r122-native-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/09_本体直通.txt"

echo ""; echo "══ OPI と推定確定オッズ ══"
php artisan keiba:ai-analysis-compensate --r122-market-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/08_OPI.txt"

echo ""; echo "══ 凍結が壊れていないこと ══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | grep -E "○|×|ロールバック後|通りました|ありません"

echo ""; echo "══ JSONの厳密読込・数値の正確な保持 ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "R122-J02|R122-J03|R122-J04|R122-J05|R122-E08" \
  | tee "$OUT/10_厳密JSONと桁.txt" | head -40

echo ""; echo "══ 受入試験（壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/19_受入試験_全文.txt" | grep -E "判定件数|合格 |不合格"

echo ""; echo "══ 元値の指紋（試験の後）＝ 前と突き合わせます ══"
if [ -n "${FP_BEFORE:-}" ]; then
  php artisan keiba:ai-analysis-compensate --r122-fingerprint="$FP_BEFORE" 2>&1 \
    | tee "$OUT/21_指紋_後_突き合わせ.txt"
  FP_AFTER="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
  if [ -n "${FP_AFTER:-}" ]; then cp "$FP_AFTER" "$OUT/21_指紋_後.json"; fi
else
  echo "  試験の前の指紋が取れていないので、突き合わせはできません"
fi

echo ""; echo "══ crontab は1文字も触っていないこと ══"
crontab -l 2>&1 | tee "$OUT/22_crontab.txt" | tail -5
echo "  （上は読み出しだけです。この手順で crontab は変更しません）"

echo ""; echo "══ 不合格の総まとめ ══"
grep -hE "不合格" "$OUT"/*.txt | sort | uniq -c | sed 's/^/  /'
echo "-- × が出た行（あれば）--"
grep -hE "^\s*×" "$OUT"/*.txt | head -40 || echo "  （× はありません）"

tar czf ~/r122_v80_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v80_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v80_logs.tgz" "$HERE/" \
  && echo "" \
  && echo "ログを取ってきました: $HERE/r122_v80_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v80_logs.tgz があります）"
