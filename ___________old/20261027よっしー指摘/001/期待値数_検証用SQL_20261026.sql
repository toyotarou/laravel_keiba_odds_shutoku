-- ============================================================================
-- 馬眼力「期待値数」再検証（確定オッズによる基礎検証）  2026-10-26（2026-10-27 改訂）
--   改訂内容：入力チェックの厳格化（値全体の数値判定・NULL・1倍未満・複勝下限＞上限）、学習開始日の明記、境界の説明を用途別に記載。
--   2026-10-27 再改訂：入力を整数または小数1桁に限定、回収率の除外定義の文言を修正。
--   改訂前後で出力CSVは同一（現DBに該当する行が0件のため）。
--   読み取り専用（SELECT のみ）。テーブル・データは変更しません。
--   出力：検証期間（2026-01-01〜2026-10-25）の 1レース1頭1行 CSV（ヘッダー付き）
--   実行例（サーバー上）：
--     mysql -u root -p -N -B -r horse_odds_finder < 期待値数_検証用SQL_20261026.sql > 期待値数_検証_確定オッズ_20261026.csv
--
-- 【条件】
--   学習期間：2023-01-01 〜 2025-12-31 ／ 検証期間：2026-01-01 〜 2026-10-25（現DBの最古日は 2023-01-05）
--   Y（的中）：t_horse_odds_finder_race_result_payout.fuku（複勝払戻表）に馬番がある＝1。
--             同着で払戻対象が4頭のレースもそのまま。着順・頭数で上書きしない。
--   pay（実払戻倍率）：払戻表の円額 ÷ 100（100円あたりの払戻 ÷ 100）。
--   掲載頭数（n_listed）：結果表に載っている全頭数（取消・除外・中止を含む）。
--   状態区分：
--     対象            … 着順1以上・単勝/複勝オッズあり・払戻表あり
--     状態不明        … 着順 −1（DBで中止・除外・失格を区別していない）。本集計から除外
--     対象外（各理由）… 払戻表未取得・オッズ未取得など
--   区間の境界（用途別）：
--     単勝帯・中間値帯・頭数帯 … 下端以上・上端未満
--     期待値の成績区分（報告書の表）… 下端より大きい・上端以下
--     購入対象 … 丸め前の期待値が 0 より大きい（ちょうど 0 は対象外）
--   回収率の定義：本集計は「対象」行のみで計算し、状態不明・対象外の行は購入額・払戻額の両方から除外する。
--                 （除外は返還が確定したという意味ではない。状態不明には中止＝外れ、データ欠損も含み得る）
--   入力チェック：単勝・複勝下限・上限は整数または小数1桁（^[0-9]+([.][0-9])?$）、1.0以上、複勝下限≦上限。満たさない行は対象外。
--                 （小数2桁以上の値を DECIMAL(10,1) に丸めてから比較しないよう、正規表現で先に除外する）
--
-- 【旧方式（サーバーの現行複勝期待値スコア）】
--   P_old ＝ 学習期間の「FLOOR(確定単勝) 帯」ごとの 3着以内率（1.0〜199.9倍、30件以上の帯のみ）
--   O_old ＝ 複勝中間値 (下限+上限)/2
-- 【新方式（補正後複勝期待値数）】
--   P_new ＝ 学習期間の「単勝帯 × 頭数帯」ごとの複勝的中率（Y の平均）
--            単勝帯：20倍未満は1倍刻み（FLOOR）、20以上30未満=20、30以上50未満=30、50以上100未満=50、100以上=100
--            頭数帯：掲載7頭以下=1、8〜11=2、12〜15=3、16以上=4
--            セル件数 50 未満のときは、単勝帯のみの率で代用（代替理由を出力）
--   O_new ＝ 複勝中間値 × 補正係数
--            補正係数 ＝ 学習期間の的中馬について Σ実払戻倍率 ÷ Σ複勝中間値（中間値帯ごと）
--            中間値帯：1.5未満=1、1.5〜2=2、2〜3=3、3〜4=4、4〜6=5、6〜8=6、8〜12=7、12以上=8
--   期待値数 ＝ 100 × (P × O − 1)。購入対象は丸める前の値が 0 より大きいもの。
--   表示値は四捨五入（小数第1位で四捨五入、0から遠い方へ）。表示だけで判定しない。
-- ※MySQL の AVG は小数4桁（div_precision_increment=4）で計算されます。P はこの値を使用。
-- ※補正係数は内部精度（DECIMAL の合計の比）のまま計算に使用。CSV の corr 列は小数6桁に丸めた表示値。
--   6分前照合（別CSV）は小数6桁の係数で計算しています（合計の比に戻してもプラス判定の変化は0件）。
-- ============================================================================
WITH h0 AS (
  SELECT date, basho_code, race, num, name, tan, fuku_min, fuku_max,
         finishing_position AS pos, popularity_rank AS pr,
         COUNT(*) OVER (PARTITION BY date, basho_code, race) AS n_listed,
         SUM(tan IS NULL OR NOT tan REGEXP '^[0-9]+([.][0-9])?$') OVER (PARTITION BY date, basho_code, race) AS n_notan
  FROM t_horse_odds_finder_race_result_history
  WHERE date >= '2023-01-01' AND date <= '2026-10-25'
), pp AS (
  SELECT date, basho_code, race, SUBSTRING_INDEX(SUBSTRING_INDEX(fuku, '/', n.k), '/', -1) AS pair
  FROM t_horse_odds_finder_race_result_payout
  JOIN (SELECT 1 k UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) n
  WHERE fuku LIKE '%|%' AND (LENGTH(fuku) - LENGTH(REPLACE(fuku, '/', ''))) + 1 >= n.k
), pq AS (
  SELECT date, basho_code, race, CAST(SUBSTRING_INDEX(pair, '|', 1) AS UNSIGNED) AS num,
         MAX(CAST(REPLACE(SUBSTRING_INDEX(pair, '|', -1), ',', '') AS UNSIGNED)) / 100 AS pay
  FROM pp GROUP BY date, basho_code, race, num
), r AS (
  SELECT date, basho_code, race, COUNT(*) AS n_pay FROM pq GROUP BY date, basho_code, race
), b AS (
  SELECT h0.*, r.n_pay, pq.pay AS pay_raw, (h0.date >= '2026-01-01') AS is_test,
         CASE WHEN r.date IS NULL THEN 'X1'
              WHEN (h0.tan IS NULL OR NOT h0.tan REGEXP '^[0-9]+([.][0-9])?$') AND h0.n_notan = h0.n_listed THEN 'X2a'
              WHEN h0.tan IS NULL OR NOT h0.tan REGEXP '^[0-9]+([.][0-9])?$' THEN 'X2b'
              WHEN h0.fuku_min IS NULL OR h0.fuku_max IS NULL
                   OR NOT h0.fuku_min REGEXP '^[0-9]+([.][0-9])?$'
                   OR NOT h0.fuku_max REGEXP '^[0-9]+([.][0-9])?$' THEN 'X3'
              WHEN CAST(h0.tan AS DECIMAL(10,1)) < 1.0 OR CAST(h0.fuku_min AS DECIMAL(10,1)) < 1.0
                   OR CAST(h0.fuku_min AS DECIMAL(10,1)) > CAST(h0.fuku_max AS DECIMAL(10,1)) THEN 'X3'
              WHEN h0.pos IS NULL OR h0.pos = 0 THEN 'X4'
              WHEN h0.pos < 0 THEN 'X5'
              ELSE 'OK' END AS st
  FROM h0
  LEFT JOIN r  ON r.date = h0.date AND r.basho_code = h0.basho_code AND r.race = h0.race
  LEFT JOIN pq ON pq.date = h0.date AND pq.basho_code = h0.basho_code AND pq.race = h0.race AND pq.num = h0.num
), d AS (
  SELECT b.*,
         IF(b.tan REGEXP '^[0-9]+([.][0-9])?$', CAST(b.tan AS DECIMAL(10,1)), NULL) AS t,
         IF(b.fuku_min REGEXP '^[0-9]+([.][0-9])?$' AND b.fuku_max REGEXP '^[0-9]+([.][0-9])?$',
            (CAST(b.fuku_min AS DECIMAL(10,1)) + CAST(b.fuku_max AS DECIMAL(10,1))) / 2, NULL) AS mid,
         IFNULL(b.pay_raw, 0) AS pay, (b.pay_raw IS NOT NULL) AS y, (b.pos BETWEEN 1 AND 3) AS y3,
         CASE WHEN b.n_listed <= 7 THEN 1 WHEN b.n_listed <= 11 THEN 2 WHEN b.n_listed <= 15 THEN 3 ELSE 4 END AS fg
  FROM b
), d2 AS (
  SELECT d.*,
         CASE WHEN t IS NULL THEN NULL WHEN t < 20 THEN FLOOR(t) WHEN t < 30 THEN 20 WHEN t < 50 THEN 30 WHEN t < 100 THEN 50 ELSE 100 END AS tb,
         CASE WHEN mid IS NULL THEN NULL WHEN mid < 1.5 THEN 1 WHEN mid < 2 THEN 2 WHEN mid < 3 THEN 3 WHEN mid < 4 THEN 4
              WHEN mid < 6 THEN 5 WHEN mid < 8 THEN 6 WHEN mid < 12 THEN 7 ELSE 8 END AS mb
  FROM d
), tr AS (
  SELECT * FROM d2 WHERE is_test = 0 AND st = 'OK'
), ob AS (
  SELECT FLOOR(t) AS ofl, COUNT(*) AS n, SUM(y3) AS h, AVG(y3) AS p FROM tr
  WHERE t BETWEEN 1.0 AND 199.9 GROUP BY ofl HAVING COUNT(*) >= 30
), n2 AS (
  SELECT tb, fg, COUNT(*) AS n, SUM(y) AS h, AVG(y) AS p FROM tr GROUP BY tb, fg
), n1 AS (
  SELECT tb, COUNT(*) AS n, SUM(y) AS h, AVG(y) AS p FROM tr GROUP BY tb
), c AS (
  SELECT mb, COUNT(*) AS n, SUM(pay) AS s_pay, SUM(mid) AS s_mid, SUM(pay) / SUM(mid) AS cr FROM tr WHERE y = 1 GROUP BY mb
), e AS (
  SELECT d2.*,
         IF(d2.t BETWEEN 1.0 AND 199.9, ob.p, NULL) AS p_old,
         IF(n2.n >= 50, n2.p, n1.p) AS p_new,
         n2.n AS cell_n, n2.h AS cell_h,
         CASE WHEN d2.tb IS NULL THEN NULL WHEN n2.n >= 50 THEN '' ELSE CONCAT('セル件数', IFNULL(n2.n, 0), '<50のため単勝帯のみの率') END AS p_alt,
         c.cr AS cr, d2.mid * c.cr AS o_new
  FROM d2
  LEFT JOIN ob ON ob.ofl = FLOOR(d2.t)
  LEFT JOIN n1 ON n1.tb = d2.tb
  LEFT JOIN n2 ON n2.tb = d2.tb AND n2.fg = d2.fg
  LEFT JOIN c  ON c.mb = d2.mb
  WHERE d2.is_test = 1
), f AS (
  SELECT e.*,
         100 * (p_old * mid - 1)   AS ev_old,
         100 * (p_new * o_new - 1) AS ev_new
  FROM e
)
SELECT x FROM (
  SELECT 0 AS ord, '' AS k1, 0 AS k2, 0 AS k3,
    'race_id,date,basho_code,race,num,horse_name,status,refund_class,excluded_reason,odds_basis,tan_odds,fuku_min,fuku_max,fuku_mid,popularity,listed_horses,fuku_rule_est,fuku_paid_count,p_old,p_old_band,p_new,p_new_cell,cell_n,cell_hits,p_alt_reason,mid_band,corr,o_new,ev_old_raw,ev_new_raw,ev_new_disp,buy_new,buy_old,y,pay' AS x
  UNION ALL
  SELECT 1, DATE_FORMAT(date, '%Y-%m-%d'), CAST(basho_code AS UNSIGNED), race * 100 + num,
    CONCAT_WS(',',
      CONCAT(DATE_FORMAT(date, '%Y-%m-%d'), '_', basho_code, '_', LPAD(race, 2, '0')),
      DATE_FORMAT(date, '%Y-%m-%d'), basho_code, race, num, REPLACE(IFNULL(name, ''), ',', ' '),
      CASE st WHEN 'OK' THEN '対象' WHEN 'X5' THEN '状態不明' ELSE '対象外' END,
      CASE st WHEN 'OK' THEN '通常' WHEN 'X5' THEN '不明（中止＝外れ／除外＝返還の区別なし）' WHEN 'X2b' THEN '返還の可能性（未確認）' ELSE '不明' END,
      CASE st WHEN 'OK' THEN '' WHEN 'X5' THEN '着順−1（中止・除外・失格の区別なし）' WHEN 'X2b' THEN 'この馬の単勝オッズなし（取消・除外等）'
              WHEN 'X2a' THEN 'レース全体で単勝オッズ未取得' WHEN 'X1' THEN '払戻表未取得' WHEN 'X3' THEN '複勝オッズなし・不正値' ELSE '着順未取得' END,
      '確定オッズ（結果ページ）',
      IFNULL(t, ''), IFNULL(fuku_min, ''), IFNULL(fuku_max, ''), IFNULL(mid, ''), IFNULL(pr, ''),
      n_listed, IF(n_listed >= 8, '3着まで', '2着まで'), IFNULL(n_pay, ''),
      IFNULL(p_old, ''), IFNULL(IF(t BETWEEN 1.0 AND 199.9, FLOOR(t), NULL), ''),
      IFNULL(p_new, ''), IFNULL(CONCAT(tb, '/', fg), ''), IFNULL(cell_n, ''), IFNULL(cell_h, ''), IFNULL(p_alt, ''),
      IFNULL(mb, ''), IFNULL(ROUND(cr, 6), ''), IFNULL(ROUND(o_new, 6), ''),
      IFNULL(ROUND(ev_old, 6), ''), IFNULL(ROUND(ev_new, 6), ''), IFNULL(ROUND(ev_new, 0), ''),
      IF(st = 'OK' AND ev_new > 0, 1, 0), IF(st = 'OK' AND ev_old > 0, 1, 0),
      IF(st IN ('OK', 'X5'), y, ''), IF(st IN ('OK', 'X5'), pay, '')
    )
  FROM f
) z
ORDER BY ord, k1, k2, k3;
