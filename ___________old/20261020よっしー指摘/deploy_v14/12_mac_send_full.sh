#!/bin/bash
# ───────────────────────────────────────────────
# 【Macのターミナルで実行】SSHする前です
#   r12.2 の実装一式（判定・配分・時刻・取得・凍結・順位・応答検証・表示
#   ・新規テーブル・読取API）をサーバへ送ります
# ───────────────────────────────────────────────
set -u
K="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA"
AI="$K/_____laravel/app/Http/Controllers/Api/AiController.php"
CMD="$K/_____laravel/app/Console/Commands/SummaryAiAnalysisCompensate.php"
RT="$K/_____laravel/routes/api.php"
DEP="$K/20261020よっしー指摘/deploy_v14/13_server_full.sh"
HOST="centos@49.212.166.123"

echo "== 送る前にMac側を確認 =="
printf "  %-34s %6s 行  %s\n" "AiController.php"   "$(wc -l < "$AI"  | tr -d ' ')" "$(md5 -q "$AI")"
echo "    期待：19491 / a7d6b015384d90dd5426f9099a88ceb1"
printf "  %-34s %6s 行  %s\n" "SummaryAiAnalysisCompensate.php" "$(wc -l < "$CMD" | tr -d ' ')" "$(md5 -q "$CMD")"
echo "    期待：12059 / 5cce3c2d4645559f6c5d5289f5c6a7da"
printf "  %-34s %6s 行  %s\n" "routes/api.php"     "$(wc -l < "$RT"  | tr -d ' ')" "$(md5 -q "$RT")"
echo "    期待：  193 / d6d7437ba60063fd256b64bc8b35aa09"
echo

scp "$AI"  "$HOST:/tmp/AiController.full.php"                || exit 1
scp "$CMD" "$HOST:/tmp/SummaryAiAnalysisCompensate.full.php"  || exit 1
scp "$RT"  "$HOST:/tmp/api.full.php"                          || exit 1
scp "$DEP" "$HOST:/tmp/13_server_full.sh"                     || exit 1

echo
echo "== 送りました。つぎは SSH して実行してください =="
echo "   ssh $HOST"
echo "   bash /tmp/13_server_full.sh"
