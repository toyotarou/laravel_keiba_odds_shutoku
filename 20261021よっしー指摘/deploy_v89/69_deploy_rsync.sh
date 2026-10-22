#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v89 ＝ rsync でデプロイ（<b>変更のあった2本だけ</b>）
# ══════════════════════════════════════════════════════════════════════
# なるさんが火曜まで使えないので、いまの版をそのまま出します。
# 10月24日（土）はレビュー無しで迎えることになります。
#
# ── 出すファイル（2本だけ）────────────────────────────────────────
#   app/Http/Controllers/Api/AiController.php
#       2c6460626989c78bf542db65ef1fb3a8 → 0f62cbf053e6b5bd570d8daf35c8f721
#   app/Console/Commands/SummaryAiAnalysisCompensate.php
#       e05ccaa64eda0225d3b24a25a32edd60 → 5e28e387f4f5036630054c7714fff79a
#
# ── 出さないファイル（サーバと同じなので触りません）────────────────
#   app/Console/Commands/SummaryPopularityRankAverage.php      5d9648d9…
#   app/Console/Commands/SummaryFukuPopularityRankAverage.php  ffe28f51…
#   app/Jobs/R122Job.php                                       b5427782…
#   app/Jobs/R122FreezeRaceJob.php                             f72f0dc9…
#   app/Jobs/R122RunRoleJob.php                                e6c61f1a…
#   app/Jobs/R122AggregateRaceJob.php                          319ce857…
#   app/Jobs/R122UpdateRaceLiveJob.php                         872b4afb…
#   config/queue.php  ← サーバ側で直接1節を足してあります。
#                        Mac 側に同じものが無いので、送ると消えます。
#   systemd 2本       ← OS側（/usr/local/bin・/etc/systemd/system）。
#                        常駐を立てるときだけ、root権限で置きます。
#   この7本＋αは<b>送りませんが、ハッシュだけ確かめます。</b>
#   知らないうちに変わっていないかを見るためです。
#
# ══════════════════════════════════════════════════════════════════════
# 今回の変更の中身（010の7章⑤。<b>本物の穴でした</b>）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-READGATE  読取APIに認証が無かった
#   GET /api/baganriki/r12-2/races/{race_id} に middleware の指定が
#   ありませんでした（routes/api.php 150行目）。誰でも叩けます。
#   さらに画面（Flutter）の race_content_page.dart 909行目付近に
#   <b>誰にでも見える「r12.2」のボタン</b>が出ています。
#   管理者判定も debug 判定も掛かっていません。
#
#   いまは記録が0件なので空が返るだけです。ですが
#   <b>24日に旗を上げた瞬間、12レースの予想が一般の利用者に見えます。</b>
#   race_id は「日付-回-場-日-R」なので総当たりも簡単です。
#
#   既定で閉じました。
#     BAGANRIKI_R122_READ  未設定/key … 鍵が合ったときだけ返す【既定】
#                          open       … 誰でも見られる
#                          off        … 誰にも見せない
#     BAGANRIKI_R122_READ_KEY に鍵を入れ、
#       ヘッダ X-R122-Key: <鍵>  か  ?r122_key=<鍵>  で渡します。
#   <b>鍵が未設定なら、誰も中身を見られません。</b>
#   .env には何も足しません（足さない状態が「閉じている」です）。
#
# @ANCHOR-20261021-R122-READGATE200  断るときも HTTP 200 で返します
#   画面の lib/data/http/client.dart は 2xx 以外を<b>すべて例外</b>に
#   します（49〜51行）。404 で断ると画面に
#     「読めませんでした  Exception: http 404 [url=https://…]」
#   と生のエラー文字列とURLが出ます（display_alert 98行）。
#   なので 200 で、いつもの形のまま state=UNAVAILABLE と理由を返します。
#   画面は diagnostics をそのまま並べるので（同380行）、
#   「この記録はまだ公開していません」と読める形で出ます。
#   <b>Flutter は1行も直していません。</b>作り直し不要です。
#
#   試験 A-30〜A-41（12項目）。
#
# ══════════════════════════════════════════════════════════════════════
# 使い方
#   bash 69_deploy_rsync.sh        下見だけ（本番には書きません）
#   bash 69_deploy_rsync.sh go     本番へ書きます
#
#   --delete は使いません。フォルダまるごとの同期もしません。
#   Mac 側の _____laravel は写しで .env・vendor・storage・database が
#   無いので、まるごと同期するとサーバにしか無いものが消えます。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
APP=/var/www/horse_odds_finder
DRY="${1:-}"

if [ "$DRY" = "go" ]; then
  RSYNC_OPT="-avz"
  echo "══ 本番へ書きます ══"
else
  RSYNC_OPT="-avzn"
  echo "══ 下見だけします（本番には書きません）══"
  echo "   本当に出すときは  bash $(basename "$0") go"
fi
echo ""

# ── ① 出す前に、サーバのいまを確かめます ────────────────────────────
echo "── 出す前のサーバ（置き換わるのはこの2本だけです）──"
ssh "$SRV" "cd $APP && md5sum \
  app/Http/Controllers/Api/AiController.php \
  app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "   いまの AiController  2c6460626989c78bf542db65ef1fb3a8"
echo "   いまの Compensate    e05ccaa64eda0225d3b24a25a32edd60"
echo ""

# ── ② 退避 ──────────────────────────────────────────────────────────
STAMP="$(date +%Y%m%d_%H%M%S)"
if [ "$DRY" = "go" ]; then
  ssh "$SRV" "mkdir -p ~/baganriki_20261018/before_v89_$STAMP && \
    cp -p $APP/app/Http/Controllers/Api/AiController.php \
          $APP/app/Console/Commands/SummaryAiAnalysisCompensate.php \
          ~/baganriki_20261018/before_v89_$STAMP/ && \
    echo '  退避しました: ~/baganriki_20261018/before_v89_$STAMP'"
  echo ""
fi

# ── ③ rsync（2本だけ。名指し）───────────────────────────────────────
echo "── 1本目 AiController.php ──"
rsync $RSYNC_OPT --chmod=F644 \
  "$HERE/app/Http/Controllers/Api/AiController.php" \
  "$SRV:$APP/app/Http/Controllers/Api/AiController.php"

echo ""; echo "── 2本目 SummaryAiAnalysisCompensate.php ──"
rsync $RSYNC_OPT --chmod=F644 \
  "$HERE/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
  "$SRV:$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"

if [ "$DRY" != "go" ]; then
  echo ""
  echo "══ 下見はここまでです ══"
  echo "   上の2本が置き換わります。よければ  bash $(basename "$0") go"
  exit 0
fi

# ── ④ 置いたあとの確認 ──────────────────────────────────────────────
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
cd "$APP"
chown apache:apache app/Http/Controllers/Api/AiController.php 2>/dev/null || true
chown apache:apache app/Console/Commands/SummaryAiAnalysisCompensate.php 2>/dev/null || true

echo ""; echo "══ ④ 置き換えた2本 ══"
md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php
echo "  期待 AiController 0f62cbf053e6b5bd570d8daf35c8f721"
echo "  期待 Compensate   5e28e387f4f5036630054c7714fff79a"
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php

echo ""; echo "══ ⑤ 送っていないファイル（変わっていないこと）══"
md5sum app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php \
       app/Jobs/*.php
cat <<'EXP'
  期待 PopAvg               5d9648d932b0b4bffd119a05650f90a1
  期待 FukuAvg              ffe28f51db5f80c636bf342e4b2f29da
  期待 R122AggregateRaceJob 319ce857b8c5b938c52d8adf9c3d8582
  期待 R122FreezeRaceJob    f72f0dc95dc30bfd9a2838afeb698e75
  期待 R122Job              b5427782fe211b55866e779b8d200c72
  期待 R122RunRoleJob       e6c61f1a5d316b5b58c2d887af14ba3e
  期待 R122UpdateRaceLiveJob 872b4afb67e64f4438ee726156a37dd7
EXP
echo "-- config/queue.php の r122 の節が残っていること --"
grep -c "R122-QUEUECONF" config/queue.php | sed 's/^/  見つかった数 : /'

php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear  >/dev/null 2>&1 || true

echo ""; echo "══ ⑥ 読取APIが外から中身を返さないこと ══"
grep -E "^BAGANRIKI_R122_READ" .env 2>/dev/null \
  || echo "  BAGANRIKI_R122_READ / READ_KEY は未設定（＝閉じています）"
for RID in 20261018-4-04-2-1 20261024-5-05-1-1 ; do
  echo "   race_id=$RID"
  curl -s -o /tmp/r122read.json -w "     HTTP %{http_code}\n" \
    "http://127.0.0.1/api/baganriki/r12-2/races/$RID" 2>/dev/null \
    || echo "     （curl できませんでした）"
  head -c 240 /tmp/r122read.json 2>/dev/null; echo ""
done
echo "   ※ HTTP 200 ＋ state=UNAVAILABLE ＋ NOT_PUBLIC なら塞がっています"
echo "   ※ 200 なのは、画面が 2xx 以外を全部エラー文字列で出すためです"

echo ""; echo "══ ⑦ 壊れていないこと ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee /tmp/r122read_test.txt | tail -4
echo "-- 非公開の見張りだけ --"
grep -E "A-3[0-9]|A-4[01]" /tmp/r122read_test.txt
for OPT in "--r122-queue-test" "--r122-job-test" ; do
  php artisan keiba:ai-analysis-compensate $OPT 2>&1 | tail -4
done
for OPT in "--r122-type-test" "--r122-wire-test" "--r122-publish-test" "--r122-response-test" ; do
  php artisan keiba:ai-analysis-compensate $OPT="$HOME/r122_v23/mat" 2>&1 | tail -4
done
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tail -4

echo ""; echo "══ ⑧ 旗（どれも上がっていないこと）══"
grep -E "^BAGANRIKI_" .env
echo ""; echo "══ ⑨ 常駐（1枠も起動していないこと）══"
systemctl list-units 'r122-worker@*' --all --no-legend 2>/dev/null
echo "  （上が空なら1枠も起動していません）"
echo ""; echo "══ ⑩ crontab（読み出しだけ・変えていません）══"
crontab -l 2>&1 | tail -3
REMOTE

echo ""
echo "══ 出しました ══"
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
cp "$HERE/app/Http/Controllers/Api/AiController.php"            "$MACAPP/app/Http/Controllers/Api/"
cp "$HERE/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/"
echo "   Mac 側の写しも同じ中身にそろえました"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
