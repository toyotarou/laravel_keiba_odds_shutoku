#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-AUDIT  サーバ側：取得監査の記録を入れて確かめる
# ═══════════════════════════════════════════════════════════════════════════
# 大事なこと
#   ・AIへは1回も送りません（費用0円）。
#   ・既存テーブルへは1行も書きません。新テーブルへの試し書きは必ず
#     ロールバックするので、DBへ残る書き込みは0件です。
#   ・オッズ取得コマンドそのものはここでは動かしません（JRAへ行きません）。
#   ・機能フラグ BAGANRIKI_R122_PROMPT は入れません（本番の送信は従来どおり）。
set -u
APP=/var/www/horse_odds_finder
SRC=~/r122_audit
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$SRC/result_$STAMP"
mkdir -p "$OUT"
say(){ echo ""; echo "══ $* ══"; }

say "0. 作業前の控え"
mkdir -p "$BK/before_r122audit_$STAMP"
cp -p "$APP/app/Console/Commands/ImportKeibaOdds.php"             "$BK/before_r122audit_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_r122audit_$STAMP/"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_r122audit_$STAMP/"
( cd "$BK/before_r122audit_$STAMP" && wc -l *.php && md5sum *.php ) | tee "$OUT/00_控え.txt"

say "1. 置き換え"
cp "$SRC/code/ImportKeibaOdds.php"             "$APP/app/Console/Commands/ImportKeibaOdds.php"
cp "$SRC/code/SummaryAiAnalysisCompensate.php" "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$SRC/code/AiController.php"                "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Console/Commands/ImportKeibaOdds.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
                    "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
{ cd "$APP" && wc -l app/Console/Commands/ImportKeibaOdds.php \
                     app/Console/Commands/SummaryAiAnalysisCompensate.php \
                     app/Http/Controllers/Api/AiController.php
  md5sum app/Console/Commands/ImportKeibaOdds.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Http/Controllers/Api/AiController.php
  echo "期待 ImportKeibaOdds : 533行   / a863f54c06c90dce8a39080282a468c7"
  echo "期待 Command         : 13111行 / 2d3a5b11d1eae89cc6e196daee8d6d1d"
  echo "期待 AiController    : 21254行 / 46bb07d6faf1843dbfbbddafe412e5eb"
} | tee "$OUT/01_配置後.txt"
for f in app/Console/Commands/ImportKeibaOdds.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Http/Controllers/Api/AiController.php ; do
  php -l "$APP/$f" | tee -a "$OUT/01_配置後.txt"
done
cd "$APP" && php artisan config:clear >/dev/null 2>&1 || true

say "2. オッズ取得コマンドが壊れていないこと（--help だけ。JRAへは行きません）"
cd "$APP" && php artisan keiba:importOdds --help 2>&1 | head -20 | tee "$OUT/02_importOdds_help.txt"

say "3. r12.2 テーブルの状態"
cd "$APP" && php artisan keiba:ai-analysis-compensate --r122-status 2>&1 | tee "$OUT/03_テーブル状態.txt"

say "4. 取得監査の記録の経路の確認（試し書きは必ずロールバック）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --r122-audit-check \
  --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/04_取得監査の確認.txt"

say "5. 送信文の検査（変わっていないことの確認）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$HOME/r122_prompt/mat" 2>&1 | tail -30 | tee "$OUT/05_送信文の検査.txt"

say "6. 受入試験（全件）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "判定件数|合格|不合格|── 不合格" -A6 | head -20 | tee "$OUT/06_受入試験.txt"

say "7. 既存テーブルの元値の指紋（10/20提出時と同じSQL）"
cat > "$OUT/07_指紋.sql" <<'SQL'
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
 FROM t_horse_odds_finder_race_results
UNION ALL SELECT 'acq_batches', COUNT(*), 'r12.2の新テーブル（0件が正常）'
 FROM baganriki_acquisition_batches;
SQL
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
{ mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/07_指紋.sql" 2>&1
  echo "--- 10/20 提出時の基準値 ---"
  echo "odds         8283  bcba698ed03855c2853df74839b542f8"
  echo "horses        922  16f97810a2bdb18838166a005b50add5"
  echo "races          72  8e8e8ee3b9ff9df5e9b3740a9e68d028"
  echo "race_results  916  8bb127a8b6bb6d46e48d08f5a56c1be6"
} | tee "$OUT/08_既存データの指紋.txt"

say "8. まとめ"
tar czf "$BK/r122audit_$STAMP.tar.gz" -C "$SRC" "result_$STAMP"
ls -l "$BK/r122audit_$STAMP.tar.gz"
echo "結果: $OUT"
ls -l "$OUT"
echo ""
echo "※ 監査行は、次にオッズを取得するレースから貯まります（cron はそのまま）。"
echo "   止めたいときだけ .env に BAGANRIKI_R122_AUDIT=0 を入れてください。"
