#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   E-T04 の期待値修正＋AiController の切り出し（第1弾）
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
DEP="$K/20261020よっしー指摘/deploy_v13/11_server_refactor.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
wc -l "$AI"; md5 -q "$AI"
echo "  期待：18193行 / 12299720302dda853e3b292175160720"
echo
scp "$AI"  "$HOST:/tmp/AiController.refactor.php" || exit 1
scp "$DEP" "$HOST:/tmp/11_server_refactor.sh"     || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/11_server_refactor.sh"
