-- 元値の列だけを id 昇順で連結し、その md5 を取ります。
-- 行数ではなく中身が動いていないことの基準になります。
SELECT 'odds' t, COUNT(*) rows_n,
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,minutes_before_start,odds,fuku_min,fuku_max) ORDER BY id SEPARATOR ';')) fp
 FROM t_horse_odds_finder_odds
UNION ALL SELECT 'horses', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,waku,num,name) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_horses
UNION ALL SELECT 'races', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,start_time,num_horses) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_races
UNION ALL SELECT 'race_results', COUNT(*),
 MD5(GROUP_CONCAT(CONCAT_WS('|',date,kaisuu,basho,day,race,num,result) ORDER BY id SEPARATOR ';'))
 FROM t_horse_odds_finder_race_results;
