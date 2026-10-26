#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v86 ＝ e2e試験の残り3件（Command 1本。AiController は触りません）
# ══════════════════════════════════════════════════════════════════════
# v85 の結果（2026-10-21 13:53）
#   受入試験 662 件すべて合格。e2e は 27件中24件合格。
#   <b>実凍結と期限の幅は全部通りました。</b>
#     G-03 出馬表と監査行から実際に凍結できた
#     G-05 発走時刻が、寄せた時刻として保存されている
#     G-06 投票締切は発走の60秒前（導出。渡していません）
#     G-07 情報上限 −360s ／ G-08 入力凍結 −330s
#     G-09 応答期限 −150s ／ G-10 公開期限 −120s
#     G-13〜G-17 初回を両roleへ実際に送って受領
#     G-20・G-21 両roleの採否が確定
#   3,600秒の決め打ちは1つもありません。
#
# 残っていた3件を直します。
#
# @ANCHOR-20261021-R122-E2EUSAGE  G-18（期待値の数え違い）
#   使用量が2件だと見込んでいましたが、<b>4件</b>でした。
#   初回に直すところがあったので、両roleで訂正も飛びます。
#   role ごとに 初回1件＋訂正1件 ＝ 合計4件です。正しい動きです。
#   訂正のぶんを数えていませんでした。
#   role ごとに attempt_no が [0,1] であることを見る形にしました。
#
# @ANCHOR-20261021-R122-E2EEVID  G-22・G-23 の原因（偽応答の作り方）
#   偽の応答を、雛形の<b>1頭目をそのまま使い回して</b>作っていました。
#   そのため全頭が「1番の根拠（h1_…）」を指します。
#   診断は「自分のSIGNALが1系統も無い馬」を外すので、2番以降が全部
#   落ちて、採用できる応答が無くなりました（NO_RESPONSE）。
#   この凍結入力に実際に在る参照（h{馬番}_win_odds_6）へ、
#   馬ごとに書き替えるようにしました。
#
# @ANCHOR-20261021-R122-E2ESCOPE  この試験が示す範囲を、はっきり書きました
#   <b>公開の中身（候補の選定・加点・帯順位）は、この試験では
#   確かめません。</b>偽の応答は実在のレースの凍結入力に合わせて
#   組み立てたもので、送付資料の正常出力のように採点・根拠・5系統まで
#   整合しているわけではありません。
#   候補の選定は、資料の正常出力を使う試験
#   （公開 67件・実経路 E群・応答検証 76件）で確認しています。
#
#   ここで確かめるのは次の2つに絞りました。
#     G-22  公開判定が起動し、<b>待ちにはならない</b>こと
#           （両roleの採否が確定しているので待ちにはなりません）
#     G-23  公開判定が定義どおりの結果を返したこと
#   採用できた馬の数は<b>数えて出すだけ</b>にし、判定しません。
#   弱い判定を「合格」と書かないためです。
#
# 置き換えるのは Command 1本だけです。
#   SummaryAiAnalysisCompensate.php  e05ccaa64eda0225d3b24a25a32edd60
# AiController.php は v83〜v85 と同じもの（2c6460626989c78bf542db65ef1fb3a8）
# なので送りません。
#
# 旗は動かしません。worker も起動しません。実AIへの通信は0回です。
# crontab は読み出しだけです。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 Command e05ccaa64eda0225d3b24a25a32edd60"

ssh "$SRV" 'mkdir -p ~/r122_v86/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v86/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v86/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v86_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v86_$STAMP/"
cp ~/r122_v86/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cd "$APP"

echo ""; echo "══ 置き換え ══"
md5sum app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Http/Controllers/Api/AiController.php
echo "  AiController は v83・v84 と同じはずです: 2c6460626989c78bf542db65ef1fb3a8"
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ 実凍結から一続き（G-01〜G-24）══"
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/38_実凍結から一続き.txt"

echo ""; echo "══ 期限の幅だけ抜き出し ══"
grep -E "G-0[0-9]|G-1[0-9]|G-2[0-4]|発走|情報上限|入力凍結|応答期限|公開期限|投票締切|決め打ち|元に戻|写し" \
  "$OUT/38_実凍結から一続き.txt" | tee "$OUT/38b_期限の幅.txt"

echo ""; echo "══ 壊れていないこと（受入試験だけ確認）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/19_受入試験_全文.txt" | grep -E "判定件数|合格 |不合格"

echo ""; echo "══ × が出た行（あれば）══"
grep -hE "^\s*×" "$OUT"/*.txt | sort -u | head -30 || echo "  （× はありません）"

tar czf ~/r122_v86_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v86_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v86_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v86_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v86_logs.tgz があります）"
