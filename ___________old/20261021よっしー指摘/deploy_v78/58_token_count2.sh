#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# トークン数の実測（課金ゼロ）  両モデル・内訳・長短単価つき
# ══════════════════════════════════════════════════════════════════════
# なる様のご指摘を受けて、次を直しました。
#   ・Haiku 5.5 の $0.10／$0.50 は<b>入力10万トークンまで</b>。
#     10万を超えるとリクエスト全体が $0.50／$2.50 になります。
#   ・Haiku 5.5 はトークナイザが違うので、同じ本文でも数が変わります。
#     旧モデルの数値は使えません。<b>モデルを指定して数え直します。</b>
#   ・16頭と17頭で量が急増する理由を出すため、本文の内訳
#     （source_registry の件数・過去走の出典件数・大きい順の区分）
#     を並べます。
#   ・初回のみ／訂正込みを分け、長文側の単価を自動で当てます。
#
# count_tokens はモデルを指定して呼べます。料金はかかりません。
# 本文の生成（/v1/messages）は1回も呼びません。
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
echo "  期待 Command      065ad1871814ae4d66060d659f0109c6"

ssh "$SRV" 'mkdir -p ~/r122_v78/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v78/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v78/result_$STAMP; mkdir -p "$OUT/bodies"
mkdir -p "$BK/before_v78_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v78_$STAMP/"
cp ~/r122_v78/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cd "$APP"
echo ""; echo "══ 置き換え ══"
md5sum app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ トークン数の実測（課金ゼロ。本文の生成は0回）══"
php artisan keiba:ai-analysis-compensate \
  --r122-token-count="$HOME/r122_v23/mat" \
  --r122-token-out="$OUT/bodies" \
  --date=2026-10-18 2>&1 | tee "$OUT/31_トークン実測_両モデル.txt"

echo ""; echo "══ 書き出した本文 ══"
ls -la "$OUT/bodies" | head -20
du -sh "$OUT/bodies"

tar czf ~/r122_v78_tokens.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "まとめました: ~/r122_v78_tokens.tgz"
REMOTE

scp -q "$SRV:~/r122_v78_tokens.tgz" "$HERE/" \
  && echo "" && echo "取ってきました: $HERE/r122_v78_tokens.tgz" \
  || echo "取得に失敗しました（サーバ側には ~/r122_v78_tokens.tgz があります）"
