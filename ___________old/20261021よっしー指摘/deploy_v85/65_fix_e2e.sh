#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v85 ＝ e2e試験の窓を直すだけ（Command 1本。AiController は触りません）
# ══════════════════════════════════════════════════════════════════════
# v84 の結果（2026-10-21 13:50）
#   受入試験 662・キュー 112・型保持 92・Job構成 72・公開 67・応答検証 76
#   本体直通 41・読取 29・固定入力 20・OPI 18・本番入力 9：すべて不合格0
#   e2e も、写し（G-00a〜c）と監査行（G-01・G-02）までは通りました。
#   落ちたのは G-03・G-04 の2件だけです。
#
# @ANCHOR-20261021-R122-E2EWINDOW  寄せ先が遠すぎました
#   発走を「いまから10分後」に寄せていたので、
#   情報上限（発走の360秒前＝いまから240秒後）が<b>まだ来ていません</b>。
#   凍結が TOO_EARLY で止まりました。<b>これは正しい動きです。</b>
#   試験の寄せ先が悪かっただけです。
#
#   凍結できて、かつ初回を送れる窓はここです。
#     情報上限が過ぎている   発走 − 360 ≦ いま  → 発走 ≦ いま + 360秒
#     応答期限まで余裕がある 発走 − 150 ≧ いま + 45秒 → 発走 ≧ いま + 195秒
#   あいだを採って <b>いまから300秒後</b>にします。
#     情報上限   いま − 60秒（過ぎています）
#     入力凍結   いま − 30秒（過ぎています）
#     応答期限   いま + 150秒（間に合います）
#     公開期限   いま + 180秒 ／ 投票締切 いま + 240秒
#   いちばん遅い6分前の取得も、入力凍結より前に収まります
#   （予定 いま−60秒、利用可能 いま−57.5秒 ≦ 入力凍結 いま−30秒）。
#   期限の幅は1つも決め打ちしていません。
#
# 置き換えるのは Command 1本だけです。
#   SummaryAiAnalysisCompensate.php  90f45357b6afa63c64116b9025d23fc7
# AiController.php は v83・v84 と同じもの（2c6460626989c78bf542db65ef1fb3a8）
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
echo "  期待 Command 90f45357b6afa63c64116b9025d23fc7"

ssh "$SRV" 'mkdir -p ~/r122_v85/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v85/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v85/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v85_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v85_$STAMP/"
cp ~/r122_v85/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
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

tar czf ~/r122_v85_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v85_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v85_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v85_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v85_logs.tgz があります）"
