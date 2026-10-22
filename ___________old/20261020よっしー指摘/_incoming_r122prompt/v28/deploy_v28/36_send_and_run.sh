#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-ACQFIX  取得監査の実DB接続（10月20日005 のご指摘2）
#   2-1 DBの日時を RFC3339 へ変換（マイクロ秒を落とさない）
#   2-2 input_freeze_at を採用判定へ渡す＋正本anchorとの一致検査
#   2-3 採用バッチの raw_payload を SHA-256 照合して、その値だけを使う
#   2-4 監査行→採用→入力→凍結保存→読戻し の通し試験（--r122-acq-test）
# AIへは送りません。DBへ残る書き込みは0件です（通し試験もロールバック）。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"            "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 AiController 1f69fe1b7cb9c33505bb8013d5c42053"
echo "  期待 Command      aed8dbda8feaec0a6d96bbb355d2a818"
ssh "$SRV" 'mkdir -p ~/r122_v28/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v28/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v28/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v28_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v28_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v28_$STAMP/"
cp ~/r122_v28/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v28/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
md5sum app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ ① 監査行→採用→入力→凍結保存→読戻し（ロールバック）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 | tee "$OUT/01_通し試験.txt"

echo ""; echo "══ ② 受入試験 ══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/02_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/02_受入試験.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/02_受入試験.txt" | head -20

echo ""; echo "══ ③ 送信文・異常例・合成試験 ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | grep -E "Schemaの判定|意味検証の判定|すべて先方"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary"

echo ""; echo "══ ④ 既存テーブルが動いていないこと ══"
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" -N -e "
SELECT 'odds', COUNT(*) FROM t_horse_odds_finder_odds
UNION ALL SELECT 'horses', COUNT(*) FROM t_horse_odds_finder_horses
UNION ALL SELECT 'races', COUNT(*) FROM t_horse_odds_finder_races
UNION ALL SELECT 'results', COUNT(*) FROM t_horse_odds_finder_race_results
UNION ALL SELECT 'acq_batches', COUNT(*) FROM baganriki_acquisition_batches
UNION ALL SELECT 'snapshots', COUNT(*) FROM baganriki_snapshots
UNION ALL SELECT 'race_runs', COUNT(*) FROM baganriki_race_runs;" 2>&1 | grep -v '^mysql:' | tee "$OUT/03_件数.txt"
echo "期待 8283 / 922 / 72 / 916 / 0 / 0 / 0"
echo ""; echo "結果: $OUT"
REMOTE
