#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-PROMPT  Mac側：r12.2 の両AI本文をサーバへ送る
# ═══════════════════════════════════════════════════════════════════════════
# 使い方
#   bash 22_mac_send.sh
#
# 何をするか
#   ① このフォルダの code/ の2本と prompts/ の3本、資材の r122_material.tgz を
#      サーバのホーム（~/r122_prompt/）へ scp します。
#   ② サーバ側では何も実行しません（23_server.sh を手で動かしてください）。
#   ③ Mac のローカル Laravel にも同じ2本を置きます（上書きの前に控えを取ります）。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"

echo "── ① Mac側のローカル控え ──"
mkdir -p "$HERE/mac_backup_$STAMP"
for f in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php ; do
  if [ -f "$MACAPP/$f" ]; then
    cp "$MACAPP/$f" "$HERE/mac_backup_$STAMP/$(basename "$f")"
    echo "  控え: $(basename "$f")"
  fi
done

echo "── ② Mac側のローカルへ配置 ──"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
mkdir -p "$MACAPP/storage/app/baganriki/prompts/r12_2"
cp "$HERE"/prompts/*.txt "$MACAPP/storage/app/baganriki/prompts/r12_2/"
echo "  行数とMD5（Mac側）"
wc -l "$MACAPP/app/Http/Controllers/Api/AiController.php" \
      "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"

echo "── ③ サーバへ送る ──"
ssh "$SRV" 'mkdir -p ~/r122_prompt/code ~/r122_prompt/prompts'
scp "$HERE"/code/*.php        "$SRV:~/r122_prompt/code/"
scp "$HERE"/prompts/*.txt     "$SRV:~/r122_prompt/prompts/"
scp "$HERE"/r122_material.tgz "$SRV:~/r122_prompt/"
scp "$HERE"/23_server.sh      "$SRV:~/r122_prompt/"
echo ""
echo "  送り終わりました。次はサーバで次の1行を動かしてください。"
echo "    ssh $SRV 'bash ~/r122_prompt/23_server.sh'"
