#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-PROMPT  サーバ側：r12.2 の両AI本文を入れて検査する（v21）
# ═══════════════════════════════════════════════════════════════════════════
# 大事なこと
#   ・AIへは1回も送りません（費用0円）。
#   ・DBへ1行も書きません。
#   ・機能フラグ BAGANRIKI_R122_PROMPT は入れません。既定（切）のままなので、
#     本番の送信内容は1バイトも変わりません。
#   ・パスワードは .env から読みます。手で打つ場面はありません。
set -u
APP=/var/www/horse_odds_finder
SRC=~/r122_prompt
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$SRC/result_$STAMP"
mkdir -p "$OUT"
say(){ echo ""; echo "══ $* ══"; }

say "0. 作業前の控え"
mkdir -p "$BK/before_r122prompt_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_r122prompt_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_r122prompt_$STAMP/"
( cd "$BK/before_r122prompt_$STAMP" && wc -l *.php && md5sum *.php ) | tee "$OUT/00_控え.txt"

say "1. 置き換え"
cp "$SRC/code/AiController.php"                "$APP/app/Http/Controllers/Api/AiController.php"
cp "$SRC/code/SummaryAiAnalysisCompensate.php" "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
{ cd "$APP" && wc -l app/Http/Controllers/Api/AiController.php \
                     app/Console/Commands/SummaryAiAnalysisCompensate.php
  md5sum app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php
  echo "期待 AiController : 21254行 / 46bb07d6faf1843dbfbbddafe412e5eb"
  echo "期待 Command      : 12928行 / ec06597c65e0dfb51f659895e8f5179a"
} | tee "$OUT/01_配置後.txt"
php -l "$APP/app/Http/Controllers/Api/AiController.php"            | tee -a "$OUT/01_配置後.txt"
php -l "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" | tee -a "$OUT/01_配置後.txt"
cd "$APP" && php artisan config:clear >/dev/null 2>&1 || true

say "2. 資材を展開"
rm -rf "$SRC/mat" ; mkdir -p "$SRC/mat"
tar xzf "$SRC/r122_material.tgz" -C "$SRC/mat"
ls "$SRC/mat" | tee "$OUT/02_資材.txt"

say "3. 両AI本文を storage へ置く（artisan の前に確実に置きます）"
mkdir -p "$APP/storage/app/baganriki/prompts/r12_2"
cp "$SRC/prompts/"*.txt "$APP/storage/app/baganriki/prompts/r12_2/"
chown -R apache:apache "$APP/storage/app/baganriki" 2>/dev/null || true
{ ls -l "$APP/storage/app/baganriki/prompts/r12_2/"
  sha256sum "$APP/storage/app/baganriki/prompts/r12_2/"*.txt
  echo "期待 prompt_1st  0f3d3c95d332b744105b89a488de610dec0fa28f1cffeeb93788ba0016eadfd3"
  echo "期待 prompt_2nd  90f40945f64bbbc991b8781edf3b4688a231b6616545e0ff636fb8c2c8982d3d"
  echo "期待 common      e63ee2ec84868420fbc24a247966116d5a8f891ba6696bc98650497c39e4ced8"
} | tee "$OUT/03_本文の設置.txt"

say "4. 本文の設置と中身の確認（artisan）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-install="$SRC/prompts" 2>&1 | tee -a "$OUT/03_本文の設置.txt"

say "5. 送信文の検査（§1〜§5。AI呼び出し0回）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" \
  --r122-prompt-dump="$OUT/送信文" 2>&1 | tee "$OUT/04_送信文の検査.txt"

say "6. 本番DBでの組み立て（§6。止まる理由の内訳）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" --r122-prompt-real \
  --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/05_本番DBでの組み立て.txt"

say "7. 本番DBでの組み立て（投票締切と取得監査を仮置きしたとき）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" --r122-prompt-real \
  --r122-prompt-assume --r122-prompt-sales-offset=60 \
  --r122-prompt-dump="$OUT/送信文_本番_仮置き" \
  --date=2026-10-18 2>&1 | tee "$OUT/06_本番DB_仮置き.txt"

say "8. 受入試験（全件）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/07_受入試験.txt"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/07b_受入試験_全項目.txt" 2>&1 || true

say "9. 先方の合成試験（729件＋4件）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-cases="$SRC/mat/validation" 2>&1 | tee "$OUT/08_合成試験.txt"

say "10. 本番側と検証側の信号の突き合わせ（72レース）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/09_信号の突き合わせ.txt"

say "11. 既存テーブルの元値の指紋（10/20提出時と同じSQL）"
cat > "$OUT/10_指紋.sql" <<'SQL'
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
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
{ mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/10_指紋.sql" 2>&1
  echo "--- 10/20 提出時の基準値 ---"
  echo "odds         8283  bcba698ed03855c2853df74839b542f8"
  echo "horses        922  16f97810a2bdb18838166a005b50add5"
  echo "races          72  8e8e8ee3b9ff9df5e9b3740a9e68d028"
  echo "race_results  916  8bb127a8b6bb6d46e48d08f5a56c1be6"
} | tee "$OUT/11_既存データの指紋.txt"

say "12. 機能フラグが切のままであることの確認"
{ echo "BAGANRIKI_R122_PROMPT の設定:"
  grep -n 'BAGANRIKI_R122_PROMPT' "$APP/.env" || echo "  .env に記載なし（＝切。既定のまま）"
} | tee "$OUT/12_機能フラグ.txt"

say "13. この実行のまとめ（提出用）"
tar czf "$BK/r122prompt_$STAMP.tar.gz" -C "$SRC" "result_$STAMP"
ls -l "$BK/r122prompt_$STAMP.tar.gz"

say "おわり"
echo "結果: $OUT"
ls -l "$OUT"
echo ""
echo "※ 本番の送信を新しい本文へ切り替えるときだけ .env へ次の1行を入れてください。"
echo "    BAGANRIKI_R122_PROMPT=1"
