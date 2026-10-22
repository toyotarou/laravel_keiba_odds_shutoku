SELECT t.date, t.kaisuu, t.basho, t.day, t.race, t.timing, r.start_time,
  DATE_SUB(TIMESTAMP(r.date, r.start_time),
    INTERVAL (CASE t.timing WHEN 999 THEN 30 WHEN -999 THEN 0 ELSE t.timing END) MINUTE) AS planned_at,
  t.get_datetime AS available_at,
  TIMESTAMPDIFF(SECOND,
    DATE_SUB(TIMESTAMP(r.date, r.start_time),
      INTERVAL (CASE t.timing WHEN 999 THEN 30 WHEN -999 THEN 0 ELSE t.timing END) MINUTE),
    t.get_datetime) AS delay_sec
FROM t_horse_odds_finder_odds_get_timing t
JOIN t_horse_odds_finder_races r
  ON r.date=t.date AND r.kaisuu=t.kaisuu AND r.basho=t.basho AND r.day=t.day AND r.race=t.race
ORDER BY t.date, t.basho, t.race, t.timing;
