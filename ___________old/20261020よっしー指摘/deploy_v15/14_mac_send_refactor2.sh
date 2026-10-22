#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   リファクタリング第2弾（機械学習のかたまりを切り出し）
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
DEP="$K/20261020よっしー指摘/deploy_v15/15_server_refactor2.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
printf "  %6s 行  %s\n" "$(wc -l < "$AI" | tr -d ' ')" "$(md5 -q "$AI")"
echo "  期待：19589行 / 3b26bb02c9af65e9e5194c99341fbd8f"
echo
scp "$AI"  "$HOST:/tmp/AiController.refactor2.php" || exit 1
scp "$DEP" "$HOST:/tmp/15_server_refactor2.sh"     || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/15_server_refactor2.sh"
