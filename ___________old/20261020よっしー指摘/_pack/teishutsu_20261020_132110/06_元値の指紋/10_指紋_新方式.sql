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
