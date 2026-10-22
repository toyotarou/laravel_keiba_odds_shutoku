#!/bin/bash
# 【Macのターミナルで実行】順位点の厳密化＋境界試験の追加
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
DEP="$K/20261020よっしー指摘/deploy_v18/21_server.sh"
HOST="centos@49.212.166.123"
echo "== 送る前にMac側を確認 =="
printf "  AiController  %6s 行  %s\n" "$(wc -l < "$AI" | tr -d ' ')" "$(md5 -q "$AI")"
echo "    期待：19952 / 04cdc3826b7a781391e2ec556eb3b78c"
echo
scp "$AI"  "$HOST:/tmp/AiController.rank.php" || exit 1
scp "$DEP" "$HOST:/tmp/21_server.sh"          || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/21_server.sh"
