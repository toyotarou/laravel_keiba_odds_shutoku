#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   r12.2 の実装（AiController.php ＋ 検証コマンド）をサーバへ送ります
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
CMD="$K/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
VAL="$K/20261020よっしー指摘/001/馬眼力_エンジニア送付資料_20261019/validation"
DEP="$K/20261020よっしー指摘/deploy_v12/09_server_r122.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
wc -l "$AI";  md5 -q "$AI"
echo "  期待：18096行 / 43c90bd16880c3cd6e407bdb7f0ca634"
wc -l "$CMD"; md5 -q "$CMD"
echo "  期待：11793行 / 242f5b9e97f5156448495203b3ad195e"
echo

scp "$AI"  "$HOST:/tmp/AiController.r122.php"                   || exit 1
scp "$CMD" "$HOST:/tmp/SummaryAiAnalysisCompensate.r122.php"    || exit 1
scp "$DEP" "$HOST:/tmp/09_server_r122.sh"                       || exit 1

ssh "$HOST" 'mkdir -p /tmp/r122_validation' || exit 1
scp "$VAL/mode_cases.json" "$VAL/acquisition_boundary_cases.json" \
    "$HOST:/tmp/r122_validation/" || exit 1

echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/09_server_r122.sh"
