#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   やること：更新したPHPとサーバ用スクリプトをサーバへ送るだけ
# ───────────────────────────────────────────────
set -u
SRC="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
DEP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/20261020よっしー指摘/deploy_v9/02_server_v9.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
wc -l "$SRC"
md5 -q "$SRC"
echo "  期待：11330行 / 05f348d701018e56627fe037dd02441f"
echo

scp "$SRC" "$HOST:/tmp/SummaryAiAnalysisCompensate.v9.php" || exit 1
scp "$DEP" "$HOST:/tmp/02_server_v9.sh"                    || exit 1

echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/02_server_v9.sh"
