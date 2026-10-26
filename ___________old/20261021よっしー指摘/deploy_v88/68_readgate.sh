#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v88 ＝ 読取APIを非公開にする（010の7章⑤。<b>本物の穴でした</b>）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-READGATE
#
# ■ 見つかったこと
#   読取API GET /api/baganriki/r12-2/races/{race_id} に
#   <b>認証が1つも掛かっていませんでした。</b>
#   routes/api.php 150行目。middleware の指定がありません。
#
#   さらに画面側（Flutter）の lib/screens/page/race_content_page.dart の
#   909行目付近に、<b>誰にでも見える「r12.2」のボタン</b>が出ています。
#   管理者判定も debug 判定も掛かっていません。
#
#   いまは記録が1件もないので空が返るだけです。ですが
#   <b>10月24日に旗を上げた瞬間、その12レースの予想が
#   一般の利用者に見えます。</b>「非公開の検証」になりません。
#   race_id は「日付-回-場-日-R」なので、総当たりも簡単です。
#
# ■ 塞ぎ方（既定は閉じる）
#   .env の BAGANRIKI_R122_READ で決めます。
#     （未設定・key） 鍵が合ったときだけ中身を返す【既定】
#     open           誰でも見られる
#     off            誰にも見せない（鍵があっても）
#   鍵は BAGANRIKI_R122_READ_KEY に入れ、
#     ヘッダ  X-R122-Key: <鍵>
#     クエリ  ?r122_key=<鍵>
#   のどちらかで渡します。
#   <b>鍵が未設定なら、誰も中身を見られません。</b>
#   対象レースの限定（TARGETS が空なら1レースも送らない）と同じ考え方で、
#   設定を入れ忘れた側が安全に倒れます。
#
#   断るときの返しは「このレースは正本に存在しません」と同じ形にします。
#   <b>記録が在るかどうかも漏らさない</b>ためです。
#
# ■ 画面（Flutter）は作り直しません
#   鍵を渡さないので「まだありません」と出るだけです。
#   検証する側は、鍵を付けてURLを直接開けば中身を読めます。
#     https://…/api/baganriki/r12-2/races/20261024-5-05-1-1?r122_key=<鍵>
#   PWAの再ビルドを24日までに挟まずに済みます。
#
# ■ artisan（コマンド）からは素通りします
#   外から叩けないので止める意味がなく、止めると試験が全部落ちます。
#
# ■ 試験 A-30〜A-39（10項目）
#   鍵が未設定／違う／渡さない／合う、open、off、知らない値、
#   コマンドからの素通り、を全部見ます。
#
# 置き換えるファイル（2本）
#   AiController.php                 13d67b03f00fe07b13ba8916c19eec78
#   SummaryAiAnalysisCompensate.php  3c526ef50e5a1382cdcf7f137e1209e2
#
# .env には何も足しません（既定で閉じます）。旗も動かしません。
# worker も起動しません。実AIへの通信は0回です。
# crontab は読み出しだけです。
# ══════════════════════════════════════════════════════════════════════
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
echo "  期待 AiController 13d67b03f00fe07b13ba8916c19eec78"
echo "  期待 Command      3c526ef50e5a1382cdcf7f137e1209e2"

ssh "$SRV" 'mkdir -p ~/r122_v88/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v88/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v88/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v88_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v88_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v88_$STAMP/"
cp ~/r122_v88/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v88/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"

echo ""; echo "══ ① 置き換え ══"
md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php | tee "$OUT/00_版.txt"
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ ② 読取APIが、外から中身を返さないこと ══"
# いまの .env には BAGANRIKI_R122_READ も READ_KEY もありません。
# ＝ 既定（key）で、鍵が未設定なので「誰にも見せない」はずです。
echo "-- いまの設定（どちらも無いのが正しい）--"
grep -E "^BAGANRIKI_R122_READ" .env 2>/dev/null \
  || echo "  BAGANRIKI_R122_READ / READ_KEY は未設定（＝閉じています）" | tee "$OUT/40_読取の段.txt"
echo "-- 実際に外から叩いてみます（localhost 経由）--"
for RID in 20261018-4-04-2-1 20261024-5-05-1-1 ; do
  echo "   race_id=$RID"
  curl -s -o /tmp/r122read.json -w "     HTTP %{http_code}\n" \
    "http://127.0.0.1/api/baganriki/r12-2/races/$RID" 2>/dev/null \
    || echo "     （curl できませんでした）"
  head -c 300 /tmp/r122read.json 2>/dev/null; echo ""
done | tee -a "$OUT/40_読取の段.txt"
echo "   ※ state=UNAVAILABLE / code=NOT_PUBLIC が返れば塞がっています"

echo ""; echo "══ ③ 読取APIの試験（A-30〜A-39 を含む）══"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt"
echo ""; echo "-- 非公開の見張りだけ抜き出し --"
grep -E "A-3[0-9]|非公開" "$OUT/05_読取API.txt" | tee "$OUT/05b_非公開の読取.txt"

echo ""; echo "══ ④ 壊れていないこと ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/19_受入試験_全文.txt" | grep -E "判定件数|合格 |不合格"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 \
  | tee "$OUT/01_キュー.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-job-test 2>&1 \
  | tee "$OUT/35_Job構成.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-type-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/37_型保持.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/38_実凍結から一続き.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/16_実経路.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt" | tail -4

echo ""; echo "══ ⑤ 不合格の総まとめ ══"
grep -hE "不合格" "$OUT"/*.txt | sort | uniq -c | sed 's/^/  /'
echo "-- × が出た行（あれば）--"
grep -hE "^\s*×" "$OUT"/*.txt | sort -u | head -30 || echo "  （× はありません）"

tar czf ~/r122_v88_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v88_logs.tgz"
REMOTE

scp -q "$SRV:~/r122_v88_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v88_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v88_logs.tgz があります）"
