#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】r12.2 対応版（v11）
#   ssh centos@49.212.166.123 のあと  bash /tmp/07_server_v11.sh
#   DBへの書き込みはありません。AI呼び出しもありません（費用0円）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
TGT=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/v11
mkdir -p "$OUT"

echo "########## 0. いまの版 ##########"
wc -l "$TGT"; md5sum "$TGT"
cp -p "$TGT" "$OUT/SummaryAiAnalysisCompensate.before_v11.php"
echo

echo "########## 1. v11 を入れる ##########"
cp /tmp/SummaryAiAnalysisCompensate.v11.php "$TGT" || exit 1
wc -l "$TGT"; md5sum "$TGT"
echo "  期待：11793行 / 242f5b9e97f5156448495203b3ad195e"
echo

echo "########## 2. 構文検査 ##########"
php -l "$TGT" || { echo "!! 構文エラー。元に戻します"; cp -p "$OUT/SummaryAiAnalysisCompensate.before_v11.php" "$TGT"; exit 1; }
echo

cd "$APP" || exit 1

echo "########## 3. r12.2 合成試験（先方の期待値との突き合わせ）##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation \
  2>&1 | tee "$OUT/r122_cases_20261020.txt"
echo

echo "########## 4. 72レースを r12.2 で再集計 ##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_v11_20261020.txt"
echo

echo "########## 5. CSVをv11の名前で退避 ##########"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check_v11_20261017_20261018.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds_v11_20261017_20261018.csv"
ls -l "$OUT"/*.csv
echo

echo "########## 6. v10 との差分（既存列が動いていないこと）##########"
{
  V10="$KEEP/v10/mode_check_v10_20261017_20261018.csv"
  V11="$OUT/mode_check_v11_20261017_20261018.csv"
  echo "=== レース別CSV：既存20列だけを取り出して比較 ==="
  if [ -f "$V10" ] && [ -f "$V11" ]; then
    cut -d, -f1-20 "$V10" > /tmp/_v10_20.csv
    cut -d, -f1-20 "$V11" > /tmp/_v11_20.csv
    echo "  既存20列の差分行数: $(diff /tmp/_v10_20.csv /tmp/_v11_20.csv | grep -c '^[<>]')"
    diff /tmp/_v10_20.csv /tmp/_v11_20.csv | head -10
    echo "  （0なら、r12.2の列を足しただけで既存の値は1つも動いていません）"
  else
    echo "  !! v10のCSVが見つかりません"
  fi
  echo
  echo "=== 各馬CSV：全体を比較 ==="
  O10="$KEEP/v10/mode_check_odds_v10_20261017_20261018.csv"
  O11="$OUT/mode_check_odds_v11_20261017_20261018.csv"
  if [ -f "$O10" ] && [ -f "$O11" ]; then
    echo "  差分行数: $(diff "$O10" "$O11" | grep -c '^[<>]')"
    diff "$O10" "$O11" | head -6
  fi
} | tee "$OUT/diff_v10_v11_20261020.txt"
echo

echo "########## 7. 暫定選出33レースの配分を抜き出す ##########"
{
  head -1 "$OUT/mode_check_v11_20261017_20261018.csv"
  grep PROVISIONAL "$OUT/mode_check_v11_20261017_20261018.csv"
} > "$OUT/provisional_33_v11.csv"
echo "  行数: $(wc -l < "$OUT/provisional_33_v11.csv")（見出し1行＋暫定レース）"
echo

echo "########## 8. コード抜粋と LATEST_MD5 ##########"
cd "$OUT" || exit 1
{
  echo "# 抜粋元: $TGT"
  echo "# md5: $(md5sum "$TGT" | awk '{print $1}')"
  echo "# 行数: $(wc -l < "$TGT")"
  echo "# 抜粋範囲: r12.2 の判定（@ANCHOR-20261020-R122 から signalNote の直前まで）"
  echo "# 認証情報は含みません"
  echo "# ------------------------------------------------------------"
  awk '/@ANCHOR-20261020-R122  r12\.2/{f=1} /@ANCHOR-20261018-MODECHECK  信号の説明/{f=0} f{printf "%6d  %s\n", NR, $0}' "$TGT"
} > r122_code_v11.txt
wc -l r122_code_v11.txt; md5sum r122_code_v11.txt
{
  echo "# 馬眼力 r12.2 最新成果物のハッシュ（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  echo "## 本番コード（v11・現行）"
  md5sum "$TGT"
  echo "  行数: $(wc -l < "$TGT")"
  echo
  echo "## v11の出力（$OUT）"
  md5sum r122_cases_20261020.txt mode_check_v11_20261020.txt \
         mode_check_v11_20261017_20261018.csv \
         mode_check_odds_v11_20261017_20261018.csv \
         provisional_33_v11.csv diff_v10_v11_20261020.txt r122_code_v11.txt 2>&1
} > LATEST_MD5.txt
cat LATEST_MD5.txt
echo

echo "########## 9. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
echo "版: $(md5sum "$TGT" | awk '{print $1}') / $(wc -l < "$TGT")行"
grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった" "$OUT/r122_cases_20261020.txt"
echo "--- 72レース ---"
grep -E "傾向を確定できた|mode判定不能|計算不能（入力不足）|確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/mode_check_v11_20261020.txt"
echo "--- v10との差分 ---"
grep -E "既存20列の差分行数|差分行数" "$OUT/diff_v10_v11_20261020.txt"
echo "--- 暫定33レースの配分（先頭8行）---"
head -9 "$OUT/provisional_33_v11.csv" | cut -d, -f2,3,21,22,26,27,28,29,30
echo "----8<---- ここまで ----8<----"
