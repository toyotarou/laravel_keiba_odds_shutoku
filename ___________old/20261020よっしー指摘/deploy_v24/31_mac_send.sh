#!/bin/bash
# @ANCHOR-20261020-R122-COVERAGE  追加材料3区分を source_registry へ載せる
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php" "$MACAPP/app/Http/Controllers/Api/AiController.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php"
echo "  期待 b3a1ffa5ca6a387a6f51c6ef7ac5f16c"
ssh "$SRV" 'mkdir -p ~/r122_v24/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v24/code/"
scp -q "$HERE"/32_server.sh          "$SRV:~/r122_v24/"
echo ""
echo "  次はこの1行です。"
echo "    ssh $SRV 'bash ~/r122_v24/32_server.sh'"
