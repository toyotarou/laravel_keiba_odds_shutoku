#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-AUDIT  Mac側：取得監査の記録（お願い②A案）を送る
# ═══════════════════════════════════════════════════════════════════════════
# 入れるもの
#   ImportKeibaOdds.php              … オッズ取得のたびに監査を1行残す
#   SummaryAiAnalysisCompensate.php  … --r122-audit-check を追加
#   AiController.php                 … v21から変更なし（照合用に同梱）
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"

echo "── ① Mac側の控え ──"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Console/Commands/ImportKeibaOdds.php"             "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
( cd "$HERE/mac_backup_$STAMP" && wc -l *.php )

echo "── ② Mac側へ配置 ──"
cp "$HERE/code/ImportKeibaOdds.php"             "$MACAPP/app/Console/Commands/ImportKeibaOdds.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
md5 "$MACAPP/app/Console/Commands/ImportKeibaOdds.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
    "$MACAPP/app/Http/Controllers/Api/AiController.php"
echo "  期待 ImportKeibaOdds : 533行  / a863f54c06c90dce8a39080282a468c7"
echo "  期待 Command         : 13111行 / 2d3a5b11d1eae89cc6e196daee8d6d1d"
echo "  期待 AiController    : 21254行 / 46bb07d6faf1843dbfbbddafe412e5eb"

echo "── ③ サーバへ送る ──"
ssh "$SRV" 'mkdir -p ~/r122_audit/code'
scp -q "$HERE"/code/*.php   "$SRV:~/r122_audit/code/"
scp -q "$HERE"/27_server.sh "$SRV:~/r122_audit/"
echo ""
echo "  送り終わりました。次はこの1行です。"
echo "    ssh $SRV 'bash ~/r122_audit/27_server.sh'"
