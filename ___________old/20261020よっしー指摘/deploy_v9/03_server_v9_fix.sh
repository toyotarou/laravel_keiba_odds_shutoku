#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】02の取りこぼし3点を直すだけ
#   ①MySQLのパスワード未指定でSQLが流れなかった
#   ②各馬CSVのファイル名を付け間違えた
#   ③v8とv9の差分を取って LATEST_MD5.txt を作り直す
#   （DBは読むだけ。コードも出力も作り直しません）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
TGT=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/v9
cd "$OUT" || exit 1

echo "########## 1. 各馬CSVの名前を直す ##########"
if [ -f mode_check_v9_odds_20261017_20261018.csv ]; then
  mv -f mode_check_v9_odds_20261017_20261018.csv mode_check_odds_v9_20261017_20261018.csv
fi
ls -l *.csv
echo

echo "########## 2. .env からDB接続情報を読んでSQLを流す ##########"
DBH=$(grep -m1 '^DB_HOST='     "$APP/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' "$APP/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
DBU=$(grep -m1 '^DB_USERNAME=' "$APP/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
DBP=$(grep -m1 '^DB_PASSWORD=' "$APP/.env" | cut -d= -f2- | tr -d '"'"'"' \r')
echo "  接続先: ${DBU}@${DBH:-localhost} / ${DBN}   （パスワードは表示しません）"

MYSQL=(mysql -h "${DBH:-localhost}" -u "$DBU" "$DBN")
[ -n "$DBP" ] && MYSQL=(mysql -h "${DBH:-localhost}" -u "$DBU" -p"$DBP" "$DBN")

"${MYSQL[@]}" < cancel.sql 2>&1 | tee cancel_counts_v9.tsv
echo

echo "########## 3. ③のレースの全頭オッズも証跡に残す ##########"
cat > cancel_race.sql <<'SQL'
SELECT o.num, h.name,
 MAX(CASE WHEN o.minutes_before_start=999  THEN o.odds END) AS t30,
 MAX(CASE WHEN o.minutes_before_start=21   THEN o.odds END) AS t21,
 MAX(CASE WHEN o.minutes_before_start=18   THEN o.odds END) AS t18,
 MAX(CASE WHEN o.minutes_before_start=15   THEN o.odds END) AS t15,
 MAX(CASE WHEN o.minutes_before_start=12   THEN o.odds END) AS t12,
 MAX(CASE WHEN o.minutes_before_start=9    THEN o.odds END) AS t9,
 MAX(CASE WHEN o.minutes_before_start=6    THEN o.odds END) AS t6,
 MAX(CASE WHEN o.minutes_before_start=3    THEN o.odds END) AS t3,
 MAX(CASE WHEN o.minutes_before_start=-999 THEN o.odds END) AS t0
FROM t_horse_odds_finder_odds o
LEFT JOIN t_horse_odds_finder_horses h
  ON h.date=o.date AND h.kaisuu=o.kaisuu AND h.basho=o.basho
 AND h.day=o.day AND h.race=o.race AND h.num=o.num
WHERE o.date='2026-10-18' AND o.kaisuu='4' AND o.basho='08' AND o.day='7' AND o.race=9
GROUP BY o.num,h.name ORDER BY o.num;
SELECT r.num_horses,
 (SELECT COUNT(*) FROM t_horse_odds_finder_horses h
   WHERE h.date=r.date AND h.kaisuu=r.kaisuu AND h.basho=r.basho
     AND h.day=r.day AND h.race=r.race) AS entry,
 (SELECT GROUP_CONCAT(rr.num ORDER BY rr.num) FROM t_horse_odds_finder_race_results rr
   WHERE rr.date=r.date AND rr.kaisuu=r.kaisuu AND rr.basho=r.basho
     AND rr.day=r.day AND rr.race=r.race) AS result_nums
FROM t_horse_odds_finder_races r
WHERE r.date='2026-10-18' AND r.kaisuu='4' AND r.basho='08' AND r.day='7' AND r.race=9;
SQL
"${MYSQL[@]}" < cancel_race.sql 2>&1 | tee cancel_race_v9.tsv
echo

echo "########## 4. v8とv9のCSV差分（どこが変わったか）##########"
for p in "mode_check" "mode_check_odds"; do
  V8="$KEEP/${p/mode_check/mode_check_v8}_20261017_20261018.csv"
  [ "$p" = "mode_check" ]      && V8="$KEEP/mode_check_v8_20261017_20261018.csv"
  [ "$p" = "mode_check_odds" ] && V8="$KEEP/mode_check_odds_v8_20261017_20261018.csv"
  V9="$OUT/${p/mode_check/mode_check_v9}_20261017_20261018.csv"
  [ "$p" = "mode_check" ]      && V9="$OUT/mode_check_v9_20261017_20261018.csv"
  [ "$p" = "mode_check_odds" ] && V9="$OUT/mode_check_odds_v9_20261017_20261018.csv"
  echo "--- $p ---"
  if [ -f "$V8" ] && [ -f "$V9" ]; then
    echo "  行数 v8=$(wc -l < "$V8")  v9=$(wc -l < "$V9")"
    echo "  差分行数: $(diff "$V8" "$V9" | grep -c '^[<>]')"
    diff "$V8" "$V9" | head -20
  else
    echo "  !! 見つかりません v8=$V8 v9=$V9"
  fi
  echo
done | tee diff_v8_v9_20261019.txt
echo

echo "########## 5. LATEST_MD5.txt を作り直す ##########"
{
  echo "# 馬眼力 v0.4.1-r12.1 最新成果物のハッシュ（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  echo "## 本番コード"
  md5sum "$TGT"
  echo "  行数: $(wc -l < "$TGT")"
  echo
  echo "## v9の出力（$OUT）"
  md5sum mode_check_v9_20261019.txt \
         mode_check_v9_withemptyday_20261019.txt \
         mode_check_v9_20261017_20261018.csv \
         mode_check_odds_v9_20261017_20261018.csv \
         modecheck_code_v9.txt \
         cancel_counts_v9.tsv cancel.sql \
         cancel_race_v9.tsv cancel_race.sql \
         diff_v8_v9_20261019.txt 2>&1
  echo
  echo "## 行数"
  wc -l mode_check_v9_20261017_20261018.csv \
        mode_check_odds_v9_20261017_20261018.csv \
        modecheck_code_v9.txt 2>&1
} > LATEST_MD5.txt
cat LATEST_MD5.txt
echo

echo "########## 6. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
cat cancel_counts_v9.tsv
echo "--- v8→v9 のCSV差分 ---"
grep -E "^  行数|^  差分行数|^--- " diff_v8_v9_20261019.txt
echo "----8<---- ここまで ----8<----"
