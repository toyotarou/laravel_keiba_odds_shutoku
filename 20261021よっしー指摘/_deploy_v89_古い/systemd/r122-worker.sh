#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-WORKER  r12.2 の常駐worker（systemd から呼びます）
# ══════════════════════════════════════════════════════════════════════
# 引数1  待ち行列名:枠番号   例) r122-ai-1st:2
#   コロンの前だけを待ち行列名として使います。
#   番号は、同じ待ち行列に複数の枠を立てるためのただの識別子です。
#
# 秒数（010の2-1 末尾の整合）
#   --timeout=180   RunRoleJob の上限 175秒より長い
#   retry_after=300 worker の上限 180秒より十分長い（config/queue.php）
#   --tries=1       Job側の $tries を優先します（RunRoleJob は1回）
#   --max-time=3600 1時間で自分から降り、systemd が立て直します
#                   （PHPの長時間常駐でメモリが膨らむのを避けます）
#   --sleep=1       空のときは1秒だけ眠ります
# ══════════════════════════════════════════════════════════════════════
set -u
APP=/var/www/horse_odds_finder
INST="${1:-}"
if [ -z "$INST" ]; then
  echo "待ち行列名が渡されていません（例: r122-ai-1st:1）" >&2
  exit 2
fi
QUEUE="${INST%%:*}"
case "$QUEUE" in
  r122-freeze|r122-ai-1st|r122-ai-2nd|r122-agg) : ;;
  *) echo "知らない待ち行列です: $QUEUE" >&2; exit 2 ;;
esac
cd "$APP" || exit 2
exec /usr/bin/php artisan queue:work r122 \
  --queue="$QUEUE" \
  --sleep=1 \
  --tries=1 \
  --timeout=180 \
  --max-time=3600 \
  --max-jobs=200
