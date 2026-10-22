#!/bin/bash
# @ANCHOR-20261020-R122-33R  33レース候補比較のCSVを作ってMacへ取り寄せる
set -u
SRV=centos@49.212.166.123
DEST="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/20261020よっしー指摘/提出_20261021"

echo "══ サーバで33レース比較を実行（読み取りだけ・AI呼び出し0回）══"
ssh "$SRV" 'cd /var/www/horse_odds_finder && php artisan keiba:ai-analysis-compensate \
  --mode-check --r122-33races --date=2026-10-17,2026-10-18 2>&1 \
  | grep -E "暫定|違う|同じ|CSVを書き出しました|レース$|件"'

echo ""; echo "══ いちばん新しいCSVを取り寄せ ══"
CSV=$(ssh "$SRV" 'ls -t /var/www/horse_odds_finder/storage/logs/r122_33races_*.csv 2>/dev/null | head -1')
if [ -z "$CSV" ]; then
  echo "  × CSVが見つかりません。上の出力をご確認ください"
  exit 1
fi
echo "  $CSV"
scp -q "$SRV:$CSV" "$DEST/05_33レース候補比較.csv"
echo "  → $DEST/05_33レース候補比較.csv"
wc -l "$DEST/05_33レース候補比較.csv"

echo ""; echo "══ まとめて1つにする ══"
cd "$DEST/.."
# @ANCHOR-20261020-R122-PACK  COPYFILE_DISABLE=1 を付けないと、macOSのtarが
#   xattr から ._ で始まる隠しメタデータを15本混ぜます（相手の数え方が合わなくなります）
COPYFILE_DISABLE=1 tar czf 馬眼力_r12.2_是正と設計書_20261021.tar.gz 提出_20261021
shasum -a 256 馬眼力_r12.2_是正と設計書_20261021.tar.gz
ls -la 馬眼力_r12.2_是正と設計書_20261021.tar.gz
echo ""
echo "══ 中身（$(find 提出_20261021 -type f | wc -l | tr -d ' ') ファイル）══"
find 提出_20261021 -type f | sort
