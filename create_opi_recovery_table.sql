CREATE TABLE `t_horse_odds_finder_opi_recovery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `opi_band` varchar(30) NOT NULL COMMENT 'OPI帯（例：1.50以上、0.70未満）',
  `opi_min` decimal(6,2) DEFAULT NULL COMMENT 'OPI帯の下限',
  `opi_max` decimal(6,2) DEFAULT NULL COMMENT 'OPI帯の上限',
  `popularity_band` varchar(20) NOT NULL COMMENT '人気帯（例：1〜3人気）',
  `popularity_min` int DEFAULT NULL COMMENT '人気帯の最小人気順位',
  `popularity_max` int DEFAULT NULL COMMENT '人気帯の最大人気順位',
  `sample_count` int DEFAULT NULL COMMENT '集計サンプル数',
  `win_count` int DEFAULT NULL COMMENT '1着回数',
  `win_rate` decimal(6,2) DEFAULT NULL COMMENT '勝率（%）',
  `recovery_rate` decimal(8,2) DEFAULT NULL COMMENT '単勝回収率（%）',
  `start_date` date DEFAULT NULL COMMENT '集計対象期間（開始）',
  `end_date` date DEFAULT NULL COMMENT '集計対象期間（終了）',
  `computed_at` datetime DEFAULT NULL COMMENT '最終集計日時',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opi_popularity` (`opi_band`, `popularity_band`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='OPI帯×人気帯別の単勝回収率';
