#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】リファクタリング第2弾
#   ssh centos@49.212.166.123 のあと  bash /tmp/15_server_refactor2.sh
#   中身は1行も変えていません。置き場所を変えただけです。
#   DBへの書き込みなし・AI呼び出しなし（費用0円）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/refactor2
mkdir -p "$OUT"

echo "########## 0. いまの版 ##########"
printf "  %7d 行  %s\n" "$(wc -l < "$AI")" "$(md5sum "$AI"|awk '{print $1}')"
cp -p "$AI" "$OUT/AiController.before.php"
echo

echo "########## 1. 入れ替える ##########"
cp /tmp/AiController.refactor2.php "$AI" || exit 1
printf "  %7d 行  %s\n" "$(wc -l < "$AI")" "$(md5sum "$AI"|awk '{print $1}')"
echo "  期待：19589 / 3b26bb02c9af65e9e5194c99341fbd8f"
echo

echo "########## 2. 構文検査 ##########"
php -l "$AI" || { echo "!! 構文エラー。元に戻します"; cp -p "$OUT/AiController.before.php" "$AI"; exit 1; }
php artisan route:clear >/dev/null 2>&1
php artisan config:clear >/dev/null 2>&1
echo

cd "$APP" || exit 1

echo "########## 3. クラスの行数 ##########"
# awk の三項演算子の中で split() を呼ぶと一部の awk で落ちるので、素直に書き直しました
grep -n '^final class Baganriki' "$AI" | sed 's/:final class / /' > /tmp/_cls.txt
TOTAL=$(wc -l < "$AI")
FIRST=$(head -1 /tmp/_cls.txt | awk '{print $1}')
echo "  AiController 本体      $((FIRST-1))行"
awk -v total="$TOTAL" '{l[NR]=$1; n[NR]=$2} END{
  for(i=1;i<=NR;i++){ e=(i<NR)? l[i+1]-1 : total; printf "  %-22s %5d行\n", n[i], e-l[i]+1 }
}' /tmp/_cls.txt
echo

echo "########## 4. 本体の受入試験（426件）##########"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/v04_test.txt" | grep -E "判定件数|合格|不合格"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/v04_test.txt" | head -10
echo

echo "########## 5. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation 2>&1 | tee "$OUT/r122_cases.txt" | grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった"
echo

echo "########## 6. 機械学習の経路が生きているか（読み取りだけ）##########"
php artisan keiba:ai-analysis-compensate --ml-status 2>&1 | tee "$OUT/ml_status.txt" | tail -20
echo

echo "########## 7. r12.2 の状態 ##########"
php artisan keiba:ai-analysis-compensate --r122-status 2>&1 | tee "$OUT/r122_status.txt" | sed -n '1,20p'
echo

echo "########## 8. 72レース再集計（前回と同じになること）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 > "$OUT/mode_check.txt" 2>&1
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds.csv"
{
  A="$KEEP/r122_full/mode_check.csv"
  [ -f "$A" ] && echo "レース別CSVの差分行数: $(diff "$A" "$OUT/mode_check.csv" | grep -c '^[<>]')"
  B="$KEEP/r122_full/mode_check_odds.csv"
  [ -f "$B" ] && echo "各馬CSVの差分行数: $(diff "$B" "$OUT/mode_check_odds.csv" | grep -c '^[<>]')"
  C="$KEEP/r122_full/mode_check.txt"
  [ -f "$C" ] && echo "実行結果テキストの差分行数: $(diff "$C" "$OUT/mode_check.txt" | grep -c '^[<>]')"
} | tee "$OUT/diff_prev.txt"
echo

echo "########## 9. 読取APIが生きているか ##########"
php artisan route:list 2>/dev/null | grep -c "baganriki/r12-2" | xargs -I{} echo "  ルート {} 件"
echo

echo "########## 10. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
printf "AiController : %s / %s行\n" "$(md5sum "$AI"|awk '{print $1}')" "$(wc -l < "$AI")"
awk '/^final class Baganriki/{ if(!s)s=NR } END{ printf "本体 %d行 / 切り出し %d行\n", s-1, NR-s+1 }' "$AI"
grep -E "判定件数|合格 |不合格" "$OUT/v04_test.txt" | head -3
grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった" "$OUT/r122_cases.txt"
echo "--- 切り出し前との差分 ---"
cat "$OUT/diff_prev.txt"
echo "--- 読取API ---"
php artisan route:list 2>/dev/null | grep -c "baganriki/r12-2" | xargs -I{} echo "ルート {} 件"
echo "----8<---- ここまで ----8<----"
