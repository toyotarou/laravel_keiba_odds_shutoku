#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   10/20 確認結果 R122-02 への対応（丸めをやめて元値で比較）
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
CMD="$K/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
DEP="$K/20261020よっしー指摘/deploy_v16/17_server_exact.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
printf "  AiController  %6s 行  %s\n" "$(wc -l < "$AI"  | tr -d ' ')" "$(md5 -q "$AI")"
echo "    期待：19779 / 2baae23fb130dbca9da1834575a0bde3"
printf "  Command       %6s 行  %s\n" "$(wc -l < "$CMD" | tr -d ' ')" "$(md5 -q "$CMD")"
echo "    期待：12160 / 3b5f72d0ac5bf094dd9c144c504a7ad7"
echo
scp "$AI"  "$HOST:/tmp/AiController.exact.php"               || exit 1
scp "$CMD" "$HOST:/tmp/SummaryAiAnalysisCompensate.exact.php" || exit 1
scp "$DEP" "$HOST:/tmp/17_server_exact.sh"                    || exit 1
echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/17_server_exact.sh"
