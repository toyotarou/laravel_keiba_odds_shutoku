#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】E-T04 の期待値修正 ＋ AiController の切り出し（第1弾）
#   ssh centos@49.212.166.123 のあと  bash /tmp/11_server_refactor.sh
#   DBへの書き込みなし・AI呼び出しなし（費用0円）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/refactor1
mkdir -p "$OUT"

echo "########## 0. いまの版を控える ##########"
wc -l "$AI"; md5sum "$AI"
cp -p "$AI" "$OUT/AiController.before_refactor.php"
echo

echo "########## 1. 入れ替える ##########"
cp /tmp/AiController.refactor.php "$AI" || exit 1
wc -l "$AI"; md5sum "$AI"
echo "  期待：18193行 / 12299720302dda853e3b292175160720"
echo

echo "########## 2. 構文検査 ##########"
php -l "$AI" || { echo "!! 構文エラー。元に戻します"; cp -p "$OUT/AiController.before_refactor.php" "$AI"; exit 1; }
echo

cd "$APP" || exit 1

echo "########## 3. 本体の受入試験（330件）##########"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/v04_test_refactor.txt"
echo

echo "########## 4. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation \
  2>&1 | tee "$OUT/r122_cases_refactor.txt"
echo

echo "########## 5. 72レース再集計（切り出し前と同じになること）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_refactor.txt"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check_refactor.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds_refactor.csv"
echo

echo "########## 6. 切り出し前（r122）との差分 ##########"
{
  A="$KEEP/r122/mode_check_r122_20261017_20261018.csv"
  B="$OUT/mode_check_refactor.csv"
  [ -f "$A" ] && echo "レース別CSVの差分行数: $(diff "$A" "$B" | grep -c '^[<>]')"
  [ -f "$A" ] && diff "$A" "$B" | head -6
  C="$KEEP/r122/mode_check_odds_r122_20261017_20261018.csv"
  D="$OUT/mode_check_odds_refactor.csv"
  [ -f "$C" ] && echo "各馬CSVの差分行数: $(diff "$C" "$D" | grep -c '^[<>]')"
  E="$KEEP/r122/mode_check_r122.txt"
  [ -f "$E" ] && echo "実行結果テキストの差分行数: $(diff "$E" "$OUT/mode_check_refactor.txt" | grep -c '^[<>]')"
} | tee "$OUT/diff_r122_refactor.txt"
echo

echo "########## 7. クラスの行数 ##########"
awk '/^final class BaganrikiSignals/{s=NR} /^final class BaganrikiAllocation/{a=NR} END{
  printf "  AiController 本体    : %d行\n", s-1
  printf "  BaganrikiSignals     : %d行\n", a-s-1
  printf "  BaganrikiAllocation  : %d行\n", NR-a
}' "$AI"
echo

echo "########## 8. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
echo "AiController : $(md5sum "$AI" | awk '{print $1}') / $(wc -l < "$AI")行"
grep -E "判定件数|合格 |不合格" "$OUT/v04_test_refactor.txt" | head -4
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/v04_test_refactor.txt" | head -10
grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった" "$OUT/r122_cases_refactor.txt"
grep -E "確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/mode_check_refactor.txt"
echo "--- 切り出し前との差分 ---"
cat "$OUT/diff_r122_refactor.txt"
echo "----8<---- ここまで ----8<----"
