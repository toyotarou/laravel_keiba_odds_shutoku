#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-Z  Zの丸めを直す（10月20日005のご指摘3）
# ═══════════════════════════════════════════════════════════════════════════
# Mac配置 → サーバ反映 → 確認まで1本で終わります。
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
echo "  期待 4d61e70c6de61608540286a987fa2baf"
ssh "$SRV" 'mkdir -p ~/r122_v26/code'
scp -q "$HERE"/code/AiController.php "$SRV:~/r122_v26/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v26/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v26_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php" "$BK/before_v26_$STAMP/"
cp ~/r122_v26/code/AiController.php "$APP/app/Http/Controllers/Api/AiController.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php; md5sum app/Http/Controllers/Api/AiController.php
echo "期待 21820行 / 4d61e70c6de61608540286a987fa2baf"
php -l app/Http/Controllers/Api/AiController.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ 受入試験（Zの16頭の境界例を追加）══"
php artisan keiba:ai-analysis-compensate --v04-test > "$OUT/01_受入試験.txt" 2>&1
grep -E "判定件数|合格 |不合格" "$OUT/01_受入試験.txt"
echo "--- R122-Z01 の明細 ---"
php artisan keiba:ai-analysis-compensate --v04-test-detail > "$OUT/02_受入試験_全項目.txt" 2>&1
grep "R122-Z01" "$OUT/02_受入試験_全項目.txt" | head -25
echo "--- 不合格があれば表示 ---"
sed -n '/── 不合格の内容/,/── ここでは判定していない/p' "$OUT/01_受入試験.txt" | head -20

echo ""; echo "══ 送信文の検査（参照入力14本が変わっていないこと）══"
php artisan keiba:ai-analysis-compensate --r122-prompt-check="$HOME/r122_v23/mat" 2>&1 | tail -4 | tee "$OUT/03_送信文.txt"

echo ""; echo "══ 異常例32本 ══"
php artisan keiba:ai-analysis-compensate --r122-invalid-cases="$HOME/r122_v23/mat" 2>&1 | tail -6 | tee "$OUT/04_異常例.txt"

echo ""; echo "══ 合成試験 729件＋4件 ══"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 | grep -E "mode_cases|acquisition_boundary|すべて先方" | tee "$OUT/05_合成試験.txt"

echo ""; echo "══ 72レース（Zを直したので mode が変わるレースがあるか）══"
php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 > "$OUT/06_72レース.txt" 2>&1
sed -n '/ 1\. レース傾向/,/ 2\./p' "$OUT/06_72レース.txt"
sed -n '/7-3\./,/書き出しました/p' "$OUT/06_72レース.txt" | head -8

echo ""; echo "結果: $OUT"
REMOTE
