#!/bin/bash
# @ANCHOR-20261020-R122-SHADOW  r12.2 の旗を .env へ設定します
#
#   いまの商品を落とさない設定にします。
#     BAGANRIKI_R122_AUDIT             = 1       取得監査を必ず残す（土台）
#     BAGANRIKI_R122_PROMPT            = shadow  入力を作って凍結するが、送るのは旧本文
#     BAGANRIKI_R122_QUEUE             = 0       まだキューは動かさない
#     BAGANRIKI_SALES_CLOSE_DERIVE     = 1       投票締切をJRAの規則から出す
#     BAGANRIKI_SALES_CLOSE_OFFSET_SEC = 60      発走の60秒前
#     BAGANRIKI_PAST_RUNS_MAX          = 5       過去走は直近5走まで
#
#   .env は書き換える前に必ず控えを取ります。
#   同じキーが既にあれば値だけ入れ替え、無ければ末尾に足します。
set -u
SRV=centos@49.212.166.123
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
ENVF="$APP/.env"
STAMP="$(date +%Y%m%d_%H%M%S)"
BK=~/baganriki_env_backup
mkdir -p "$BK"

if [ ! -f "$ENVF" ]; then
  echo "× $ENVF がありません。何もしません。"
  exit 1
fi

cp -p "$ENVF" "$BK/.env.before_$STAMP"
echo "控えを取りました: $BK/.env.before_$STAMP"
echo ""
echo "══ 変更前（BAGANRIKI_ の行）══"
grep -E "^BAGANRIKI_" "$ENVF" || echo "  （まだありません）"

set_kv() {
  key="$1"; val="$2"
  if grep -qE "^[#[:space:]]*${key}=" "$ENVF"; then
    # コメント行も含めて、そのキーの行を1本に置き換えます
    awk -v k="$key" -v v="$val" '
      $0 ~ "^[#[:space:]]*" k "=" { if (!done) { print k "=" v; done=1 } ; next }
      { print }
      END { if (!done) print k "=" v }
    ' "$ENVF" > "$ENVF.tmp" && mv "$ENVF.tmp" "$ENVF"
  else
    printf '%s=%s\n' "$key" "$val" >> "$ENVF"
  fi
}

# BAGANRIKI_ の行が1本も無ければ、見出しを付けます
if ! grep -qE "^[#[:space:]]*BAGANRIKI_" "$ENVF"; then
  printf '\n# @ANCHOR-20261020-R122  馬眼力 r12.2 の旗\n' >> "$ENVF"
fi

set_kv BAGANRIKI_R122_AUDIT             1
set_kv BAGANRIKI_R122_PROMPT            shadow
set_kv BAGANRIKI_R122_QUEUE             0
set_kv BAGANRIKI_SALES_CLOSE_DERIVE     1
set_kv BAGANRIKI_SALES_CLOSE_OFFSET_SEC 60
set_kv BAGANRIKI_PAST_RUNS_MAX          5

chown apache:apache "$ENVF" 2>/dev/null || true
chmod 600 "$ENVF" 2>/dev/null || true

echo ""
echo "══ 変更後（BAGANRIKI_ の行）══"
grep -E "^BAGANRIKI_" "$ENVF"

cd "$APP"
php artisan config:clear >/dev/null 2>&1 || true
echo ""
echo "══ アプリから読めているか ══"
php artisan tinker --execute='
  foreach (["BAGANRIKI_R122_AUDIT","BAGANRIKI_R122_PROMPT","BAGANRIKI_R122_QUEUE",
            "BAGANRIKI_SALES_CLOSE_DERIVE","BAGANRIKI_SALES_CLOSE_OFFSET_SEC",
            "BAGANRIKI_PAST_RUNS_MAX"] as $k) {
    printf("  %-34s %s\n", $k, var_export(env($k), true));
  }
  printf("  %-34s %s\n", "本文の段", \App\Http\Controllers\Api\BaganrikiPrompt::mode());
  printf("  %-34s %s\n", "キューの段", \App\Http\Controllers\Api\BaganrikiWorker::mode());
  printf("  %-34s %s\n", "締切の前倒し(秒)", \App\Http\Controllers\Api\BaganrikiTime::salesCloseOffsetSec());
  printf("  %-34s %s\n", "過去走の上限(走)", \App\Http\Controllers\Api\AiController::r122PastRunsMax());
' 2>&1 | grep -v "^Psy\|^$"

echo ""
echo "══ 受入試験（設定を変えても壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"
echo ""
echo "控えから戻すとき: cp -p $BK/.env.before_$STAMP $ENVF && cd $APP && php artisan config:clear"
REMOTE
