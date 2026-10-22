#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-PROMPT  Mac側：直した2本をサーバへ送る（v21）
# ═══════════════════════════════════════════════════════════════════════════
# v20 からの直し
#   ① Class "BaganrikiPrompt" not found … 同じファイルの中のクラスを
#      読ませる一手間（class_exists(AiController::class)）を入口に足しました。
#   ② 受入試験 R122-P01「129桁の整数は送らない」の不合格 … 桁数の数え方が
#      指数表記（1.0E+129）を展開していませんでした。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"

echo "── ① Mac側の控え ──"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"            "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
( cd "$HERE/mac_backup_$STAMP" && wc -l *.php )

echo "── ② Mac側へ配置 ──"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
mkdir -p "$MACAPP/storage/app/baganriki/prompts/r12_2"
cp "$HERE"/prompts/*.txt "$MACAPP/storage/app/baganriki/prompts/r12_2/"
wc -l "$MACAPP/app/Http/Controllers/Api/AiController.php" \
      "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 AiController : 21254行 / 46bb07d6faf1843dbfbbddafe412e5eb"
echo "  期待 Command      : 12928行 / ec06597c65e0dfb51f659895e8f5179a"

echo "── ③ サーバへ送る ──"
ssh "$SRV" 'mkdir -p ~/r122_prompt/code ~/r122_prompt/prompts'
scp -q "$HERE"/code/*.php        "$SRV:~/r122_prompt/code/"
scp -q "$HERE"/prompts/*.txt     "$SRV:~/r122_prompt/prompts/"
scp -q "$HERE"/r122_material.tgz "$SRV:~/r122_prompt/"
scp -q "$HERE"/25_server.sh      "$SRV:~/r122_prompt/"
echo ""
echo "  送り終わりました。次はこの1行です。"
echo "    ssh $SRV 'bash ~/r122_prompt/25_server.sh'"
