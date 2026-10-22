#!/bin/bash
# 【Macのターミナルで実行】表示用の点の型を直した版 ＋ 提出物の組み立て
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
DEP="$K/20261020よっしー指摘/deploy_v19/23_server.sh"
HOST="centos@49.212.166.123"
echo "== 送る前にMac側を確認 =="
printf "  AiController  %6s 行  %s\n" "$(wc -l < "$AI" | tr -d ' ')" "$(md5 -q "$AI")"
echo "    期待：19953 / 2ae4f4427d4e5197b2e1eafca5e98fc2"
echo
scp "$AI"  "$HOST:/tmp/AiController.v19.php" || exit 1
scp "$DEP" "$HOST:/tmp/23_server.sh"         || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/23_server.sh"
