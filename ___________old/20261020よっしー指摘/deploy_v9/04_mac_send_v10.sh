#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   行順の直し（v10）をサーバへ送るだけ
# ───────────────────────────────────────────────
set -u
SRC="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
DEP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/20261020よっしー指摘/deploy_v9/05_server_v10.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
wc -l "$SRC"
md5 -q "$SRC"
echo "  期待：11342行 / d77eea518b36e801c16576bb81d070a5"
echo
scp "$SRC" "$HOST:/tmp/SummaryAiAnalysisCompensate.v10.php" || exit 1
scp "$DEP" "$HOST:/tmp/05_server_v10.sh"                    || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/05_server_v10.sh"
