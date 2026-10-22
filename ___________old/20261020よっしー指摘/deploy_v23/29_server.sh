#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# サーバ側：是正の確認と、現物入りの提出物を作る（v23）
# ═══════════════════════════════════════════════════════════════════════════
# AIへは1回も送りません。DBへ残る書き込みも0件です（凍結保存は --dry）。
set -u
APP=/var/www/horse_odds_finder
SRC=~/r122_v23
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$SRC/teishutsu_$STAMP"
mkdir -p "$OUT"/{01_コード,02_設置した本文とSchema,03_生成した入力,04_試験ログ,05_説明,06_元値の指紋}
say(){ echo ""; echo "══ $* ══"; }

say "0. 控えと置き換え"
mkdir -p "$BK/before_v23_$STAMP"
for f in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Console/Commands/ImportKeibaOdds.php ; do
  cp -p "$APP/$f" "$BK/before_v23_$STAMP/"
done
cp "$SRC/code/AiController.php"                "$APP/app/Http/Controllers/Api/AiController.php"
cp "$SRC/code/SummaryAiAnalysisCompensate.php" "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$SRC/code/ImportKeibaOdds.php"             "$APP/app/Console/Commands/ImportKeibaOdds.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" \
                    "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
                    "$APP/app/Console/Commands/ImportKeibaOdds.php" 2>/dev/null || true
for f in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Console/Commands/ImportKeibaOdds.php ; do php -l "$APP/$f"; done
cd "$APP" && php artisan config:clear >/dev/null 2>&1 || true

say "1. 資材と本文"
rm -rf "$SRC/mat"; mkdir -p "$SRC/mat"; tar xzf "$SRC/r122_material.tgz" -C "$SRC/mat"
mkdir -p "$APP/storage/app/baganriki/prompts/r12_2"
cp "$SRC/prompts/"*.txt "$APP/storage/app/baganriki/prompts/r12_2/"
chown -R apache:apache "$APP/storage/app/baganriki" 2>/dev/null || true

# ── 提出物 01 コード ──────────────────────────────────────────────
cp -p "$APP/app/Http/Controllers/Api/AiController.php" \
      "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
      "$APP/app/Console/Commands/ImportKeibaOdds.php" \
      "$APP/routes/api.php" "$OUT/01_コード/"
{ echo "# 本番で動いているファイル（$(date '+%Y-%m-%d %H:%M:%S %Z')）"; echo
  cd "$APP" && md5sum app/Http/Controllers/Api/AiController.php \
    app/Console/Commands/SummaryAiAnalysisCompensate.php \
    app/Console/Commands/ImportKeibaOdds.php routes/api.php
  echo; sha256sum app/Http/Controllers/Api/AiController.php \
    app/Console/Commands/SummaryAiAnalysisCompensate.php \
    app/Console/Commands/ImportKeibaOdds.php routes/api.php
  echo; wc -l app/Http/Controllers/Api/AiController.php \
    app/Console/Commands/SummaryAiAnalysisCompensate.php \
    app/Console/Commands/ImportKeibaOdds.php routes/api.php
} > "$OUT/01_コード/00_版とハッシュ.txt"
{ echo "# 今回の変更箇所（アンカー別の行番号）"; echo
  for a in R122-PROMPT R122-AUDIT R122-X6 R122-INVALID R122-FREEZE R122-MODE R122-QUOTA R122-ALLOC R122-EXACT REFACTOR ; do
    echo "## @ANCHOR-20261020-$a"
    grep -n "@ANCHOR-20261020-$a" "$APP/app/Http/Controllers/Api/AiController.php" \
         "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
         "$APP/app/Console/Commands/ImportKeibaOdds.php" 2>/dev/null | head -40
    echo
  done
} > "$OUT/01_コード/01_変更箇所のアンカー.txt"
diff -u "$BK/before_v23_$STAMP/ImportKeibaOdds.php" \
        "$APP/app/Console/Commands/ImportKeibaOdds.php" \
        > "$OUT/01_コード/02_ImportKeibaOdds_差分.txt" 2>&1 || true

# ── 提出物 02 設置した本文とSchema ────────────────────────────────
cp -p "$APP/storage/app/baganriki/prompts/r12_2/"*.txt "$OUT/02_設置した本文とSchema/"
cp -p "$SRC/mat/schemas/"*.json "$OUT/02_設置した本文とSchema/"
cp -p "$SRC/mat/config/"*.json  "$OUT/02_設置した本文とSchema/" 2>/dev/null || true
{ echo "# 実際に設置し、実際に system へ載せる本文の完全なSHA-256"; echo
  sha256sum "$APP/storage/app/baganriki/prompts/r12_2/"*.txt
  echo; echo "# 同梱Schema"; sha256sum "$SRC/mat/schemas/"*.json
  echo; echo "# 配布ファイルとの一致（BOM無し・全バイト）"
  for f in prompt_1st.txt prompt_2nd.txt common_prompt.txt ; do
    a=$(sha256sum "$APP/storage/app/baganriki/prompts/r12_2/$f" | awk '{print $1}')
    b=$(sha256sum "$SRC/mat/prompts/$f" | awk '{print $1}')
    [ "$a" = "$b" ] && echo "  一致 $f  $a" || echo "  不一致 $f  設置=$a 配布=$b"
  done
  echo; echo "# 呼び出し経路"
  grep -n "BaganrikiPrompt::systemBody\|BaganrikiPrompt::assemble\|r122AssembleSend\|BAGANRIKI_R122_PROMPT" \
    "$APP/app/Http/Controllers/Api/AiController.php" | head -30
} > "$OUT/02_設置した本文とSchema/00_完全ハッシュと呼出経路.txt"

say "2. 送信文の検査（§1〜§5）＋ 生成した入力の書き出し"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-prompt-check="$SRC/mat" \
  --r122-prompt-dump="$OUT/03_生成した入力" 2>&1 \
  | tee "$OUT/04_試験ログ/01_送信文の検査.txt"
{ echo "# 生成した INPUT_JSON の snapshot_hash と、先方の参照入力の値"; echo
  for f in "$OUT/03_生成した入力"/*_input.json ; do
    [ -e "$f" ] || continue
    n=$(basename "$f" _input.json)
    g=$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['snapshot_hash'])" "$f" 2>/dev/null)
    k=$(python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))))" "$f" 2>/dev/null)
    r="$SRC/mat/validation/normal/${n}_input.json"
    e=$(python3 -c "import json,sys;print(json.load(open(sys.argv[1]))['snapshot_hash'])" "$r" 2>/dev/null)
    [ "$g" = "$e" ] && s="一致" || s="不一致"
    printf "%-30s キー数%2s  %s  得た値 %s / 期待値 %s\n" "$n" "$k" "$s" "$g" "$e"
  done
} > "$OUT/03_生成した入力/00_snapshot_hashと件数.txt" 2>&1
cat "$OUT/03_生成した入力/00_snapshot_hashと件数.txt"

say "3. 異常例32本（入力23・出力9）"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-invalid-cases="$SRC/mat" 2>&1 | tee "$OUT/04_試験ログ/02_異常例32本.txt"

say "4. 受入試験（全項目）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test-detail \
  > "$OUT/04_試験ログ/03_受入試験_全項目.txt" 2>&1 || true
cd "$APP" && php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/04_試験ログ/04_受入試験_要約.txt" | grep -E "判定件数|合格|不合格"

say "5. 合成試験 729件＋4件"
cd "$APP" && php artisan keiba:ai-analysis-compensate \
  --r122-cases="$SRC/mat/validation" 2>&1 | tee "$OUT/04_試験ログ/05_合成試験.txt" | tail -8

say "6. 72レースの突き合わせ（X1〜X5・Z。X6は含みません）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --mode-check --r122-exact-check \
  --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/04_試験ログ/06_72レース突き合わせ.txt" | tail -14
cp -p "$APP/storage/logs/mode_check_20261017_20261018.csv"      "$OUT/04_試験ログ/07_レース別.csv" 2>/dev/null || true
cp -p "$APP/storage/logs/mode_check_odds_20261017_20261018.csv" "$OUT/04_試験ログ/08_各馬のオッズ.csv" 2>/dev/null || true

say "7. 取得監査の記録の確認（書き込み0件）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --r122-audit-check \
  --date=2026-10-17,2026-10-18 2>&1 | tee "$OUT/04_試験ログ/09_取得監査の確認.txt" | tail -12

say "8. 凍結入力の保存（試すだけ・1行も書きません）"
cd "$APP" && php artisan keiba:ai-analysis-compensate --r122-freeze-dry \
  --date=2026-10-18 2>&1 | tee "$OUT/04_試験ログ/10_凍結保存_試行.txt" | tail -12
cd "$APP" && php artisan keiba:ai-analysis-compensate --r122-freeze-dry \
  --r122-prompt-assume --r122-prompt-sales-offset=60 \
  --date=2026-10-18 2>&1 | tee "$OUT/04_試験ログ/11_凍結保存_仮置き試行.txt" | tail -12

say "9. 元値の指紋（切り捨ての起きない方法＋従来方法）"
cat > "$OUT/06_元値の指紋/10_指紋_新方式.sql" <<'SQL'
-- 行ごとにMD5を取り、順序に依存しないBIT_XORと、件数の重みが入るSUMで集約します。
-- 連結しないので group_concat_max_len による切り捨てが起きません。
-- NULLは '\N'、区切りは 0x1F（表示されない制御文字）で、空文字と区別します。
SELECT 'odds' t, COUNT(*) rows_n, MIN(id) id_min, MAX(id) id_max,
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(minutes_before_start,'\\N'),
    COALESCE(odds,'\\N'),COALESCE(fuku_min,'\\N'),COALESCE(fuku_max,'\\N'))),1,16),16,10) AS UNSIGNED)))) xor_fp,
  SUM(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(minutes_before_start,'\\N'),
    COALESCE(odds,'\\N'),COALESCE(fuku_min,'\\N'),COALESCE(fuku_max,'\\N'))),1,15),16,10)) sum_fp
FROM t_horse_odds_finder_odds
UNION ALL
SELECT 'horses', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(waku,'\\N'),COALESCE(num,'\\N'),COALESCE(name,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  SUM(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(waku,'\\N'),COALESCE(num,'\\N'),COALESCE(name,'\\N'))),1,15),16,10))
FROM t_horse_odds_finder_horses
UNION ALL
SELECT 'races', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(start_time,'\\N'),COALESCE(num_horses,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  SUM(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(start_time,'\\N'),COALESCE(num_horses,'\\N'))),1,15),16,10))
FROM t_horse_odds_finder_races
UNION ALL
SELECT 'race_results', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(result,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  SUM(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(result,'\\N'))),1,15),16,10))
FROM t_horse_odds_finder_race_results;
SQL
cat > "$OUT/06_元値の指紋/11_指紋_従来方式.sql" <<'SQL'
SELECT @@session.group_concat_max_len AS group_concat_max_len;
SELECT 'odds' t, COUNT(*) rows_n,
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,minutes_before_start,odds,fuku_min,fuku_max) ORDER BY id SEPARATOR ';')) fp,
 LENGTH(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,minutes_before_start,odds,fuku_min,fuku_max) ORDER BY id SEPARATOR ';')) concat_len
 FROM t_horse_odds_finder_odds
UNION ALL SELECT 'horses', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,waku,num,name) ORDER BY id SEPARATOR ';')),
 LENGTH(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,waku,num,name) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_horses
UNION ALL SELECT 'races', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,start_time,num_horses) ORDER BY id SEPARATOR ';')),
 LENGTH(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,start_time,num_horses) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_races
UNION ALL SELECT 'race_results', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,result) ORDER BY id SEPARATOR ';')),
 LENGTH(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,result) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_race_results;
SQL
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
{ echo "### 新方式（切り捨ての起きない方法。今回からの基準）"
  mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/06_元値の指紋/10_指紋_新方式.sql" 2>&1
  echo; echo "### 従来方式（10/20提出時と同じSQL。連結長も併記）"
  mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" -e "SET SESSION group_concat_max_len=1073741824; SOURCE $OUT/06_元値の指紋/11_指紋_従来方式.sql;" 2>&1
  echo; echo "### 10/20提出時の基準値（従来方式・group_concat_max_len は既定のまま）"
  echo "odds         8283  bcba698ed03855c2853df74839b542f8"
  echo "horses        922  16f97810a2bdb18838166a005b50add5"
  echo "races          72  8e8e8ee3b9ff9df5e9b3740a9e68d028"
  echo "race_results  916  8bb127a8b6bb6d46e48d08f5a56c1be6"
} 2>&1 | tee "$OUT/06_元値の指紋/12_結果.txt"

say "10. 説明の文書"
cp -p "$SRC/docs/"*.md "$OUT/05_説明/"

say "11. まとめてZIPにする"
cat > "$OUT/00_README.txt" <<TXT
馬眼力 r12.2　10月20日004のご指摘への対応（現物つき）
作成：$(date '+%Y-%m-%d %H:%M:%S %Z')　実装担当

01_コード              本番で動いているPHPそのもの、版とハッシュ、変更箇所のアンカー、差分
02_設置した本文とSchema 実際に設置した両AI本文3本とSchema、完全なSHA-256、呼び出し経路
03_生成した入力         こちらが実際に組み立てた INPUT_JSON 14本と snapshot_hash の照合
04_試験ログ             送信文の検査／異常例32本／受入試験の全項目／合成試験／72レース／取得監査／凍結保存
05_説明                 前回ご指摘ごとの対応表／追加材料7区分の接続表／工程表
06_元値の指紋           切り捨ての起きない新方式と、従来方式（連結長つき）

この実行でAIは1回も呼んでいません。DBへ残る書き込みも0件です。
TXT
cd "$SRC" && zip -qr "$BK/馬眼力_r12.2_是正と現物_$STAMP.zip" "teishutsu_$STAMP"
ls -l "$BK/馬眼力_r12.2_是正と現物_$STAMP.zip"
sha256sum "$BK/馬眼力_r12.2_是正と現物_$STAMP.zip"
echo ""
echo "このZIPをMacへ持ってくるには、Macのターミナルで次の1行です。"
echo "  scp centos@49.212.166.123:'$BK/馬眼力_r12.2_是正と現物_$STAMP.zip' ~/Desktop/"
