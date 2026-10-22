#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】v10（CSVの行順をv8と同じ自然順に戻す）
#   ssh centos@49.212.166.123 のあと  bash /tmp/05_server_v10.sh
#   DBへの書き込みはありません
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
TGT=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/v10
mkdir -p "$OUT"

echo "########## 0. いまの版 ##########"
wc -l "$TGT"; md5sum "$TGT"
cp -p "$TGT" "$OUT/SummaryAiAnalysisCompensate.before_v10.php"
echo

echo "########## 1. v10 を入れる ##########"
cp /tmp/SummaryAiAnalysisCompensate.v10.php "$TGT" || exit 1
wc -l "$TGT"; md5sum "$TGT"
echo "  期待：11342行 / d77eea518b36e801c16576bb81d070a5"
echo

echo "########## 2. 構文検査 ##########"
php -l "$TGT" || { echo "!! 構文エラー。元に戻します"; cp -p "$OUT/SummaryAiAnalysisCompensate.before_v10.php" "$TGT"; exit 1; }
echo

cd "$APP" || exit 1

echo "########## 3. 再集計（v10）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_v10_20261019.txt"
echo

echo "########## 4. 開催が無い日を混ぜる（008③の再確認）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-20,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_v10_withemptyday_20261019.txt"
echo

echo "########## 5. CSVをv10の名前で退避 ##########"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check_v10_20261017_20261018.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds_v10_20261017_20261018.csv"
ls -l "$OUT"/*.csv
echo

echo "########## 6. v8 と v10 の差分（ここが本題）##########"
{
for p in mode_check mode_check_odds; do
  V8="$KEEP/${p}_v8_20261017_20261018.csv"
  [ "$p" = "mode_check" ] && V8="$KEEP/mode_check_v8_20261017_20261018.csv"
  V10="$OUT/${p}_v10_20261017_20261018.csv"
  [ "$p" = "mode_check" ] && V10="$OUT/mode_check_v10_20261017_20261018.csv"
  echo "=== $p ==="
  if [ -f "$V8" ] && [ -f "$V10" ]; then
    echo "  行数 v8=$(wc -l < "$V8")  v10=$(wc -l < "$V10")"
    echo "  行順も含めた差分行数: $(diff "$V8" "$V10" | grep -c '^[<>]')"
    echo "  並べ替えを無視した差分行数: $(diff <(sort "$V8") <(sort "$V10") | grep -c '^[<>]')"
    echo "  --- 差分の中身（全部）---"
    diff "$V8" "$V10"
  else
    echo "  !! 見つかりません"
  fi
  echo
done
} | tee "$OUT/diff_v8_v10_20261019.txt"
echo

echo "########## 7. LATEST_MD5.txt ##########"
cd "$OUT" || exit 1
{
  echo "# 馬眼力 v0.4.1-r12.1 最新成果物のハッシュ（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  echo "## 本番コード（v10・現行）"
  md5sum "$TGT"
  echo "  行数: $(wc -l < "$TGT")"
  echo
  echo "## v10の出力（$OUT）"
  md5sum mode_check_v10_20261019.txt \
         mode_check_v10_withemptyday_20261019.txt \
         mode_check_v10_20261017_20261018.csv \
         mode_check_odds_v10_20261017_20261018.csv \
         diff_v8_v10_20261019.txt 2>&1
  echo
  echo "## v9の証跡（そのまま残します）"
  (cd "$KEEP/v9" && md5sum cancel_counts_v9.tsv cancel_race_v9.tsv \
        cancel.sql cancel_race.sql modecheck_code_v9.txt 2>&1)
} > LATEST_MD5.txt
cat LATEST_MD5.txt
echo

echo "########## 8. コード抜粋（modecheck_code_v10.txt）##########"
{
  echo "# 抜粋元: $TGT"
  echo "# md5: $(md5sum "$TGT" | awk '{print $1}')"
  echo "# 行数: $(wc -l < "$TGT")"
  echo "# 抜粋範囲: 9650〜10765行（runModeCheck ～ atLeast）"
  echo "# 認証情報は含みません"
  echo "# ------------------------------------------------------------"
  awk 'NR>=9650 && NR<=10765 {printf "%6d  %s\n", NR, $0}' "$TGT"
} > modecheck_code_v10.txt
wc -l modecheck_code_v10.txt; md5sum modecheck_code_v10.txt
md5sum modecheck_code_v10.txt >> LATEST_MD5.txt
echo

echo "########## 9. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
echo "版: $(md5sum "$TGT" | awk '{print $1}') / $(wc -l < "$TGT")行"
grep -E "傾向を確定できた|mode判定不能|計算不能（入力不足）|計 [0-9]+ レース" "$OUT/mode_check_v10_20261019.txt"
echo "--- v8とv10の差分 ---"
grep -E "^=== |^  行数|^  行順も含めた差分行数|^  並べ替えを無視した差分行数" "$OUT/diff_v8_v10_20261019.txt"
echo "--- 差分の実物（レース別CSV）---"
sed -n '/^=== mode_check ===/,/^=== mode_check_odds ===/p' "$OUT/diff_v8_v10_20261019.txt" | grep '^[<>]' | head -10
echo "----8<---- ここまで ----8<----"
