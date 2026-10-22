#!/bin/bash
# 【サーバで実行】提出物の実行結果を作り直し、持ち帰れるようにまとめます
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
CMD=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/teishutsu_20261020
mkdir -p "$OUT/code"

echo "########## 1. 入れ替えて構文検査 ##########"
cp -p "$AI" "$OUT/AiController.before_v19.php"
cp /tmp/AiController.v19.php "$AI" || exit 1
printf "  %7d 行  %s\n" "$(wc -l < "$AI")" "$(md5sum "$AI"|awk '{print $1}')"
echo "  期待： 19953 / 2ae4f4427d4e5197b2e1eafca5e98fc2"
php -l "$AI" || { echo "!! 構文エラー。戻します"; cp -p "$OUT/AiController.before_v19.php" "$AI"; exit 1; }
php artisan config:clear >/dev/null 2>&1
echo

cd "$APP" || exit 1

echo "########## 2. 受入試験 ##########"
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/01_受入試験_全項目.txt" 2>&1 || true
php artisan keiba:ai-analysis-compensate --v04-test       > "$OUT/02_受入試験_要約.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/02_受入試験_要約.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/02_受入試験_要約.txt" | head -14
echo

echo "########## 3. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation > "$OUT/03_合成試験.txt" 2>&1
grep -E "mode_cases|acquisition_boundary|すべて先方|一致しなかった" "$OUT/03_合成試験.txt"
echo

echo "########## 4. 72レース突き合わせ ##########"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 > "$OUT/04_72レース突き合わせ.txt" 2>&1
sed -n '/7-3\./,/8\./p' "$OUT/04_72レース突き合わせ.txt" | head -12
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/05_レース別.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/06_各馬.csv"
echo

echo "########## 5. 元値の指紋 ##########"
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/07_元値の指紋.sql" 2>&1 | tee "$OUT/08_元値の指紋_結果.txt"
echo

echo "########## 6. コードを提出フォルダへ ##########"
cp -p "$AI"  "$OUT/code/AiController.php"
cp -p "$CMD" "$OUT/code/SummaryAiAnalysisCompensate.php"
cp -p $APP/routes/api.php "$OUT/code/routes_api.php"
ls -l "$OUT/code"
echo

echo "########## 7. 版とハッシュ ##########"
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

echo "########## 8. 1つにまとめる ##########"
cd "$KEEP" || exit 1
rm -f teishutsu_20261020.tar.gz 2>/dev/null
tar czf teishutsu_20261020.tar.gz -C "$KEEP" \
  --exclude='AiController.before*' teishutsu_20261020
ls -l teishutsu_20261020.tar.gz
md5sum teishutsu_20261020.tar.gz
echo
echo "  Macへ持ち帰るには、Macの別ウィンドウでこれを実行してください："
echo "  scp centos@49.212.166.123:~/baganriki_20261018/teishutsu_20261020.tar.gz \\"
echo "    \"/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/20261020よっしー指摘/提出_20261020/\""
echo

echo "########## 9. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
printf "AiController : %s / %s行\n" "$(md5sum "$AI"|awk '{print $1}')" "$(wc -l < "$AI")"
grep -E "判定件数|合格 |不合格" "$OUT/02_受入試験_要約.txt" | head -3
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/02_受入試験_要約.txt" | head -10
grep -E "mode_cases|acquisition_boundary|すべて先方" "$OUT/03_合成試験.txt"
sed -n '/7-3\./,/8\./p' "$OUT/04_72レース突き合わせ.txt" | grep -E "突き合わせたレース|食い違ったレース|全レースで一致"
echo "--- 元値の指紋 ---"
grep -v Warning "$OUT/08_元値の指紋_結果.txt"
echo "--- まとめたもの ---"
ls -l "$KEEP/teishutsu_20261020.tar.gz"
echo "----8<---- ここまで ----8<----"
