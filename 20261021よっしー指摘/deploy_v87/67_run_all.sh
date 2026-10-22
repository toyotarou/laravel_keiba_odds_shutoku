#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v87 ＝ ファイルは置き換えません。提出用に、全部を1回で通して記録します
# ══════════════════════════════════════════════════════════════════════
# v86 の結果（2026-10-21 13:58）
#   e2e 29件すべて合格。受入試験 662件すべて合格。
#   実凍結から公開まで一続きに通りました（13頭採用・公開1件）。
#
# いまサーバに在るもの（v86 で確定）
#   AiController.php                 2c6460626989c78bf542db65ef1fb3a8
#   SummaryAiAnalysisCompensate.php  e05ccaa64eda0225d3b24a25a32edd60
#   app/Jobs/ 5本・systemd 2本・config/queue.php の r122 の節
#
# この手順は<b>1ファイルも置き換えません。</b>
# v84 と v86 で別々に取ったログを、提出のために<b>同じ版で1回に揃えます</b>。
# なるさんが「実行ログがない」と毎回書かれているので、
# 全部を同じ実行時刻の記録にします。
#
# 旗は動かしません。worker も起動しません。実AIへの通信は0回です。
# crontab は読み出しだけです。
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v87/result_$STAMP; mkdir -p "$OUT"
cd "$APP"

echo ""; echo "══ ① いま動いている版 ══"
php -v | head -1 | tee "$OUT/00_版.txt"
php artisan --version 2>&1 | tee -a "$OUT/00_版.txt"
md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php \
       app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php \
       app/Jobs/*.php 2>&1 | tee -a "$OUT/00_版.txt"
echo "-- 8.1以降の書き方が1つも無いこと --" | tee -a "$OUT/00_版.txt"
grep -nE '(^|[^[:alnum:]_$>])readonly[^[:alnum:]_]|^[[:space:]]*enum[[:space:]]|\)[[:space:]]*:[[:space:]]*never\b|array_is_list[[:space:]]*\(' \
  app/Http/Controllers/Api/AiController.php \
  app/Console/Commands/SummaryAiAnalysisCompensate.php app/Jobs/*.php \
  | grep -vE ':[[:space:]]*(//|\*|#)' | tee -a "$OUT/00_版.txt" \
  || echo "  ○ 1つもありません（コメント内の言及だけです）" | tee -a "$OUT/00_版.txt"
echo "-- 待ち行列の設定 --" | tee -a "$OUT/00_版.txt"
php artisan tinker --execute='
  echo "  既定の接続 : " . config("queue.default") . PHP_EOL;
  echo "  r122       : " . json_encode(config("queue.connections.r122"), JSON_UNESCAPED_SLASHES) . PHP_EOL;
  foreach (["jobs","job_batches","failed_jobs"] as $t) {
    echo sprintf("  %-12s %s（%d 行）", $t,
      \Schema::hasTable($t) ? "あり" : "なし",
      \Schema::hasTable($t) ? \DB::table($t)->count() : 0) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" | tee -a "$OUT/00_版.txt"
echo "-- 旗 --" | tee -a "$OUT/00_版.txt"
grep -E "^BAGANRIKI_" .env | tee -a "$OUT/00_版.txt"
echo "-- 常駐（1枠も起動していないこと）--" | tee -a "$OUT/00_版.txt"
systemctl list-units 'r122-worker@*' --all --no-legend 2>/dev/null | tee -a "$OUT/00_版.txt" || true
echo "  （上が空なら1枠も起動していません）" | tee -a "$OUT/00_版.txt"

echo ""; echo "══ ② 元値の指紋（試験の前）══"
php artisan keiba:ai-analysis-compensate --r122-fingerprint 2>&1 | tee "$OUT/20_指紋_前.txt"
FP_BEFORE="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
if [ -n "${FP_BEFORE:-}" ]; then cp "$FP_BEFORE" "$OUT/20_指紋_前.json"; fi

echo ""; echo "══ ③ 集計2表の全行明細（試験の前。009の④）══"
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    echo "── $n ──" . PHP_EOL;
    echo sprintf("  %4s %12s %8s %12s %12s %28s %28s",
        "順位","平均","件数","期間始","期間終","算出時刻","確認時刻") . PHP_EOL;
    foreach (\DB::table($t)->orderBy("popularity_rank")->get() as $r) {
      echo sprintf("  %4s %12s %8s %12s %12s %28s %28s",
          (string) $r->popularity_rank, (string) $r->odds_average,
          (string) $r->count, substr((string) $r->start_date,0,10),
          substr((string) $r->end_date,0,10),
          (string) ($r->computed_at ?? "（なし）"),
          (string) ($r->verified_at ?? "（なし）")) . PHP_EOL;
    }
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/39_集計2表_前.txt"

echo ""; echo "══ ④ 010の2章  Job構成（J-01〜J-57）══"
php artisan keiba:ai-analysis-compensate --r122-job-test 2>&1 | tee "$OUT/35_Job構成.txt"

echo ""; echo "══ ⑤ 010の3章  型保持（T-01〜T-42／P-01〜P-30）══"
php artisan keiba:ai-analysis-compensate --r122-type-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/37_型保持.txt"
echo "-- 地図なしとの対比だけ抜き出し --"
grep -E "地図なし|地図あり|参考" "$OUT/37_型保持.txt" | tee "$OUT/37b_対比.txt"

echo ""; echo "══ ⑥ 010の5章末尾・009の③  実凍結から一続き（G-01〜G-24）══"
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/38_実凍結から一続き.txt"
echo "-- 期限の幅だけ抜き出し --"
grep -E "G-0[0-9]|G-1[0-9]|G-2[0-4]|発走|情報上限|入力凍結|応答期限|公開期限|投票締切|決め打ち|写し|元に戻" \
  "$OUT/38_実凍結から一続き.txt" | tee "$OUT/38b_期限の幅.txt"

echo ""; echo "══ ⑦ 010の2-4・2-3  採否の確定と期限ぴったり ══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"
grep -E "X-2[0-3]" "$OUT/01_キュー.txt" | tee "$OUT/12b_採否確定.txt"

echo ""; echo "══ ⑧ 010の5章③  帯の順位の保持（E-58e 群）══"
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/16_実経路.txt"
grep -E "E-58|E-11|E-19|E-37|E-52|対象外の帯の突き合わせ" "$OUT/16_実経路.txt" \
  | tee "$OUT/16b_帯の保持と早すぎる公開.txt"

echo ""; echo "══ ⑨ 壊れていないこと（全部）══"
php artisan keiba:ai-analysis-compensate --r122-onebuild-test --date=2026-10-18 2>&1 \
  | tee "$OUT/14_固定入力.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 \
  | tee "$OUT/07_本番入力.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-native-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/09_本体直通.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-market-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/08_OPI.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | tail -4
php artisan keiba:ai-analysis-compensate --r122-sweep 2>&1 | tee "$OUT/13_やり直し.txt" | tail -4
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/19_受入試験_全文.txt" | grep -E "判定件数|合格 |不合格"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "R122-J02|R122-J03|R122-J04|R122-J05|R122-E08" \
  | tee "$OUT/10_厳密JSONと桁.txt" | head -5
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-CL0" \
  | tee "$OUT/06_締切.txt" | head -5

echo ""; echo "══ ⑩ 集計2表の全行明細（試験の後。前と同じはずです）══"
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    echo "── $n ──" . PHP_EOL;
    foreach (\DB::table($t)->orderBy("popularity_rank")->get() as $r) {
      echo sprintf("  %4s %12s %8s %12s %12s %28s %28s",
          (string) $r->popularity_rank, (string) $r->odds_average,
          (string) $r->count, substr((string) $r->start_date,0,10),
          substr((string) $r->end_date,0,10),
          (string) ($r->computed_at ?? "（なし）"),
          (string) ($r->verified_at ?? "（なし）")) . PHP_EOL;
    }
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/39_集計2表_後.txt"
echo "-- 前後の差分（0行なら1つも変わっていません）--"
diff "$OUT/39_集計2表_前.txt" "$OUT/39_集計2表_後.txt" > "$OUT/39b_集計2表_差分.txt" || true
echo "  差分 $(wc -l < "$OUT/39b_集計2表_差分.txt") 行" | tee -a "$OUT/39b_集計2表_差分.txt"

echo ""; echo "══ ⑪ 元値の指紋（試験の後）＝ 前と突き合わせ ══"
if [ -n "${FP_BEFORE:-}" ]; then
  php artisan keiba:ai-analysis-compensate --r122-fingerprint="$FP_BEFORE" 2>&1 \
    | tee "$OUT/21_指紋_後_突き合わせ.txt"
  FP_AFTER="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
  if [ -n "${FP_AFTER:-}" ]; then cp "$FP_AFTER" "$OUT/21_指紋_後.json"; fi
fi

echo ""; echo "══ ⑫ jobs 表に1行も残っていないこと ══"
php artisan tinker --execute='
  foreach (["jobs","job_batches","failed_jobs"] as $t) {
    echo sprintf("  %-12s %d 行", $t, \DB::table($t)->count()) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/36_jobs表の行数.txt"

echo ""; echo "══ ⑬ crontab（読み出しだけ）══"
crontab -l 2>&1 | tee "$OUT/22_crontab.txt" | tail -3
php artisan schedule:list 2>&1 | tee "$OUT/23_schedule.txt" | head -10 || true

echo ""; echo "══ ⑭ 不合格の総まとめ ══"
grep -hE "不合格" "$OUT"/*.txt | sort | uniq -c | sed 's/^/  /'
echo "-- × が出た行（あれば）--"
grep -hE "^\s*×" "$OUT"/*.txt | sort -u | head -40 || echo "  （× はありません）"

tar czf ~/r122_v87_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v87_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v87_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v87_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v87_logs.tgz があります）"
