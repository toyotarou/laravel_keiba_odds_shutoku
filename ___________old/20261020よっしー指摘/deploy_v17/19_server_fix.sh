#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】2つの不具合の修正
#   ssh centos@49.212.166.123 のあと  bash /tmp/19_server_fix.sh
#   DBへの書き込みなし・AI呼び出しなし（費用0円）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
CMD=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/exact_fix
mkdir -p "$OUT"

echo "########## 0. いまの版 ##########"
for f in "$AI" "$CMD"; do printf "  %7d 行  %s  %s\n" "$(wc -l < "$f")" "$(md5sum "$f"|awk '{print $1}')" "$(basename "$f")"; done
cp -p "$AI" "$OUT/AiController.before.php"; cp -p "$CMD" "$OUT/Command.before.php"
echo

echo "########## 1. 入れ替える ##########"
cp /tmp/AiController.fix.php               "$AI"  || exit 1
cp /tmp/SummaryAiAnalysisCompensate.fix.php "$CMD" || exit 1
for f in "$AI" "$CMD"; do printf "  %7d 行  %s  %s\n" "$(wc -l < "$f")" "$(md5sum "$f"|awk '{print $1}')" "$(basename "$f")"; done
echo "  期待： 19814 / 6f6c7ad4fb029f79acea312262747d31"
echo "         12171 / 14d8a802714f50f6465e58065489c737"
echo

echo "########## 2. 構文検査 ##########"
ok=1; for f in "$AI" "$CMD"; do php -l "$f" || ok=0; done
if [ "$ok" -ne 1 ]; then echo "!! 構文エラー。元に戻します";
  cp -p "$OUT/AiController.before.php" "$AI"; cp -p "$OUT/Command.before.php" "$CMD"; exit 1; fi
php artisan config:clear >/dev/null 2>&1
echo

cd "$APP" || exit 1

echo "########## 3. 受入試験（443件・全部合格になるはず）##########"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/v04_test.txt" | grep -E "判定件数|合格 |不合格"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/v04_test.txt" | head -14
echo

echo "########## 4. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation 2>&1 | tee "$OUT/r122_cases.txt" | grep -E "mode_cases|acquisition_boundary|すべて先方|一致しなかった"
echo

echo "########## 5. 本番側と検証側の突き合わせ（R122-02 の本題）##########"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 > "$OUT/exact_check.txt" 2>&1
sed -n '/7-3\./,/8\./p' "$OUT/exact_check.txt" | head -14
echo

echo "########## 6. 72レースの集計が変わっていないか ##########"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds.csv"
{
  A="$KEEP/refactor2/mode_check.csv"
  [ -f "$A" ] && echo "レース別CSVの差分行数: $(diff "$A" "$OUT/mode_check.csv" | grep -c '^[<>]')"
  B="$KEEP/refactor2/mode_check_odds.csv"
  [ -f "$B" ] && echo "各馬CSVの差分行数: $(diff "$B" "$OUT/mode_check_odds.csv" | grep -c '^[<>]')"
} | tee "$OUT/diff_prev.txt"
grep -E "確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/exact_check.txt"
echo

echo "########## 7. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
printf "AiController : %s / %s行\n" "$(md5sum "$AI"|awk '{print $1}')" "$(wc -l < "$AI")"
printf "Command      : %s / %s行\n" "$(md5sum "$CMD"|awk '{print $1}')" "$(wc -l < "$CMD")"
grep -E "判定件数|合格 |不合格" "$OUT/v04_test.txt" | head -3
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/v04_test.txt" | head -10
grep -E "mode_cases|acquisition_boundary|すべて先方|一致しなかった" "$OUT/r122_cases.txt"
echo "--- 本番側との突き合わせ ---"
sed -n '/7-3\./,/8\./p' "$OUT/exact_check.txt" | grep -E "突き合わせたレース|食い違ったレース|全レースで一致|本番側で例外"
echo "--- 72レース ---"
grep -E "確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/exact_check.txt"
echo "--- 前回との差分 ---"
cat "$OUT/diff_prev.txt"
echo "----8<---- ここまで ----8<----"
