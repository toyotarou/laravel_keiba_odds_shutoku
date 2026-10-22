#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# 29_server.sh の後始末：zip が無いので tar.gz にする＋指紋のSUMを直す
# ═══════════════════════════════════════════════════════════════════════════
# 29_server.sh の中身は全部成功しています。最後のZIP化だけが
# 「zip: command not found」で落ちました。ここだけやり直します。
# AIへは送りません。DBへの書き込みも0件です。
set -u
APP=/var/www/horse_odds_finder
SRC=~/r122_v23
BK=~/baganriki_20261018

# いちばん新しい提出フォルダを使います
OUT=$(ls -dt "$SRC"/teishutsu_* 2>/dev/null | head -1)
if [ -z "${OUT:-}" ] || [ ! -d "$OUT" ]; then
  echo "提出フォルダが見つかりません: $SRC/teishutsu_*"; exit 1
fi
STAMP=$(basename "$OUT" | sed 's/^teishutsu_//')
echo "対象: $OUT"

echo ""
echo "══ 1. 元値の指紋をやり直す（SUMを多倍長にします）══"
cat > "$OUT/06_元値の指紋/10_指紋_新方式.sql" <<'SQL'
-- 行ごとにMD5を取り、順序に依存しないBIT_XORと、件数の重みが入るSUMで集約します。
-- 連結しないので group_concat_max_len による切り捨てが起きません。
-- NULLは '\N'、区切りは 0x1F（表示されない制御文字）で、空文字と区別します。
-- SUM は DECIMAL(65,0) で取り、指数表記にしません。
SELECT 'odds' t, COUNT(*) rows_n, MIN(id) id_min, MAX(id) id_max,
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(minutes_before_start,'\\N'),
    COALESCE(odds,'\\N'),COALESCE(fuku_min,'\\N'),COALESCE(fuku_max,'\\N'))),1,16),16,10) AS UNSIGNED)))) xor_fp,
  CAST(SUM(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(minutes_before_start,'\\N'),
    COALESCE(odds,'\\N'),COALESCE(fuku_min,'\\N'),COALESCE(fuku_max,'\\N'))),1,15),16,10) AS DECIMAL(65,0))) AS CHAR) sum_fp
FROM t_horse_odds_finder_odds
UNION ALL
SELECT 'horses', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(waku,'\\N'),COALESCE(num,'\\N'),COALESCE(name,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  CAST(SUM(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(waku,'\\N'),COALESCE(num,'\\N'),COALESCE(name,'\\N'))),1,15),16,10) AS DECIMAL(65,0))) AS CHAR)
FROM t_horse_odds_finder_horses
UNION ALL
SELECT 'races', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(start_time,'\\N'),COALESCE(num_horses,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  CAST(SUM(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(start_time,'\\N'),COALESCE(num_horses,'\\N'))),1,15),16,10) AS DECIMAL(65,0))) AS CHAR)
FROM t_horse_odds_finder_races
UNION ALL
SELECT 'race_results', COUNT(*), MIN(id), MAX(id),
  LOWER(HEX(BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(result,'\\N'))),1,16),16,10) AS UNSIGNED)))),
  CAST(SUM(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1F,
    COALESCE(date,'\\N'),COALESCE(kaisuu,'\\N'),COALESCE(basho,'\\N'),COALESCE(day,'\\N'),
    COALESCE(race,'\\N'),COALESCE(num,'\\N'),COALESCE(result,'\\N'))),1,15),16,10) AS DECIMAL(65,0))) AS CHAR)
FROM t_horse_odds_finder_race_results;
SQL
DBP=$(grep -m1 '^DB_PASSWORD=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
DBN=$(grep -m1 '^DB_DATABASE=' $APP/.env | cut -d= -f2- | tr -d '"'"'"' \r')
{ echo "### 新方式（切り捨ての起きない方法。今回からの基準）"
  mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" < "$OUT/06_元値の指紋/10_指紋_新方式.sql" 2>&1 | grep -v '^mysql:'
  echo
  echo "### 従来方式（group_concat_max_len を 1GB に広げたとき）"
  mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" -e \
    "SET SESSION group_concat_max_len=1073741824; SOURCE $OUT/06_元値の指紋/11_指紋_従来方式.sql;" 2>&1 | grep -v '^mysql:'
  echo
  echo "### 従来方式（group_concat_max_len が既定のまま＝10/20提出時と同じ条件）"
  mysql -h 127.0.0.1 -uroot -p"$DBP" "$DBN" -e \
    "SELECT @@session.group_concat_max_len AS group_concat_max_len; SOURCE $OUT/06_元値の指紋/11_指紋_従来方式.sql;" 2>&1 | grep -v '^mysql:'
  echo
  echo "### 10/20提出時の基準値（従来方式・既定のまま）"
  echo "odds         8283  bcba698ed03855c2853df74839b542f8"
  echo "horses        922  16f97810a2bdb18838166a005b50add5"
  echo "races          72  8e8e8ee3b9ff9df5e9b3740a9e68d028"
  echo "race_results  916  8bb127a8b6bb6d46e48d08f5a56c1be6"
} 2>&1 | tee "$OUT/06_元値の指紋/12_結果.txt"

echo ""
echo "══ 2. 切り捨ての事実を書き残す ══"
cat > "$OUT/06_元値の指紋/13_切り捨てについて.md" <<'MD'
# 元値の指紋 ─ 従来方式で切り捨てが起きていました

10月20日004のご指摘（6）「連結長の切捨て確認」は、**当たっていました**。

## 何が起きていたか

従来の `MD5(GROUP_CONCAT(...))` は、`group_concat_max_len`（このサーバの既定値
1024バイト）を超えた分を**警告なしに切り捨て**ます。

今回測った連結長は次のとおりです。

| テーブル | 連結長（バイト） | 既定の上限 | 切り捨て |
| --- | --- | --- | --- |
| odds | 321,153 | 1,024 | 起きていた |
| horses | 43,237 | 1,024 | 起きていた |
| races | 2,306 | 1,024 | 起きていた |
| race_results | 22,804 | 1,024 | 起きていた |

つまり 10/20 に提出した `bcba698e…` 以下の4つの値は、
**各テーブルの先頭およそ1,024バイト分しか見ていませんでした。**
全件・全列の不変を示す値にはなっていませんでした。

## 直したこと

1. 行ごとに MD5 を取り、`BIT_XOR`（順序に依存しない）と
   `SUM`（件数の重みが入る・DECIMAL(65,0)）で集約します。
   連結しないので長さ上限がありません。
2. NULL は `\N`、区切りは `0x1F` にして、NULL と空文字を区別します。
   `CONCAT_WS` の NULL 無視は使いません。
3. 主キー集合を `COUNT(*)`・`MIN(id)`・`MAX(id)` で併記します。
4. 従来方式も、`group_concat_max_len` を 1GB に広げた値と、
   既定のままの値の両方を出し、連結長も併記します。

## 正直に申し上げること

**過去にさかのぼって「全件が不変だった」ことは証明できません。**
これまでの一致は、行数が同じであることと、先頭約1,024バイトが
同じであることしか示していませんでした。

**今回の新方式の値を、ここからの基準とします。**
既存値が改変されたと申し上げているのではありません。
証明の方法が弱かった、ということです。
MD
cat "$OUT/06_元値の指紋/13_切り捨てについて.md" | head -20

echo ""
echo "══ 3. まとめて tar.gz にする（zip が無いので）══"
ARC="$BK/馬眼力_r12.2_是正と現物_${STAMP}.tar.gz"
tar czf "$ARC" -C "$SRC" "teishutsu_${STAMP}"
ls -l "$ARC"
sha256sum "$ARC"
echo ""
echo "中身の件数:"
tar tzf "$ARC" | wc -l
echo ""
echo "Macへ持ってくるには、Macのターミナルでこの1行です（パスはコピペしてください）。"
echo "  scp centos@49.212.166.123:$BK/*是正と現物_${STAMP}.tar.gz ~/Desktop/"
