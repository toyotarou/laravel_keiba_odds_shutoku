#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   さっき出た2つの不具合を直した版を送ります
#     ①受入試験4件の不合格（手で組んだpairsで判定できなかった）
#     ②コマンドからクラスが見つからない（同じファイル内クラスの読み込み）
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
CMD="$K/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
DEP="$K/20261020よっしー指摘/deploy_v17/19_server_fix.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
printf "  AiController  %6s 行  %s\n" "$(wc -l < "$AI"  | tr -d ' ')" "$(md5 -q "$AI")"
echo "    期待：19814 / 6f6c7ad4fb029f79acea312262747d31"
printf "  Command       %6s 行  %s\n" "$(wc -l < "$CMD" | tr -d ' ')" "$(md5 -q "$CMD")"
echo "    期待：12171 / 14d8a802714f50f6465e58065489c737"
echo
scp "$AI"  "$HOST:/tmp/AiController.fix.php"               || exit 1
scp "$CMD" "$HOST:/tmp/SummaryAiAnalysisCompensate.fix.php" || exit 1
scp "$DEP" "$HOST:/tmp/19_server_fix.sh"                    || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/19_server_fix.sh"
