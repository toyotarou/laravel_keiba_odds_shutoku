#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   r12.2 対応版（v11）と、先方の validation 一式をサーバへ送ります
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
SRC="$K/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
VAL="$K/20261020よっしー指摘/001/馬眼力_エンジニア送付資料_20261019/validation"
DEP="$K/20261020よっしー指摘/deploy_v11/07_server_v11.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
wc -l "$SRC"
md5 -q "$SRC"
echo "  期待：11793行 / 242f5b9e97f5156448495203b3ad195e"
echo

scp "$SRC" "$HOST:/tmp/SummaryAiAnalysisCompensate.v11.php" || exit 1
scp "$DEP" "$HOST:/tmp/07_server_v11.sh"                    || exit 1

echo
echo "== 先方の validation 一式を送ります =="
ssh "$HOST" 'mkdir -p /tmp/r122_validation' || exit 1
scp "$VAL/mode_cases.json" "$VAL/acquisition_boundary_cases.json" \
    "$VAL/normal_cases.json" "$VAL/invalid_cases.json" \
    "$VAL/verification_results.json" "$HOST:/tmp/r122_validation/" || exit 1

echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/07_server_v11.sh"
