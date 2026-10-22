#!/bin/bash
# 【サーバで実行】順位点の厳密化＋境界試験の追加、そして提出用の実行結果を作る
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
CMD=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/teishutsu_20261020
mkdir -p "$OUT"

echo "########## 0. いまの版 ##########"
printf "  %7d 行  %s  AiController\n" "$(wc -l < "$AI")" "$(md5sum "$AI"|awk '{print $1}')"
cp -p "$AI" "$OUT/AiController.before.php"
echo

echo "########## 1. 入れ替えて構文検査 ##########"
cp /tmp/AiController.rank.php "$AI" || exit 1
printf "  %7d 行  %s\n" "$(wc -l < "$AI")" "$(md5sum "$AI"|awk '{print $1}')"
echo "  期待： 19952 / 04cdc3826b7a781391e2ec556eb3b78c"
php -l "$AI" || { echo "!! 構文エラー。戻します"; cp -p "$OUT/AiController.before.php" "$AI"; exit 1; }
php artisan config:clear >/dev/null 2>&1
echo

cd "$APP" || exit 1

echo "########## 2. 受入試験（提出用の全文を保存）##########"
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/01_受入試験_全項目.txt" 2>&1 || true
php artisan keiba:ai-analysis-compensate --v04-test       > "$OUT/02_受入試験_要約.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/02_受入試験_要約.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/02_受入試験_要約.txt" | head -12
echo

echo "########## 3. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation > "$OUT/03_合成試験.txt" 2>&1
grep -E "mode_cases|acquisition_boundary|すべて先方|一致しなかった" "$OUT/03_合成試験.txt"
echo

echo "########## 4. 本番側と検証側の突き合わせ ##########"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 > "$OUT/04_72レース突き合わせ.txt" 2>&1
sed -n '/7-3\./,/8\./p' "$OUT/04_72レース突き合わせ.txt" | head -12
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/05_レース別.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/06_各馬.csv"
echo

echo "########## 5. 既存データの指紋（元値が動いていないことの基準）##########"
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
cat > "$OUT/07_元値の指紋.sql" <<'SQL'
-- 元値の列だけを id 昇順で連結し、その md5 を取ります。
-- 行数ではなく中身が動いていないことの基準になります。
SELECT 'odds' t, COUNT(*) rows_n,
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,minutes_before_start,odds,fuku_min,fuku_max) ORDER BY id SEPARATOR ';')) fp
 FROM t_horse_odds_finder_odds
UNION ALL SELECT 'horses', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,waku,num,name) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_horses
UNION ALL SELECT 'races', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,start_time,num_horses) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_races
UNION ALL SELECT 'race_results', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,result) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_race_results;
SQL
mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/07_元値の指紋.sql" 2>&1 | tee "$OUT/08_元値の指紋_結果.txt"
echo

echo "########## 6. 版とハッシュ ##########"
{
  echo "# 提出物の版とハッシュ（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  echo "## 本番コード"
  md5sum "$AI" "$CMD" $APP/routes/api.php
  echo "  AiController 行数: $(wc -l < "$AI")"
  echo "  Command      行数: $(wc -l < "$CMD")"
  echo "  routes/api   行数: $(wc -l < $APP/routes/api.php)"
  echo
  echo "## 先方からお預かりした資材（変更していません）"
  md5sum /tmp/r122_validation/*.json 2>/dev/null
  echo
  echo "## 実行結果"
  (cd "$OUT" && md5sum 0*.txt 0*.csv 0*.sql 2>/dev/null)
} > "$OUT/09_版とハッシュ.txt"
cat "$OUT/09_版とハッシュ.txt"
echo

echo "########## 7. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
printf "AiController : %s / %s行\n" "$(md5sum "$AI"|awk '{print $1}')" "$(wc -l < "$AI")"
grep -E "判定件数|合格 |不合格" "$OUT/02_受入試験_要約.txt" | head -3
grep -E "R122-S0" "$OUT/02_受入試験_要約.txt"
grep -E "mode_cases|acquisition_boundary|すべて先方" "$OUT/03_合成試験.txt"
sed -n '/7-3\./,/8\./p' "$OUT/04_72レース突き合わせ.txt" | grep -E "突き合わせたレース|食い違ったレース|全レースで一致"
echo "--- 元値の指紋 ---"
cat "$OUT/08_元値の指紋_結果.txt"
echo "--- 提出フォルダ ---"
ls "$OUT"
echo "----8<---- ここまで ----8<----"
