#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバ（さくらVPS）で実行】ssh centos@49.212.166.123 したあと
#   bash /tmp/02_server_v9.sh
#   やること：v9を入れて再集計し、証跡を作るだけ（DBへは書きません）
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
TGT=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/v9
mkdir -p "$OUT"

echo "########## 0. いまサーバに入っている版 ##########"
wc -l "$TGT"; md5sum "$TGT"
cp -p "$TGT" "$OUT/SummaryAiAnalysisCompensate.before_v9.php"
echo

echo "########## 1. v9 を入れる ##########"
cp /tmp/SummaryAiAnalysisCompensate.v9.php "$TGT" || exit 1
wc -l "$TGT"; md5sum "$TGT"
echo "  期待：11330行 / 05f348d701018e56627fe037dd02441f"
echo

echo "########## 2. 構文検査 ##########"
php -l "$TGT" || { echo "!! 構文エラー。元に戻します"; cp -p "$OUT/SummaryAiAnalysisCompensate.before_v9.php" "$TGT"; exit 1; }
echo

cd "$APP" || exit 1

echo "########## 3. 再集計（v9）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_v9_20261019.txt"
echo

echo "########## 4. 008③の確認：開催が無い日を混ぜる ##########"
echo "  （2026-10-20 は開催なし。72レースが減らないこと・"
echo "    「開催が無い日」と出ることを見ます）"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-20,2026-10-18 \
  2>&1 | tee "$OUT/mode_check_v9_withemptyday_20261019.txt"
echo

echo "########## 5. CSVをv9の名前で退避 ##########"
for f in mode_check_20261017_20261018.csv mode_check_odds_20261017_20261018.csv; do
  if [ -f "$APP/storage/logs/$f" ]; then
    cp -p "$APP/storage/logs/$f" "$OUT/$(echo $f | sed 's/^mode_check/mode_check_v9/')"
    echo "  退避: $f"
  else
    echo "  !! 見つかりません: storage/logs/$f"
  fi
done
ls -l "$OUT"/*.csv 2>/dev/null
echo

echo "########## 6. コード抜粋（modecheck_code_v9.txt）##########"
{
  echo "# 抜粋元: $TGT"
  echo "# md5: $(md5sum "$TGT" | awk '{print $1}')"
  echo "# 行数: $(wc -l < "$TGT")"
  echo "# 抜粋範囲: 9650〜10750行（runModeCheck ～ atLeast）"
  echo "# 認証情報は含みません"
  echo "# ------------------------------------------------------------"
  awk 'NR>=9650 && NR<=10750 {printf "%6d  %s\n", NR, $0}' "$TGT"
} > "$OUT/modecheck_code_v9.txt"
wc -l "$OUT/modecheck_code_v9.txt"; md5sum "$OUT/modecheck_code_v9.txt"
echo

echo "########## 7. 取消の件数をSQLで直接確認 ##########"
cat > "$OUT/cancel.sql" <<'SQL'
SELECT minutes_before_start AS mbs, COUNT(*) AS n, SUM(odds='取消') AS cancel
FROM t_horse_odds_finder_odds GROUP BY minutes_before_start
ORDER BY CASE WHEN minutes_before_start=999 THEN 30
              WHEN minutes_before_start=-999 THEN 0
              ELSE minutes_before_start END DESC;
SELECT o.date,o.kaisuu,o.basho,o.day,o.race,o.num,h.name,
       GROUP_CONCAT(o.minutes_before_start ORDER BY o.minutes_before_start) AS cancel_slots
FROM t_horse_odds_finder_odds o
LEFT JOIN t_horse_odds_finder_horses h
  ON h.date=o.date AND h.kaisuu=o.kaisuu AND h.basho=o.basho
 AND h.day=o.day AND h.race=o.race AND h.num=o.num
WHERE o.odds='取消'
GROUP BY o.date,o.kaisuu,o.basho,o.day,o.race,o.num,h.name;
SQL
mysql -uroot horse_odds_finder < "$OUT/cancel.sql" 2>&1 | tee "$OUT/cancel_counts_v9.tsv"
echo

echo "########## 8. LATEST_MD5.txt ##########"
{
  echo "# 馬眼力 v0.4.1-r12.1 最新成果物のハッシュ（$(date '+%Y-%m-%d %H:%M:%S %Z')）"
  echo
  echo "## 本番コード"
  md5sum "$TGT"
  echo "  行数: $(wc -l < "$TGT")"
  echo
  echo "## v9の出力"
  (cd "$OUT" && md5sum mode_check_v9_20261019.txt \
      mode_check_v9_withemptyday_20261019.txt \
      mode_check_v9_20261017_20261018.csv \
      mode_check_odds_v9_20261017_20261018.csv \
      modecheck_code_v9.txt cancel_counts_v9.tsv cancel.sql 2>&1)
} > "$OUT/LATEST_MD5.txt"
cat "$OUT/LATEST_MD5.txt"
echo

echo "########## 9. まとめ（この9行をそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
echo "版: $(md5sum "$TGT" | awk '{print $1}') / $(wc -l < "$TGT")行"
grep -E "傾向を確定|判定不能|計算不能|凍結前の取消|凍結後の取消|取消\(" "$OUT/mode_check_v9_20261019.txt" | head -20
echo "--- 開催が無い日を混ぜたとき ---"
grep -E "開催|傾向を確定|判定不能|計算不能" "$OUT/mode_check_v9_withemptyday_20261019.txt" | head -10
echo "----8<---- ここまで ----8<----"
