#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】r12.2 の実装を入れて試験を通す
#   ssh centos@49.212.166.123 のあと  bash /tmp/09_server_r122.sh
#   DBへの書き込みなし・AI呼び出しなし（費用0円）
#   本番の選出には影響しません（BAGANRIKI_V04_ENABLED は既定 false のまま）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
CMD=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/r122
mkdir -p "$OUT"

echo "########## 0. いまの版を控える ##########"
wc -l "$AI";  md5sum "$AI"
wc -l "$CMD"; md5sum "$CMD"
cp -p "$AI"  "$OUT/AiController.before_r122.php"
cp -p "$CMD" "$OUT/SummaryAiAnalysisCompensate.before_r122.php"
echo

echo "########## 1. r12.2 版を入れる ##########"
cp /tmp/AiController.r122.php                "$AI"  || exit 1
cp /tmp/SummaryAiAnalysisCompensate.r122.php "$CMD" || exit 1
wc -l "$AI";  md5sum "$AI"
echo "  期待：18096行 / 43c90bd16880c3cd6e407bdb7f0ca634"
wc -l "$CMD"; md5sum "$CMD"
echo "  期待：11793行 / 242f5b9e97f5156448495203b3ad195e"
echo

echo "########## 2. 構文検査 ##########"
ok=1
php -l "$AI"  || ok=0
php -l "$CMD" || ok=0
if [ "$ok" -ne 1 ]; then
  echo "!! 構文エラー。元に戻します"
  cp -p "$OUT/AiController.before_r122.php"  "$AI"
  cp -p "$OUT/SummaryAiAnalysisCompensate.before_r122.php" "$CMD"
  exit 1
fi
echo

cd "$APP" || exit 1

echo "########## 3. 本体の受入試験（AiController の自己試験）##########"
echo "  付録E12件＋05章B24件＋r12.2 で足した R122-01〜07"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/v04_test_r122.txt"
echo

echo "########## 4. 先方の合成試験との突き合わせ ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation \
  2>&1 | tee "$OUT/r122_cases.txt"
echo

echo "########## 5. 72レースを r12.2 で再集計 ##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_r122.txt"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check_r122_20261017_20261018.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds_r122_20261017_20261018.csv"
echo

echo "########## 6. v10 と比べて既存の値が動いていないこと ##########"
{
  V10="$KEEP/v10/mode_check_v10_20261017_20261018.csv"
  V12="$OUT/mode_check_r122_20261017_20261018.csv"
  if [ -f "$V10" ]; then
    cut -d, -f1-20 "$V10" > /tmp/_a.csv
    cut -d, -f1-20 "$V12" > /tmp/_b.csv
    echo "既存20列の差分行数: $(diff /tmp/_a.csv /tmp/_b.csv | grep -c '^[<>]')"
    diff /tmp/_a.csv /tmp/_b.csv | head -10
  fi
  O10="$KEEP/v10/mode_check_odds_v10_20261017_20261018.csv"
  O12="$OUT/mode_check_odds_r122_20261017_20261018.csv"
  [ -f "$O10" ] && echo "各馬CSVの差分行数: $(diff "$O10" "$O12" | grep -c '^[<>]')"
} | tee "$OUT/diff_v10_r122.txt"
echo

echo "########## 7. 暫定33レースの配分 ##########"
{ head -1 "$OUT/mode_check_r122_20261017_20261018.csv"
  grep PROVISIONAL "$OUT/mode_check_r122_20261017_20261018.csv"; } > "$OUT/provisional_33.csv"
echo "  行数: $(wc -l < "$OUT/provisional_33.csv")"
echo

echo "########## 8. LATEST_MD5 ##########"
cd "$OUT" || exit 1
{
  echo "# 馬眼力 r12.2 実装（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  md5sum "$AI"; echo "  行数: $(wc -l < "$AI")"
  md5sum "$CMD"; echo "  行数: $(wc -l < "$CMD")"
  echo
  md5sum v04_test_r122.txt r122_cases.txt mode_check_r122.txt \
         mode_check_r122_20261017_20261018.csv \
         mode_check_odds_r122_20261017_20261018.csv \
         provisional_33.csv diff_v10_r122.txt 2>&1
} > LATEST_MD5.txt
cat LATEST_MD5.txt
echo

echo "########## 9. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
echo "AiController : $(md5sum "$AI"  | awk '{print $1}') / $(wc -l < "$AI")行"
echo "Command      : $(md5sum "$CMD" | awk '{print $1}') / $(wc -l < "$CMD")行"
echo "--- 本体の受入試験 ---"
grep -E "PASS|FAIL|合格|不合格|件" "$OUT/v04_test_r122.txt" | tail -12
echo "--- 先方の合成試験 ---"
grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった" "$OUT/r122_cases.txt"
echo "--- 72レース ---"
grep -E "傾向を確定できた|mode判定不能|計算不能（入力不足）|確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/mode_check_r122.txt"
echo "--- v10との差分 ---"
cat "$OUT/diff_v10_r122.txt"
echo "----8<---- ここまで ----8<----"
