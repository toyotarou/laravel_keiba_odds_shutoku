#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# Mac側：10月20日004のご指摘ぶんを送る（v23）
# ═══════════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
for f in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Console/Commands/ImportKeibaOdds.php ; do
  cp "$MACAPP/$f" "$HERE/mac_backup_$STAMP/" 2>/dev/null || true
done
echo "── Mac側へ配置 ──"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$HERE/code/ImportKeibaOdds.php"             "$MACAPP/app/Console/Commands/ImportKeibaOdds.php"
mkdir -p "$MACAPP/storage/app/baganriki/prompts/r12_2"
cp "$HERE"/prompts/*.txt "$MACAPP/storage/app/baganriki/prompts/r12_2/"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
    "$MACAPP/app/Console/Commands/ImportKeibaOdds.php"
echo "  期待 AiController    : 2c4afa402796c8329e7650ce51cdc7da"
echo "  期待 Command         : 558440371e29bf63e6c440d0a732c637"
echo "  期待 ImportKeibaOdds : a863f54c06c90dce8a39080282a468c7"
echo "── サーバへ送る ──"
ssh "$SRV" 'mkdir -p ~/r122_v23/code ~/r122_v23/docs ~/r122_v23/prompts'
scp -q "$HERE"/code/*.php        "$SRV:~/r122_v23/code/"
scp -q "$HERE"/docs/*.md         "$SRV:~/r122_v23/docs/"
scp -q "$HERE"/prompts/*.txt     "$SRV:~/r122_v23/prompts/"
scp -q "$HERE"/r122_material.tgz "$SRV:~/r122_v23/"
scp -q "$HERE"/29_server.sh      "$SRV:~/r122_v23/"
echo ""
echo "  送り終わりました。次はこの1行です。"
echo "    ssh $SRV 'bash ~/r122_v23/29_server.sh'"
