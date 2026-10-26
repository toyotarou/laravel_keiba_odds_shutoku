#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v89 ＝ rsync でデプロイします（なるの確認を待たずに出す版）
# ══════════════════════════════════════════════════════════════════════
# なるさんが火曜まで使えないので、いまの版をそのまま出します。
# 10月24日（土）はレビュー無しで迎えることになります。
#
# ══════════════════════════════════════════════════════════════════════
# v88 から足したもの（010の7章⑤。<b>本物の穴でした</b>）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-READGATE
#   読取API GET /api/baganriki/r12-2/races/{race_id} に
#   <b>認証が1つも掛かっていませんでした</b>（routes/api.php 150行目）。
#   さらに画面（Flutter）の race_content_page.dart 909行目付近に
#   <b>誰にでも見える「r12.2」のボタン</b>が出ています。
#   いまは記録が0件なので空が返るだけですが、24日に旗を上げた瞬間、
#   12レースの予想が一般の利用者に見えます。
#   既定で閉じました。BAGANRIKI_R122_READ_KEY の鍵が合ったときだけ
#   中身を返します。鍵が未設定なら誰も見られません。
#
# @ANCHOR-20261021-R122-READGATE200  断るときも HTTP 200 で返します
#   画面の lib/data/http/client.dart は 2xx 以外を<b>すべて例外</b>に
#   します（49〜51行）。404 で断ると、画面に
#     「読めませんでした  Exception: http 404 [url=https://…]」
#   と生のエラー文字列とURLが出ます。
#   なので 200 で、いつもの形のまま state=UNAVAILABLE と理由を返します。
#   画面は diagnostics をそのまま並べるので
#   「この記録はまだ公開していません」と読める形で出ます。
#   <b>Flutter は1行も直していません。</b>作り直し不要です。
#
# ══════════════════════════════════════════════════════════════════════
# rsync で出すもの（<b>名指しだけ</b>。--delete は使いません）
# ══════════════════════════════════════════════════════════════════════
#   app/Http/Controllers/Api/AiController.php            0f62cbf053e6b5bd570d8daf35c8f721
#   app/Console/Commands/SummaryAiAnalysisCompensate.php 5e28e387f4f5036630054c7714fff79a
#   app/Console/Commands/SummaryPopularityRankAverage.php     5d9648d932b0b4bffd119a05650f90a1
#   app/Console/Commands/SummaryFukuPopularityRankAverage.php ffe28f51db5f80c636bf342e4b2f29da
#   app/Jobs/ 5本
#
# <b>フォルダまるごとの同期はしません。</b>
#   Mac 側の _____laravel は写しで、.env・vendor・storage・database が
#   ありません。まるごと rsync すると、サーバにしか無いものが
#   消えるおそれがあります。--delete も付けません。
#
# config/queue.php は<b>送りません。</b>サーバ側で直接1節を足してあり、
#   Mac 側には同じものがありません。上書きすると消えます。
#
# 先に --dry-run で「何が変わるか」だけ見ます。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
APP=/var/www/horse_odds_finder
DRY="${1:-}"      # 引数に go を付けたときだけ本番へ書きます

if [ "$DRY" = "go" ]; then
  RSYNC_OPT="-avz"
  echo "══ 本番へ書きます ══"
else
  RSYNC_OPT="-avzn"
  echo "══ 下見だけします（本番には書きません）══"
  echo "   本当に出すときは、最後に go を付けてください"
  echo "   bash $(basename "$0") go"
fi
echo ""

# ── ① 退避（rsync の前に、いまのサーバの現物を取っておきます）────────
STAMP="$(date +%Y%m%d_%H%M%S)"
if [ "$DRY" = "go" ]; then
  ssh "$SRV" "mkdir -p ~/baganriki_20261018/before_v89_$STAMP && \
    cp -p $APP/app/Http/Controllers/Api/AiController.php \
          $APP/app/Console/Commands/SummaryAiAnalysisCompensate.php \
          $APP/app/Console/Commands/SummaryPopularityRankAverage.php \
          $APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php \
          ~/baganriki_20261018/before_v89_$STAMP/ 2>/dev/null; \
    mkdir -p ~/baganriki_20261018/before_v89_$STAMP/Jobs && \
    cp -p $APP/app/Jobs/*.php ~/baganriki_20261018/before_v89_$STAMP/Jobs/ 2>/dev/null; \
    echo '  退避しました: ~/baganriki_20261018/before_v89_$STAMP'"
  echo ""
fi

# ── ② rsync（名指しのファイルだけ）──────────────────────────────────
echo "── Controller ──"
rsync $RSYNC_OPT --chmod=F644 \
  "$HERE/app/Http/Controllers/Api/AiController.php" \
  "$SRV:$APP/app/Http/Controllers/Api/AiController.php"

echo ""; echo "── Command 3本 ──"
rsync $RSYNC_OPT --chmod=F644 \
  "$HERE/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
  "$HERE/app/Console/Commands/SummaryPopularityRankAverage.php" \
  "$HERE/app/Console/Commands/SummaryFukuPopularityRankAverage.php" \
  "$SRV:$APP/app/Console/Commands/"

echo ""; echo "── Jobs 5本（フォルダごと。--delete は付けません）──"
rsync $RSYNC_OPT --chmod=F644 \
  "$HERE/app/Jobs/" \
  "$SRV:$APP/app/Jobs/"

if [ "$DRY" != "go" ]; then
  echo ""
  echo "══ 下見はここまでです ══"
  echo "   上に出たファイルが置き換わります。よければ"
  echo "   bash $(basename "$0") go"
  exit 0
fi

# ── ③ 置いたあとの確認 ──────────────────────────────────────────────
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
cd "$APP"
echo ""; echo "══ ③ 置いたあとの確認 ══"
chown apache:apache app/Http/Controllers/Api/AiController.php 2>/dev/null || true
chown apache:apache app/Console/Commands/*.php 2>/dev/null || true
chown -R apache:apache app/Jobs 2>/dev/null || true

md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php \
       app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php \
       app/Jobs/*.php
echo ""
echo "  期待 AiController    0f62cbf053e6b5bd570d8daf35c8f721"
echo "  期待 Compensate      5e28e387f4f5036630054c7714fff79a"
echo "  期待 PopAvg          5d9648d932b0b4bffd119a05650f90a1"
echo "  期待 FukuAvg         ffe28f51db5f80c636bf342e4b2f29da"
echo ""
for F in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Console/Commands/SummaryPopularityRankAverage.php \
         app/Console/Commands/SummaryFukuPopularityRankAverage.php \
         app/Jobs/*.php ; do
  php -l "$F"
done
php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear  >/dev/null 2>&1 || true

echo ""; echo "══ ④ 読取APIが外から中身を返さないこと ══"
echo "-- 設定（どちらも無いのが正しい＝閉じています）--"
grep -E "^BAGANRIKI_R122_READ" .env 2>/dev/null \
  || echo "  BAGANRIKI_R122_READ / READ_KEY は未設定（＝閉じています）"
echo "-- 実際に叩いてみます --"
for RID in 20261018-4-04-2-1 20261024-5-05-1-1 ; do
  echo "   race_id=$RID"
  curl -s -o /tmp/r122read.json -w "     HTTP %{http_code}\n" \
    "http://127.0.0.1/api/baganriki/r12-2/races/$RID" 2>/dev/null \
    || echo "     （curl できませんでした）"
  head -c 240 /tmp/r122read.json 2>/dev/null; echo ""
done
echo "   ※ HTTP 200 ＋ state=UNAVAILABLE ＋ NOT_PUBLIC なら塞がっています"
echo "   ※ 200 なのは、画面が 2xx 以外を全部エラー文字列で出すためです"

echo ""; echo "══ ⑤ 壊れていないこと ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee /tmp/r122read_test.txt | tail -4
echo "-- 非公開の見張りだけ --"
grep -E "A-3[0-9]|A-4[01]" /tmp/r122read_test.txt
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-job-test 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-type-test="$HOME/r122_v23/mat" 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 | tail -4
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 | tail -4

echo ""; echo "══ ⑥ 旗（どれも上がっていないこと）══"
grep -E "^BAGANRIKI_" .env
echo ""; echo "══ ⑦ 常駐（1枠も起動していないこと）══"
systemctl list-units 'r122-worker@*' --all --no-legend 2>/dev/null \
  || echo "  （1枠も起動していません）"
echo ""; echo "══ ⑧ crontab（読み出しだけ・変えていません）══"
crontab -l 2>&1 | tail -3
REMOTE

echo ""
echo "══ 出しました ══"
echo "   Mac 側の写しも、同じ中身にそろえておきます"
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
mkdir -p "$MACAPP/app/Jobs"
cp "$HERE/app/Http/Controllers/Api/AiController.php"      "$MACAPP/app/Http/Controllers/Api/"
cp "$HERE/app/Console/Commands/"*.php                     "$MACAPP/app/Console/Commands/"
cp "$HERE/app/Jobs/"*.php                                 "$MACAPP/app/Jobs/"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php"
