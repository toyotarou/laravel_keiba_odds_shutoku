#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# 10月20日005 のご指摘のうち4件を直す
#   @ANCHOR-20261020-R122-RECALC   取消後に目標数Cと基本配分を引き直す
#   @ANCHOR-20261020-R122-PROOF    証明の無い +5 を止める
#   @ANCHOR-20261020-R122-HORSENO  正規応答の馬番キー（horse_no）を読む
#   （Zの丸めは v26 で修正済み）
# AIへは送りません。DBへの書き込みも0件です。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php" "$MACAPP/app/Http/Controllers/Api/AiController.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php"
echo "  期待 d95567bc71e25a511bfa749d99d1b15e"
ssh "$SRV" 'mkdir -p ~/r122_v27/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v27/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v27/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v27_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v27_$STAMP/"
cp ~/r122_v27/code/AiController.php "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php; md5sum app/Http/Controllers/Api/AiController.php
echo "期待 21941行 / d95567bc71e25a511bfa749d99d1b15e"
php -l app/Http/Controllers/Api/AiController.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ 受入試験 ══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/01_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/01_受入試験.txt"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -24
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/02_全項目.txt" 2>&1
echo "--- R122-R03（証明のない+5を止めた）---"
grep "R122-R03" "$OUT/02_全項目.txt"

echo ""; echo "══ 送信文の検査・異常例・合成試験 ══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -3
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | grep -E "対象 32|Schemaの判定|意味検証の判定|すべて先方"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary|すべて先方"

echo ""; echo "══ 72レース ══"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 > "$OUT/03_72レース.txt" 2>&1
sed -n '/7-3\./,/全レースで一致/p' "$OUT/03_72レース.txt" | head -6

echo ""; echo "結果: $OUT"
REMOTE
