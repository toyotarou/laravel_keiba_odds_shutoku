#!/bin/bash
# ───────────────────────────────────────────────
# 【サーバで実行】r12.2 実装一式
#   ssh centos@49.212.166.123 のあと  bash /tmp/13_server_full.sh
#
#   このスクリプトがすること
#     ・3ファイルを入れ替えて構文検査
#     ・受入試験（本体の自己試験＋先方の合成試験）
#     ・r12.2 の新規テーブル9本を作る（既にあれば何もしない）
#     ・72レースを再集計して、切り出し前と同じになることを確認
#   既存テーブルの中身は変えません。AI呼び出しもありません（費用0円）。
# ───────────────────────────────────────────────
set -u
APP=/var/www/horse_odds_finder
AI=$APP/app/Http/Controllers/Api/AiController.php
CMD=$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php
RT=$APP/routes/api.php
KEEP=$HOME/baganriki_20261018
OUT=$KEEP/r122_full
mkdir -p "$OUT"

echo "########## 0. いまの版を控える ##########"
for f in "$AI" "$CMD" "$RT"; do printf "  %7d 行  %s  %s\n" "$(wc -l < "$f")" "$(md5sum "$f"|awk '{print $1}')" "$(basename "$f")"; done
cp -p "$AI"  "$OUT/AiController.before.php"
cp -p "$CMD" "$OUT/Command.before.php"
cp -p "$RT"  "$OUT/api.before.php"
echo

echo "########## 1. 入れ替える ##########"
cp /tmp/AiController.full.php               "$AI"  || exit 1
cp /tmp/SummaryAiAnalysisCompensate.full.php "$CMD" || exit 1
cp /tmp/api.full.php                        "$RT"  || exit 1
for f in "$AI" "$CMD" "$RT"; do printf "  %7d 行  %s  %s\n" "$(wc -l < "$f")" "$(md5sum "$f"|awk '{print $1}')" "$(basename "$f")"; done
echo "  期待： 19491 / a7d6b015384d90dd5426f9099a88ceb1"
echo "         12059 / 5cce3c2d4645559f6c5d5289f5c6a7da"
echo "           193 / d6d7437ba60063fd256b64bc8b35aa09"
echo

echo "########## 2. 構文検査 ##########"
ok=1
for f in "$AI" "$CMD" "$RT"; do php -l "$f" || ok=0; done
if [ "$ok" -ne 1 ]; then
  echo "!! 構文エラー。全部元に戻します"
  cp -p "$OUT/AiController.before.php" "$AI"
  cp -p "$OUT/Command.before.php" "$CMD"
  cp -p "$OUT/api.before.php" "$RT"
  exit 1
fi
php artisan route:clear >/dev/null 2>&1
php artisan config:clear >/dev/null 2>&1
echo "  ルート・設定のキャッシュを消しました"
echo

cd "$APP" || exit 1

echo "########## 3. 本体の受入試験 ##########"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/v04_test.txt"
echo

echo "########## 4. 先方の合成試験 ##########"
php artisan keiba:ai-analysis-compensate --r122-cases=/tmp/r122_validation 2>&1 | tee "$OUT/r122_cases.txt"
echo

echo "########## 5. r12.2 の新しいテーブルを作る ##########"
php artisan keiba:ai-analysis-compensate --r122-install 2>&1 | tee "$OUT/r122_install.txt"
echo

echo "########## 6. 状態の確認 ##########"
php artisan keiba:ai-analysis-compensate --r122-status 2>&1 | tee "$OUT/r122_status.txt"
echo

echo "########## 7. 既存テーブルが無傷か ##########"
mysql -h 127.0.0.1 -uroot -p"$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')" \
  "$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')" -e "
SELECT 't_horse_odds_finder_odds' t, COUNT(*) n FROM t_horse_odds_finder_odds
UNION ALL SELECT 'races', COUNT(*) FROM t_horse_odds_finder_races
UNION ALL SELECT 'horses', COUNT(*) FROM t_horse_odds_finder_horses
UNION ALL SELECT 'race_results', COUNT(*) FROM t_horse_odds_finder_race_results;" 2>&1 | tee "$OUT/existing_counts.txt"
echo "  期待： odds 8283 / races 72 / horses 922 / race_results 916"
echo

echo "########## 8. 72レース再集計（前回と同じになること）##########"
php artisan keiba:ai-analysis-compensate --mode-check --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/mode_check.txt"
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/mode_check.csv"
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/mode_check_odds.csv"
{
  A="$KEEP/refactor1/mode_check_refactor.csv"
  [ -f "$A" ] && echo "レース別CSVの差分行数: $(diff "$A" "$OUT/mode_check.csv" | grep -c '^[<>]')"
  B="$KEEP/refactor1/mode_check_odds_refactor.csv"
  [ -f "$B" ] && echo "各馬CSVの差分行数: $(diff "$B" "$OUT/mode_check_odds.csv" | grep -c '^[<>]')"
} | tee "$OUT/diff_prev.txt"
echo

echo "########## 9. 読取APIが生えているか ##########"
php artisan route:list 2>/dev/null | grep -i "baganriki/r12-2" | tee "$OUT/route.txt"
[ -s "$OUT/route.txt" ] || echo "  !! ルートが見つかりません"
echo

echo "########## 10. まとめ（ここをそのまま貼ってください）##########"
echo "----8<---- ここから ----8<----"
printf "AiController : %s / %s行\n" "$(md5sum "$AI"|awk '{print $1}')" "$(wc -l < "$AI")"
printf "Command      : %s / %s行\n" "$(md5sum "$CMD"|awk '{print $1}')" "$(wc -l < "$CMD")"
grep -E "判定件数|合格 |不合格" "$OUT/v04_test.txt" | head -4
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/v04_test.txt" | head -12
grep -E "mode_cases|acquisition_boundary|すべて先方の期待値|一致しなかった" "$OUT/r122_cases.txt"
echo "--- テーブル ---"
grep -E "作りました|既にありました|失敗" "$OUT/r122_install.txt"
echo "--- 既存テーブルの件数 ---"
cat "$OUT/existing_counts.txt"
echo "--- 72レース ---"
grep -E "確定（CONFIRMED）|暫定選出（PROVISIONAL）|判定不能（UNRESOLVED" "$OUT/mode_check.txt"
echo "--- 前回との差分 ---"
cat "$OUT/diff_prev.txt"
echo "--- 読取API ---"
cat "$OUT/route.txt"
echo "----8<---- ここまで ----8<----"
