#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-PROMPT  サーバ側：r12.2 の両AI本文を入れて検査する
# ═══════════════════════════════════════════════════════════════════════════
# 大事なこと
#   ・AIへは1回も送りません（費用0円）。
#   ・DBへ1行も書きません（既存テーブルも新テーブルも触りません）。
#   ・機能フラグ BAGANRIKI_R122_PROMPT は入れません。既定（切）のままなので、
#     本番の送信内容は1バイトも変わりません。
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
for f in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php ; do
  cp -p "$APP/$f" "$BK/before_r122prompt_$STAMP/$(basename "$f")"
done
( cd "$BK/before_r122prompt_$STAMP" && wc -l *.php && md5sum *.php ) | tee "$OUT/00_控え.txt"

say "1. 置き換え"
cp "$SRC/code/AiController.php"                "$APP/app/Http/Controllers/Api/AiController.php"
cp "$SRC/code/SummaryAiAnalysisCompensate.php" "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
( cd "$APP" && wc -l app/Http/Controllers/Api/AiController.php \
                     app/Console/Commands/SummaryAiAnalysisCompensate.php \
  && md5sum app/Http/Controllers/Api/AiController.php \
            app/Console/Commands/SummaryAiAnalysisCompensate.php ) | tee "$OUT/01_配置後.txt"

say "2. 構文の確認"
php -l "$APP/app/Http/Controllers/Api/AiController.php"                | tee -a "$OUT/01_配置後.txt"
php -l "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"     | tee -a "$OUT/01_配置後.txt"

say "3. 資材を展開（schemas / validation / prompts）"
rm -rf "$SRC/mat" ; mkdir -p "$SRC/mat"
tar xzf "$SRC/r122_material.tgz" -C "$SRC/mat"
ls "$SRC/mat" | tee "$OUT/02_資材.txt"

say "4. 両AI本文を storage へ設置"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-install="$SRC/prompts" 2>&1 | tee "$OUT/03_本文の設置.txt"
chown -R apache:apache "$APP/storage/app/baganriki" 2>/dev/null || true

say "5. 送信文の検査（§1〜§5。AI呼び出し0回）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" \
  --r122-prompt-dump="$OUT/送信文" 2>&1 | tee "$OUT/04_送信文の検査.txt"

say "6. 本番DBでの組み立て（§6。止まる理由の内訳）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" --r122-prompt-real \
  --date=2026-10-17,2026-10-18,2026-10-19 2>&1 | tee "$OUT/05_本番DBでの組み立て.txt"

say "7. 本番DBでの組み立て（投票締切と取得監査を仮置きしたとき）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" --r122-prompt-real \
  --r122-prompt-assume --r122-prompt-sales-offset=60 \
  --r122-prompt-dump="$OUT/送信文_本番_仮置き" \
  --date=2026-10-18 2>&1 | tee "$OUT/06_本番DB_仮置き.txt"

say "8. 既存の受入試験（X6のN<7を直したので全件やり直し）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | tee "$OUT/07_受入試験.txt"

say "9. 先方の合成試験（729件＋4件。変わっていないことの確認）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-cases="$SRC/mat/validation" 2>&1 | tee "$OUT/08_合成試験.txt"

say "10. 本番側と検証側の信号の突き合わせ（72レース）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18,2026-10-19 2>&1 | tee "$OUT/09_信号の突き合わせ.txt"

say "11. 既存テーブルの元値が変わっていないことの確認"
mysql -u root -p horse_odds_finder -N -e "
SELECT 'odds',   COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,minutes_before_start,IFNULL(odds,''),IFNULL(fuku_min,''),IFNULL(fuku_max,'')) ORDER BY date,kaisuu,basho,day,race,num,minutes_before_start SEPARATOR ';')) FROM t_horse_odds_finder_odds
UNION ALL SELECT 'horses', COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num) ORDER BY date,kaisuu,basho,day,race,num SEPARATOR ';')) FROM t_horse_odds_finder_horses
UNION ALL SELECT 'races',  COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,IFNULL(start_time,''),IFNULL(num_horses,'')) ORDER BY date,kaisuu,basho,day,race SEPARATOR ';')) FROM t_horse_odds_finder_races
UNION ALL SELECT 'results',COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num) ORDER BY date,kaisuu,basho,day,race,num SEPARATOR ';')) FROM t_horse_odds_finder_race_results
" 2>&1 | tee "$OUT/10_既存データの指紋.txt"

say "12. 機能フラグが切のままであることの確認"
{ echo "BAGANRIKI_R122_PROMPT の設定:"
  grep -n 'BAGANRIKI_R122_PROMPT' "$APP/.env" || echo "  .env に記載なし（＝切。既定のまま）"
  echo "本文の置き場:"
  ls -l "$APP/storage/app/baganriki/prompts/r12_2/" 2>&1
  sha256sum "$APP/storage/app/baganriki/prompts/r12_2/"*.txt 2>&1
} | tee "$OUT/11_機能フラグ.txt"

say "おわり"
echo "結果はここにまとめてあります: $OUT"
ls -l "$OUT"
echo ""
echo "※ 本番の送信を新しい本文へ切り替えるときだけ、.env へ次の1行を入れてください。"
echo "    BAGANRIKI_R122_PROMPT=1"
echo "  （入れていない間は、送信内容は今までと1バイトも変わりません）"
