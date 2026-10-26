#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# なる様が求めた環境資料を集めます（読むだけ・送信なし・課金なし）
# ══════════════════════════════════════════════════════════════════════
# 010の6章「差し替えコードの完成に必要な環境資料」への対応です。
#   ・composer.json／composer.lock
#   ・config/queue.php、スケジューラ登録箇所、既存Jobとworker設定
#   ・AnthropicService.php、モデル・max_tokens 等の設定箇所
#   ・migration（r12.2 で足した表）
#   ・16／17／18頭の計測ログ
#
# APIキー・パスワードは含めません（.env は集めません）。
# config/services.php はキーを伏せ字にしてから入れます。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123

ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
OUT=~/r122_env_$(date +%Y%m%d_%H%M%S)
mkdir -p "$OUT"/{composer,config,console,services,migrations,jobs,supervisor}
cd "$APP"

echo "══ ① composer ══"
cp composer.json "$OUT/composer/" 2>/dev/null && echo "  ○ composer.json"
cp composer.lock "$OUT/composer/" 2>/dev/null && echo "  ○ composer.lock"
php artisan --version 2>&1 | tee "$OUT/composer/artisan_version.txt"
php -v 2>&1 | head -1 | tee -a "$OUT/composer/artisan_version.txt"

echo ""; echo "══ ② queue の設定 ══"
cp config/queue.php "$OUT/config/" 2>/dev/null && echo "  ○ config/queue.php"
for f in config/app.php config/database.php config/logging.php; do
  [ -f "$f" ] && cp "$f" "$OUT/config/" && echo "  ○ $f"
done
# services.php はキーを伏せ字にしてから
if [ -f config/services.php ]; then
  sed -E "s/(['\"]?(key|secret|token|password)['\"]?[^,]*=>[^,]*)/\1 \/* 伏せ字 *\//I" \
      config/services.php > "$OUT/config/services.php.masked"
  echo "  ○ config/services.php（キーの行に伏せ字の印を付けました）"
fi
echo "-- .env のうち、キーでない行だけ --"
grep -E "^(QUEUE_|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|CACHE_|SESSION_|APP_ENV|APP_DEBUG|BAGANRIKI_)" .env \
  2>/dev/null | sed -E "s/(KEY|SECRET|TOKEN|PASSWORD)=.*/\1=（伏せ字）/" \
  | tee "$OUT/config/env_抜粋.txt"

echo ""; echo "══ ③ スケジューラと既存Job ══"
for f in app/Console/Kernel.php routes/console.php bootstrap/app.php; do
  [ -f "$f" ] && cp "$f" "$OUT/console/" && echo "  ○ $f"
done
if [ -d app/Jobs ]; then
  cp -r app/Jobs/* "$OUT/jobs/" 2>/dev/null
  echo "  ○ app/Jobs（$(ls app/Jobs | wc -l) 本）"
else
  echo "  － app/Jobs はありません（Jobは1本も作っていません）" | tee "$OUT/jobs/なし.txt"
fi
ls -1 app/Console/Commands > "$OUT/console/commands一覧.txt" 2>/dev/null
echo "  ○ Command 一覧 $(wc -l < "$OUT/console/commands一覧.txt") 本"

echo ""; echo "══ ④ queue のテーブルと worker ══"
php artisan tinker --execute='
  foreach (["jobs","job_batches","failed_jobs"] as $t) {
    echo sprintf("  %-14s %s", $t,
      \Schema::hasTable($t) ? "あります（".\DB::table($t)->count()." 行）" : "ありません") . PHP_EOL;
  }
  echo "  queue 既定接続 : " . config("queue.default") . PHP_EOL;
  $c = config("queue.connections." . config("queue.default"));
  echo "  その設定       : " . json_encode($c, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/config/queue状態.txt"
echo "-- supervisor --"
{ ls -1 /etc/supervisord.d/ 2>/dev/null || echo "（/etc/supervisord.d はありません）";
  systemctl is-active supervisord 2>/dev/null || echo "（supervisord は動いていません）"; } \
  | tee "$OUT/supervisor/状態.txt"

echo ""; echo "══ ⑤ AnthropicService と送信口の設定 ══"
cp app/Services/AnthropicService.php "$OUT/services/" 2>/dev/null && echo "  ○ AnthropicService.php"
grep -n "MODEL\|MAX_TOKENS\|TIMEOUT\|API_URL\|API_VERSION" app/Services/AnthropicService.php \
  | tee "$OUT/services/定数.txt"
echo "-- 2nd（DeepSeek）側の設定 --"
grep -n "deepseek\|max_tokens" app/Http/Controllers/Api/AiController.php \
  | head -20 | tee "$OUT/services/2nd設定.txt"

echo ""; echo "══ ⑥ migration（r12.2 で足した表）══"
ls -1 database/migrations 2>/dev/null | grep -i "baganriki\|computed_at\|verified_at" \
  | tee "$OUT/migrations/一覧.txt"
for f in $(ls -1 database/migrations 2>/dev/null | grep -i "baganriki"); do
  cp "database/migrations/$f" "$OUT/migrations/" 2>/dev/null
done
echo "-- r12.2 の表の定義（migration が無い分はここから読めます）--"
php artisan tinker --execute='
  foreach (["baganriki_snapshots","baganriki_ai_attempts","baganriki_live_states",
            "baganriki_audit_events","baganriki_validation_results",
            "baganriki_publications","baganriki_publication_intents",
            "baganriki_race_runs","baganriki_acquisition_batches"] as $t) {
    if (!\Schema::hasTable($t)) { echo "  － $t ありません" . PHP_EOL; continue; }
    $r = \DB::select("SHOW CREATE TABLE `$t`");
    echo "-- $t --" . PHP_EOL . ($r[0]->{"Create Table"} ?? "") . ";" . PHP_EOL . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" > "$OUT/migrations/r122_テーブル定義.sql"
wc -l "$OUT/migrations/r122_テーブル定義.sql"

echo ""; echo "══ ⑦ 直近の計測ログ ══"
mkdir -p "$OUT/計測ログ"
LATEST=$(ls -dt ~/r122_v78/result_* 2>/dev/null | head -1)
if [ -n "${LATEST:-}" ]; then
  cp "$LATEST"/*.txt "$OUT/計測ログ/" 2>/dev/null
  mkdir -p "$OUT/計測ログ/bodies"
  cp "$LATEST"/bodies/* "$OUT/計測ログ/bodies/" 2>/dev/null
  echo "  ○ $LATEST から取りました"
  ls -la "$OUT/計測ログ" "$OUT/計測ログ/bodies" | head -20
fi

tar czf ~/r122_env.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""
echo "まとめました: ~/r122_env.tgz"
du -sh ~/r122_env.tgz
REMOTE

scp -q "$SRV:~/r122_env.tgz" "$HERE/" \
  && echo "" && echo "取ってきました: $HERE/r122_env.tgz" \
  || echo "取得に失敗しました（サーバ側には ~/r122_env.tgz があります）"
