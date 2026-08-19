CREATE TABLE `t_horse_odds_finder_phase_pattern_recovery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phase_pattern` varchar(30) NOT NULL COMMENT 'フェーズパターン（例：前半下落・後半上昇）',
  `half1_label` varchar(10) NOT NULL COMMENT '前半フェーズ方向',
  `half2_label` varchar(10) NOT NULL COMMENT '後半フェーズ方向',
  `half1_order` tinyint DEFAULT NULL COMMENT '前半ソート順（1=下落,2=横ばい,3=上昇）',
  `half2_order` tinyint DEFAULT NULL COMMENT '後半ソート順（1=下落,2=横ばい,3=上昇）',
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
  UNIQUE KEY `uq_phase_popularity` (`phase_pattern`, `popularity_band`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='前半・後半フェーズ方向パターン×人気帯別の単勝回収率';
