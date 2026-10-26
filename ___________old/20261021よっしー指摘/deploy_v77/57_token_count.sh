#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# トークン数の実測（課金ゼロ）＋ モデルの上限に収まるかの確認
# ══════════════════════════════════════════════════════════════════════
# 【v76 の実測で、24日を止めるレベルの問題が出ました】
#   claude-haiku-4-5 のコンテキストウィンドウは 200,000 トークンです。
#   ところが実測は
#     18頭  257,696 トークン  → 上限を 57,696 超えています
#     17頭  234,891 トークン  → 上限を 34,891 超えています
#     16頭   69,803 トークン  → 収まります
#   <b>17頭・18頭のレースは、いまの入力では物理的に送れません。</b>
#   なるは「18頭立てがあれば含めてください」と書いています。
#   このままだと24日の検証でそこが落ちます。
#
#   今回は、過去走を何走まで減らせば収まるかを測ります（①-3）。
#   これも count_tokens だけなので料金はかかりません。
# ══════════════════════════════════════════════════════════════════════
# 【お金はかかりません】
#   使うのは Anthropic の count_tokens だけです。
#   本文の生成（/v1/messages）は<b>1回も呼びません</b>。
#   count_tokens は出力を作らないので料金が発生しません
#   （1分あたりのリクエスト数の制限だけがあります）。
#
# 何をするか
#   ① 10月18日の代表レース3本（頭数の違うもの。18頭を含む）について、
#      本番と同じ経路で INPUT_JSON を作り、1st・2nd それぞれの
#      入力トークン数を数えます
#   ② 配布された正常出力14本を数えます（出力トークンの目安）
#   ③ 訂正の入力（固定入力＋初回応答＋依頼文）の合計を出します
#   ④ 12レース・24回時の金額を出します
#   ⑤ 本文をファイルへ書き出します
#      （DeepSeek 側は公開トークナイザで別に数えます。これも課金ゼロです）
#
# DBへの書き込みは0件です。読むだけです。
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
echo "  期待 Command      3b58300c29e2c3f074e5b656860e342a"

ssh "$SRV" 'mkdir -p ~/r122_v77/code'
scp -q "$HERE"/code/SummaryAiAnalysisCompensate.php "$SRV:~/r122_v77/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v77/result_$STAMP; mkdir -p "$OUT/bodies"
mkdir -p "$BK/before_v77_$STAMP"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v77_$STAMP/"
cp ~/r122_v77/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cd "$APP"
echo ""; echo "══ 置き換え ══"
md5sum app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ トークン数の実測（課金ゼロ。本文の生成は0回）══"
php artisan keiba:ai-analysis-compensate \
  --r122-token-count="$HOME/r122_v23/mat" \
  --r122-token-out="$OUT/bodies" \
  --date=2026-10-18 2>&1 | tee "$OUT/30_トークン実測.txt"

echo ""; echo "══ 書き出した本文 ══"
ls -la "$OUT/bodies" | head -20
du -sh "$OUT/bodies"

tar czf ~/r122_v77_tokens.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "まとめました: ~/r122_v77_tokens.tgz"
REMOTE

scp -q "$SRV:~/r122_v77_tokens.tgz" "$HERE/" \
  && echo "" && echo "取ってきました: $HERE/r122_v77_tokens.tgz" \
  || echo "取得に失敗しました（サーバ側には ~/r122_v77_tokens.tgz があります）"
