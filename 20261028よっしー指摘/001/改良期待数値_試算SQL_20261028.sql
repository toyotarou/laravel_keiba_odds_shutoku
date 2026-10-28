-- ============================================================================
-- 馬眼力「改良期待数値 v0.1（E3/E5）」試算  2026-10-28（同日 なるさんの確認を受けて改訂）
--   改訂：基準率・時間減衰の除算を DOUBLE で行う（MySQL の DECIMAL 除算は小数4桁に丸まるため）。
--         6分前が NULL 等の通常の欠損を NOT_RUNNER にせず UNAVAILABLE_INPUT とする（NOT_RUNNER は明示的な「取消」のみ）。
--   ※summary に馬の行そのものが無いケースは本SQLでは検知できない。本番の組込みでは凍結した出馬表と全馬照合する。
--   読み取り専用（SELECT のみ）。AI呼び出しなし。テーブル・データは変更しません。
--   資料：馬眼力_改良期待数値_数式と計算例_v0.1_20261025.zip（v0.1.4、SHA-256 b5101dec…）
--   出力：対象期間の 1レース1頭1行 CSV（ヘッダー付き）。対象外・未算出の行も理由つきで出力。
--   実行例（サーバー上）：
--     mysql -u root -p -N -B -r horse_odds_finder < 改良期待数値_試算SQL_20261028.sql > 改良期待数値_試算_20261028.csv
--
-- 【対象期間】prm の f〜t（初期値 2026-10-01〜2026-10-25）
-- 【入力（資料2章）】
--   6分前・21分前単勝：t_horse_odds_finder_summary.odds_tan_before_6 / _21（2026-06-06 以降のみ現存）
--   着順：t_horse_odds_finder_race_result_history.finishing_position
--   芝ダ・距離・クラス：t_horse_odds_finder_race_result_payout（course / dist / grade / race_name）
--   確定オッズ・確定人気・結果は入力特徴に使わない（着順は Y と除外判定にだけ使う）。
-- 【出走頭数 N】凍結時点の出走予定頭数。全頭の6分前オッズを確認し、凍結前取消のみ除外する。
--   6分前の単勝が数値の馬の数。6分前の値が「取消」の馬は凍結前取消として N から除く。
--   6分前の値が数値でも「取消」でもない馬（NULL 等）が1頭でもいれば、そのレースは
--   入力不完全（6分前オッズ欠け）として扱い、残りの頭数で計算しない。
-- 【人気順位 r】6分前単勝の昇順、同値は馬番の昇順（1〜N）。
-- 【class_group】払戻表から固定の対応で作成（LLM 推測なし）：
--   障害・J-グレード・「芝→ダート」→ JUMP（対象外）
--   grade G1/G2/G3/L → そのまま
--   race_name に 新馬・メイクデビュー → NEW、未勝利 → MAIDEN、n勝クラス → Cn、オープン → OP
--   上記に当たらない名前つき特別戦 → UNK（クラス不明。対象馬は未算出、過去レースは近傍候補外）
-- 【基準集団 P】対象日より前・730日以内・同N・同surface・平地。
--   6分前が完全、かつ出走馬全頭の着順が 1 以上で確定しているレース。
--   着順 −1（中止・除外・失格の区別なし）または未確定の馬がいるレースは除外（状態不明）。
-- 【近傍】P のうち 21分前完全・クラス判明・距離ありのレース。D²≦9、w 降順 200 件まで。
--   並び：w 降順、D² 昇順、開催日 降順、場コード・R 昇順。
-- 【定数】parameters.json の初期値：λ=20、h=1、H=365日、尺度 0.35/0.70/0.35/400、class 不一致 1。
--   校正なし（RAW_UNCALIBRATED）。E = 100 × p_raw。
-- 【support_status】資料6章：M<100、近傍件数<20、W<10、n_eff<15 のいずれかで LOW_SUPPORT。
-- 【評価対象】対象レースのうち、出走馬全頭の着順が確定（1以上）のレースのみ。
--   着順 −1・未確定の馬が1頭でもいるレースは、レース単位で評価から除外する。
-- ※数値は DOUBLE で計算し、出力は小数6桁（E は小数1桁、表示用）。
-- ============================================================================
WITH prm AS (
 SELECT DATE('2026-10-01') f, DATE('2026-10-25') t
), s0 AS (
 SELECT DATE(s.date) d, s.basho, s.race, s.num, s.odds_tan_before_6 raw6,
  IF(s.odds_tan_before_6 REGEXP '^[0-9]+([.][0-9])?$' AND CAST(s.odds_tan_before_6 AS DECIMAL(10,1))>=1.0, CAST(s.odds_tan_before_6 AS DOUBLE), NULL) o6,
  IF(s.odds_tan_before_21 REGEXP '^[0-9]+([.][0-9])?$' AND CAST(s.odds_tan_before_21 AS DECIMAL(10,1))>=1.0, CAST(s.odds_tan_before_21 AS DOUBLE), NULL) o21,
  h.finishing_position pos
 FROM t_horse_odds_finder_summary s
 LEFT JOIN t_horse_odds_finder_race_result_history h ON h.date=DATE(s.date) AND h.basho_code=s.basho AND h.race=s.race AND h.num=s.num
 WHERE DATE(s.date) <= (SELECT t FROM prm)
), rc AS (
 SELECT d,basho,race, COUNT(*) n_rows, SUM(o6 IS NOT NULL) N,
  SUM(o6 IS NULL AND IFNULL(raw6,'')<>'取消') miss6,
  SUM(o6 IS NOT NULL AND o21 IS NULL) miss21,
  SUM(o6 IS NOT NULL AND (pos IS NULL OR pos=0)) pend,
  SUM(o6 IS NOT NULL AND pos<0) m1
 FROM s0 GROUP BY d,basho,race
), ri AS (
 SELECT rc.*, p.dist,
  CASE WHEN p.course='芝' THEN 'TURF' WHEN p.course='ダート' THEN 'DIRT' ELSE 'OTHER' END surface,
  CASE WHEN p.date IS NULL THEN 'NOPAY'
   WHEN p.race_name LIKE '%障害%' OR p.grade LIKE 'J-%' OR p.course='芝→ダート' THEN 'JUMP'
   WHEN p.grade IN ('G1','G2','G3','L') THEN p.grade
   WHEN p.race_name LIKE '%新馬%' OR p.race_name LIKE '%メイクデビュー%' THEN 'NEW'
   WHEN p.race_name LIKE '%未勝利%' THEN 'MAIDEN'
   WHEN p.race_name REGEXP '[1１]勝クラス' THEN 'C1' WHEN p.race_name REGEXP '[2２]勝クラス' THEN 'C2' WHEN p.race_name REGEXP '[3３]勝クラス' THEN 'C3'
   WHEN p.race_name LIKE '%オープン%' THEN 'OP' ELSE 'UNK' END cg
 FROM rc LEFT JOIN t_horse_odds_finder_race_result_payout p ON p.date=rc.d AND p.basho_code=rc.basho AND p.race=rc.race
), rf AS (
 SELECT ri.*,
  (surface IN ('TURF','DIRT') AND cg NOT IN ('JUMP','NOPAY') AND miss6=0 AND pend=0 AND m1=0 AND N>=2) base_ok,
  (surface IN ('TURF','DIRT') AND cg NOT IN ('JUMP','NOPAY','UNK') AND miss6=0 AND pend=0 AND m1=0 AND miss21=0 AND dist>0 AND N>=2) nb_ok,
  (d >= (SELECT f FROM prm) AND surface IN ('TURF','DIRT') AND cg NOT IN ('JUMP','NOPAY','UNK') AND miss6=0 AND miss21=0 AND dist>0 AND N>=2) tgt_ok,
  (pend=0 AND m1=0) eval_ok,
  CASE WHEN cg='NOPAY' THEN '払戻表なし（芝ダ・距離・クラス不明）'
   WHEN cg='JUMP' OR surface='OTHER' THEN '対象外（障害など）'
   WHEN miss6>0 THEN '6分前オッズ欠け'
   WHEN miss21>0 THEN '21分前オッズ欠け'
   WHEN cg='UNK' THEN 'クラス不明（名前つき特別戦）'
   WHEN NOT dist>0 THEN '距離なし' WHEN N<2 THEN '出走2頭未満' ELSE '' END in_reason
 FROM ri
), hr AS (
 SELECT s0.d,s0.basho,s0.race,s0.num,s0.o6,s0.o21,s0.pos,
  ROW_NUMBER() OVER (PARTITION BY s0.d,s0.basho,s0.race ORDER BY s0.o6, s0.num) r,
  (s0.pos BETWEEN 1 AND 3) y3, (s0.pos BETWEEN 1 AND 5) y5,
  IF(s0.o21 IS NULL, NULL, LN(s0.o21/s0.o6)) mv
 FROM s0 WHERE s0.o6 IS NOT NULL
), qq AS (
 SELECT d,basho,race,r l, LN(LEAD(o6) OVER (PARTITION BY d,basho,race ORDER BY r)/o6) q FROM hr
), pr AS (
 SELECT c.d cd,c.basho cb,c.race cr, j.d jd,j.basho jb,j.race jr, c.N, CAST(DATEDIFF(c.d,j.d) AS DOUBLE) age, j.nb_ok,
  POW(CAST(c.dist-j.dist AS DOUBLE)/400.0,2) + (c.cg<>j.cg) dd
 FROM rf c JOIN rf j ON j.N=c.N AND j.surface=c.surface AND j.base_ok=1 AND j.d<c.d AND j.d>=c.d - INTERVAL 730 DAY
 WHERE c.tgt_ok=1
), prof AS (
 SELECT pr.cd,pr.cb,pr.cr,pr.jd,pr.jb,pr.jr, MAX(pr.age) age, MAX(pr.dd) dd, SUM(POW(qc.q-qj.q,2))/(MAX(pr.N)-1)/POW(0.35,2) dp
 FROM pr JOIN qq qc ON qc.d=pr.cd AND qc.basho=pr.cb AND qc.race=pr.cr AND qc.q IS NOT NULL
 JOIN qq qj ON qj.d=pr.jd AND qj.basho=pr.jb AND qj.race=pr.jr AND qj.l=qc.l AND qj.q IS NOT NULL
 WHERE pr.nb_ok=1 GROUP BY pr.cd,pr.cb,pr.cr,pr.jd,pr.jb,pr.jr
), bs AS (
 SELECT pr.cd,pr.cb,pr.cr,hj.r, COUNT(*) M, SUM(hj.y3) B3, SUM(hj.y5) B5
 FROM pr JOIN hr hj ON hj.d=pr.jd AND hj.basho=pr.jb AND hj.race=pr.jr GROUP BY pr.cd,pr.cb,pr.cr,hj.r
), nb AS (
 SELECT hc.d cd,hc.basho cb,hc.race cr,hc.r, pf.jd,pf.jb,pf.jr, hj.y3,hj.y5,
  (pf.dp + POW(LN(hc.o6/hj.o6)/0.70,2) + POW((hc.mv-hj.mv)/0.35,2) + pf.dd)/5 D2, pf.age
 FROM prof pf JOIN hr hc ON hc.d=pf.cd AND hc.basho=pf.cb AND hc.race=pf.cr
 JOIN hr hj ON hj.d=pf.jd AND hj.basho=pf.jb AND hj.race=pf.jr AND hj.r=hc.r
), nr AS (
 SELECT nb.*, EXP(-D2/2)*POW(2,-age/365.0) w,
  ROW_NUMBER() OVER (PARTITION BY cd,cb,cr,r ORDER BY EXP(-D2/2)*POW(2,-age/365.0) DESC, D2, jd DESC, jb, jr) rn FROM nb WHERE D2<=9
), ag AS (
 SELECT cd,cb,cr,r, COUNT(*) cnt, SUM(w) W, SUM(w*y3) A3, SUM(w*y5) A5, SUM(w*w) W2 FROM nr WHERE rn<=200 GROUP BY cd,cb,cr,r
), fin AS (
 SELECT hc.d,hc.basho,hc.race,hc.num,hc.r,hc.y3,hc.y5, c.eval_ok,
  bs.M, bs.B3, bs.B5, (CAST(bs.B3 AS DOUBLE)+1.0)/(CAST(bs.M AS DOUBLE)+2.0) p03, (CAST(bs.B5 AS DOUBLE)+1.0)/(CAST(bs.M AS DOUBLE)+2.0) p05,
  IFNULL(ag.cnt,0) cnt, IFNULL(ag.W,0) W, IFNULL(ag.A3,0) A3, IFNULL(ag.A5,0) A5,
  IF(IFNULL(ag.W,0)>0, ag.W*ag.W/ag.W2, 0) neff,
  (IFNULL(ag.A3,0)+20*(CAST(bs.B3 AS DOUBLE)+1.0)/(CAST(bs.M AS DOUBLE)+2.0))/(IFNULL(ag.W,0)+20) p3,
  (IFNULL(ag.A5,0)+20*(CAST(bs.B5 AS DOUBLE)+1.0)/(CAST(bs.M AS DOUBLE)+2.0))/(IFNULL(ag.W,0)+20) p5
 FROM hr hc JOIN rf c ON c.d=hc.d AND c.basho=hc.basho AND c.race=hc.race AND c.tgt_ok=1
 LEFT JOIN bs ON bs.cd=hc.d AND bs.cb=hc.basho AND bs.cr=hc.race AND bs.r=hc.r
 LEFT JOIN ag ON ag.cd=hc.d AND ag.cb=hc.basho AND ag.cr=hc.race AND ag.r=hc.r
), st AS (
 SELECT fin.*, CASE WHEN M IS NULL THEN 'UNAVAILABLE_BASELINE' WHEN W=0 THEN 'BASELINE_ONLY'
  WHEN M<100 OR cnt<20 OR W<10 OR neff<15 THEN 'LOW_SUPPORT' ELSE 'COMPARISON_READY' END ss FROM fin
)
SELECT x FROM (
 SELECT 0 o, NULL d, '' b, 0 rr, 0 nn,
  'race_id,date,basho_code,race,num,odds_6min,odds_21min,popularity_rank_6min,N,surface,distance,class_group,support_status,unavailable_reason,M,B3,B5,p0_3,p0_5,neighbour_count,W,A3,A5,n_eff,local_weight_share,p3_raw,p5_raw,E3,E5,eval_target,eval_excluded_reason,finishing_position,Y3,Y5' x
 UNION ALL
 SELECT 1, s0.d, s0.basho, s0.race, s0.num, CONCAT_WS(',',
  CONCAT(s0.d,'_',s0.basho,'_',LPAD(s0.race,2,'0')), s0.d, s0.basho, s0.race, s0.num,
  IFNULL(s0.raw6,''), IFNULL(s0.o21,''), IFNULL(hr.r,''), rf.N, rf.surface, IFNULL(rf.dist,''), rf.cg,
  CASE WHEN s0.o6 IS NULL AND IFNULL(s0.raw6,'')='取消' THEN 'NOT_RUNNER' WHEN s0.o6 IS NULL THEN 'UNAVAILABLE_INPUT' WHEN rf.tgt_ok=1 THEN st.ss
       WHEN rf.cg='JUMP' OR rf.surface='OTHER' THEN 'OUT_OF_SCOPE' ELSE 'UNAVAILABLE_INPUT' END,
  CASE WHEN s0.o6 IS NULL THEN IF(IFNULL(s0.raw6,'')='取消','凍結前取消（6分前「取消」）','6分前オッズなし') ELSE rf.in_reason END,
  IFNULL(st.M,''), IFNULL(st.B3,''), IFNULL(st.B5,''), IFNULL(ROUND(st.p03,6),''), IFNULL(ROUND(st.p05,6),''),
  IFNULL(st.cnt,''), IFNULL(ROUND(st.W,6),''), IFNULL(ROUND(st.A3,6),''), IFNULL(ROUND(st.A5,6),''), IFNULL(ROUND(st.neff,6),''),
  IFNULL(ROUND(st.W/(st.W+20),6),''), IFNULL(ROUND(st.p3,6),''), IFNULL(ROUND(st.p5,6),''),
  IFNULL(ROUND(st.p3*100,1),''), IFNULL(ROUND(st.p5*100,1),''),
  IF(st.d IS NOT NULL AND st.eval_ok=1,1,0),
  CASE WHEN st.d IS NULL THEN '' WHEN rf.m1>0 THEN '着順−1の馬あり（レース単位で除外）' WHEN rf.pend>0 THEN '着順未確定の馬あり（レース単位で除外）' ELSE '' END,
  IFNULL(s0.pos,''), IFNULL(hr.y3,''), IFNULL(hr.y5,'')) x
 FROM s0 JOIN rf ON rf.d=s0.d AND rf.basho=s0.basho AND rf.race=s0.race
 LEFT JOIN hr ON hr.d=s0.d AND hr.basho=s0.basho AND hr.race=s0.race AND hr.num=s0.num
 LEFT JOIN st ON st.d=s0.d AND st.basho=s0.basho AND st.race=s0.race AND st.num=s0.num
 WHERE s0.d >= (SELECT f FROM prm)
) z ORDER BY o, d, b, rr, nn;
