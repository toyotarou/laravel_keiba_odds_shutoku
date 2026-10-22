# 馬眼力 詳細設計書

文書版：1.3  
作成日：2026年10月19日（文書版1.0）  
更新日：2026年10月20日（文書版1.1・実装担当が追記）  
更新日：2026年10月21日（文書版1.2・実装担当が追記と差替え）  
更新日：2026年10月21日（文書版1.3・10月21日008のご指摘を反映。取り下げた説明を整理）  
対象：r12.2、Laravel＋Flutter、既存MySQL、Laravel Queue  
対応文書：変更計画書 文書版1.2、基本設計書 文書版1.2

> **文書版1.2の変更のしかたについて（10月20日006のご指摘7への回答）**
>
> 前回「追記以外は1文字も変更していない」と書きましたが、厳密には違いました。
> 版表示と末尾の実装対応表には差替えがありました。申し訳ありません。
> 今回は**追記と差替えを分けて**書きます。
>
> | 印 | 意味 | どこに出るか |
> |---|---|---|
> | 【実装追記 2026-10-20】 | 文書版1.1で足した段落。中身は変えていない | 本文の各章 |
> | 【実装追記 2026-10-21】 | 文書版1.2で新しく足した段落 | 第6章・第8章・第10章・第13章 |
> | 【差替え 2026-10-21】 | **文書版1.1の追記のうち、現物と合わなくなったので書き直した段落**。差替え前の文もその場に残す | 第6章・第8章・第13章・第14章 |
| 【実装追記 2026-10-21b】 | 文書版1.3で新しく足した段落 | 第4章・第6章・第7章・第8章・第10章・第11章・第12章 |
| 【取り下げ 2026-10-21b】 | **文書版1.2で書いた説明のうち、10月21日008のご指摘を受けて取り下げた段落**。取り下げ前の文もその場に残す | 第3章・第14章 |
>
> 差替えたのは**すべて文書版1.1で実装担当が足した「実装追記」の段落だけ**です。
> 文書版1.0の本文（正本の仕様）は、版表示を除いて1文字も変えていません。
> 採用方針①〜③も再選択していません。
>
> 差替えの理由は1つだけです。文書版1.1で「未実装」と書いた3項目を実装し、
> 新しく1項目（実送信の後への接続）を実装したので、**「未実装」と書いたままでは
> 文書が現物と合わない**ためです。差替えの一覧は別紙
> `06_設計書の差替え箇所と追記箇所.md` にまとめました。
>
> 追記には**関数名・アンカー注釈・試験ID**を添えました。添えられないものは
> いまも「未実装」「未測定」と書いています。

## 1 実装の指定と変更範囲

①X6はBを採用する。②6分前＋30秒、その他の予想入力スロット＋120秒を採用する。③不明がX6だけで正式modeがまたがる場合、S0＝1はMIXED、S0＝3はVOLATILEを配分用に採用する。実装構成はLaravel＋Flutterに固定する。

本書のクラス、新規テーブル、APIは今回の実装指定である。既存の実クラス・物理列・モデルIDを確認済みという意味ではない。既存との命名差は末尾の対応表で管理し、外部動作を変更しない。旧r12.1の根拠、採点、局所回復契約は明示変更した箇所以外を維持する。

## 2 版と設定

| キー | 指定値 |
| --- | --- |
| implementation_version | `baganriki-v041-r12-2-20261020` |
| prompt_version | `baganriki-v041-r12-2-20261020` |
| schema_version | `baganriki-v041-r12-2` |
| time_contract.version | `baganriki-post-slot-grace-v1` |
| time_contract.anchor_kind | `POST_TIME` |
| time_contract.snapshot_offsets | `[21,18,15,12,9,6]` |
| time_contract.sampling_policy_id | `SLOT_FIRST_VALID_COMMITTED_V1` |
| 6分前の取得猶予 | 30,000ミリ秒 |
| 21・18・15・12・9分前の取得猶予 | 120,000ミリ秒 |
| safety_margin_ms | 60,000 |
| 公開前の統合時間 | 30,000ミリ秒 |
| 通常mode_policy_id | `BASE_TRI_STATE_V1` |
| 暫定mode_policy_id | `X6_ONLY_UPPER_MODE_V1` |
| learning_stage / model_version | `COLLECT_ONLY` / `null` |
| buy_recommended | `false` |
| role / attempt_no | `1st`または`2nd` / 初回`0`、訂正`1`のみ |

【実装追記 2026-10-20】**実装で追加した設定**。すべて `.env` で切り替わる。10月20日時点の実値も併記する。

| キー | 意味 | 現在値 |
| --- | --- | --- |
| `BAGANRIKI_R122_PROMPT` | 本文の段。`0`＝旧本文／`shadow`＝入力を組んで凍結・記録するが送らない／`1`＝r12.2の本文で送る | `shadow` |
| `BAGANRIKI_R122_QUEUE` | キューの段。`0`＝off／`shadow`＝記録のみ／`1`＝契約どおり止める | `0` |
| `BAGANRIKI_R122_AUDIT` | 取得監査の記録 | `1` |
| `BAGANRIKI_SALES_CLOSE_DERIVE` | 投票締切を発走時刻から導出するか | `1` |
| `BAGANRIKI_SALES_CLOSE_OFFSET_SEC` | 締切の前倒し秒数 | `60` |
| `BAGANRIKI_PAST_RUNS_MAX` | 1頭あたり過去走を何走まで載せるか。`0`で載せない | `5` |
| `BAGANRIKI_MARKET_PRIOR` | OPI・推定確定オッズを載せるか。`0`で載せない | 既定`1` |

| キー | 指定値 |
| --- | --- |
| `sales_close_at` の正本 | `T − 60秒`（JRA公式「当日発売のインターネット投票は各レース発走時刻の1分前」から導出） |
| 出どころの名前 | `JRA_INTERNET_1MIN_BEFORE_POST` |
| `snapshot_hash_version` | `exact-common-payload-sha256-r8`（維持） |
| 診断の版 | `r122-diagnose-v1`（`BaganrikiDiagnose::VERSION`） |
| 指紋の方式ID | `per-row-length-prefixed-sha256-ordered-by-pk-v1` |
| REPLAYの世代 | `generation=900`（本番は `0`） |

BEGIN/ENDは `<<<BAGANRIKI_V041_R8_BEGIN>>>` と `<<<BAGANRIKI_V041_R8_END>>>` を維持する。hash方式は `exact-common-payload-sha256-r8` を維持し、共通JSONに追加した今回の項目もhash対象にする。実装、設定、Schema、両AI本文、API、Flutterを同じリリース単位で切り替える。

## 3 Laravelの構成

| 指定クラス | 主な処理 |
| --- | --- |
| RaceSourceRepository | 対象レース、出馬表、宣言頭数、正本時刻、オッズを読む。 |
| AcquisitionPolicy | 取得監査を検証し、スロットごとの採用バッチを選ぶ。 |
| SnapshotService | 入力照合、凍結集合、共通JSON、hashを一度だけ保存する。 |
| SignalCalculator | X1〜X6・Z、Sの可能値、正式modeを計算する。 |
| AllocationPolicy | 暫定例外、目標頭数、構造調整、role別配分を決める。 |
| EvidenceValidator / ResponseValidator | 出典、正確な数値、応答全体と馬別項目を検証する。 |
| CorrectionService / RankingService | 局所訂正、正常部分保持、帯別順位を処理する。 |
| RoleWorkerService | 永続予約、既存AIクライアント呼出し、原応答保存を処理する。 |
| PublicationService | 保存済み応答から統合し、期限とrevisionを再確認して公開する。 |
| LiveStateService / PublicView | 取消と締切を原子的に更新し、現在状態を表示DTOへ反映する。 |

Queue Jobは `FreezeRaceJob`、role別の `RunRoleJob`、`AggregateRaceJob`、`UpdateRaceLiveJob` とする。取得の既存スケジューラから凍結処理を起動する。既存互換クラスを残す場合も、内部はLaravelサービスへ委譲する。

旧 `_trimAiCandidates`、`_mergeAiResults`、旧2nd入力再構成、旧brain追記、旧analysis_textの正規表現へ新結果を戻さない。接続箇所は行番号ではなく関数と呼出経路で特定する。

【実装追記 2026-10-20】**指定クラスと実装の対応**。**新しいファイルは作らず、すべて `app/Http/Controllers/Api/AiController.php` の中に置いた**（二重管理を避けるため）。PSR-4では `AiController.php` 内のクラスを直接autoloadできないので、呼び出し側は先に `class_exists(AiController::class)` で親ファイルを読ませる。

| 指定クラス | 実装 | 主な関数 |
| --- | --- | --- |
| RaceSourceRepository | `AiController::r122GatherContext()` | 出馬表・馬名・オッズ・取消・監査の読込 |
| AcquisitionPolicy | `BaganrikiTime` / `BaganrikiAcquisition` | `acceptBatch()`・`pickBatch()`・`usableDecimal()` |
| SnapshotService | `BaganrikiSnapshot` / `AiController::r122Freeze()` | `frozenRank()`・`scale()`・凍結保存 |
| SignalCalculator | `BaganrikiSignals` | `v04Pairs()`・`v04DeriveFlags()`・`v04Classify()` |
| AllocationPolicy | `BaganrikiAllocation` | `v04TargetCount()`・`v04Allocation()`・`v04StructuralAdjust()` |
| EvidenceValidator / ResponseValidator | `BaganrikiResponse` / `BaganrikiDiagnose` | `extract()`・`checkEnvelope()`・`isolateHorses()`・`run()` |
| CorrectionService / RankingService | `AiController::r122ResolveCorrection()` / `BaganrikiRanking` | `acceptCorrection()`・`applyCorrection()`・`merge()`・`afterCancellation()` |
| RoleWorkerService | `BaganrikiWorker` | `reserve()`・`allowSend()`・`receive()`・`fail()`・`resume()` |
| PublicationService | `AiController::r122Publish()` | intent・revision・期限の再検査 |
| LiveStateService / PublicView | `BaganrikiWorker::ensureLive()` / 読取API | ライブ状態と表示DTO |
| 入力の組み立てと本文 | `BaganrikiPrompt` | `buildInput()`・`assemble()`・`canonical()`・`coverageOf()` |

【差替え 2026-10-21】**Queue Jobについて。**上の文書版1.1の追記は、最後の1文が誤解を招くので差し替える。

> 差替え前（文書版1.1）の文はこうだった。
> 「【実装追記 2026-10-20】**Queue Jobについて**。`BAGANRIKI_R122_QUEUE` は現在 `0`（off）で、Jobクラスは作っていない。永続予約・訂正上限・重複送信の防止・再起動の後始末は `BaganrikiWorker` に実装済みで、試験 Q-01〜Q-44・W-01〜W-09・S-01〜S-14（計67項目）で確認している。旗を `1` にすれば契約どおり止める。」

10月20日006のご指摘6-4は正しかった。「旗を `1` にすれば契約どおり止める」と書いたが、**止めるところまでしか繋がっていなかった。**原応答を保存したあと、検証・訂正・公開を始める呼び出しがどこにも無かった。試験Commandからしか呼んでいなかった。

いまの状態をそのまま書く。

【取り下げ 2026-10-21b】**上の「作らないことを選んだ」という説明を取り下げる。**

> 取り下げ前（文書版1.2）の文はこうだった。
> 「**Jobクラスはいまも作っていない。作らないことを選んだ。**理由は時間である。応答期限（発走150秒前）から公開期限（発走120秒前）まで30秒しかない。別プロセスへ渡して結果を待つ余裕がないため、HTTPリクエストの中で同期に通す。……**この点は正本との差なので、ご判断をいただきたい。**」

10月21日008のご指摘のとおり、設計変更の根拠として不足していた。入力凍結（発走330秒前）から応答期限（発走150秒前）までは180秒あり、AI送信と訂正はこの区間に属する。30秒しかないのは応答期限から公開期限までであって、送信の区間ではない。Job方式の取り出し待ちの実測も、同時開催・障害時の比較も提示していない。

**正本（第10章）の Job 構成を維持する。**`FreezeRaceJob`、role別の `RunRoleJob`、`AggregateRaceJob`、`UpdateRaceLiveJob` である。

**ただし、Jobクラスはまだ実装していない。**「方針を維持する」ことと「実装が完成している」ことは別である。いまの実装は、同じ順序をHTTPリクエストの中と巡回コマンドの中で通している。これは**暫定であり、正本との差である。**10月24日・25日は非公開の検証にとどめ、この差を恒久的な設計変更にはしない。

**接続は実装した。**呼出経路は次の1本だけである。

| 何 | どこ |
| --- | --- |
| 実ファイル | `app/Http/Controllers/Api/AiController.php` |
| 呼出元① | `getHorseOddsFinderAiAnalysis()`。1st の原応答を保存した直後 |
| 呼出元② | `getHorseOddsFinderSecondAiOpinion()`。2nd の原応答を保存した直後 |
| 入口 | `r122AfterReceive()` |
| 順序 | `r122InspectResponse()` → （必要なら）`r122SendFix()` → `r122AcceptedHorses()` → `r122PublishWhenReady()` |
| 1st の動くとき | `app()->terminating()`。画面へ応答を返したあと |
| 2nd の動くとき | その場（2nd は先読みか利用者の操作の中なので、待たせる画面が無い） |
| Job | **未実装**（正本の Job 構成は維持する。いまはHTTPと巡回で同じ順序を通している暫定） |
| 初回のやり直し | `r122SendFirst()`。巡回とキャッシュ返却後の後始末の両方から呼ぶ |
| 止まったときのやり直し | `r122SweepPending()`。`--r122-sweep` で呼ぶ |

試験 X-01〜X-30（30項目）。うち X-24〜X-30 は `ReflectionClass` で本番関数の行範囲を取り、その中に呼び出しがあるかを見る試験である。「呼出経路を提出してください」というご指摘に、文章ではなく試験で答えられるようにした。

**旗の食い違いを設定で作れないようにした。**`@ANCHOR-20261020-R122-WIRE`／`r122WireConfig()`。`BAGANRIKI_R122_PROMPT=1` のときに `BAGANRIKI_R122_QUEUE=0` だと、本文だけ r12.2 で枠・期限・公開が旧のままになる。この組み合わせでは `QUEUE_REQUIRED` を記録して**1st AIを呼ばない**。`QUEUE=shadow` は進めるが、契約10章の停止条件が効いていないことをログに残す。試験 X-01〜X-04。

**実AIはいまも一度も呼んでいない。**訂正を実際に送る経路（`r122CallAi()`）は書いたが、通っていない。試験で確かめたのは「送る手前で断る3つの経路」（初回が未完了・時間が足りない・枠が既にある）と「依頼本文の中身」までである。実送信は10月24日・25日の非公開検証でしか測れない。

## 4 DB入力と取得監査

### 4.1 既存データとの境界

対象日の正本レース一覧を先に取得する。各レースを必ず結果集合へ残し、オッズ全欠損の日を`continue`で読み飛ばさない。「開催なし」「レース中止」「対象レースのデータ欠損」は別状態にする。

既存オッズの `num`、`minutes_before_start`、`odds`、`fuku_min`、`fuku_max` はそれぞれ馬番、スロット、単勝、複勝最小、複勝最大へ対応づける。実テーブル名と出馬表・レース情報の列は対応表へ記録する。文字列「取消」は正式取消として分離し、数値へ変換しない。

`get_datetime`は今回の提出資料では保存完了時刻である。取得元更新時刻や応答受信時刻として流用しない。過去に無かった時刻は推定で作らない。

【実装追記 2026-10-20】**実テーブルと列**。スキーマは `horse_odds_finder`（MySQL 8.4.9）。

| 用途 | 実テーブル | 使う列 |
| --- | --- | --- |
| 対象レース一覧 | `t_horse_odds_finder_races` | `date`・`kaisuu`・`basho`・`day`・`race`・`start_time` |
| 出馬表 | `t_horse_odds_finder_horses` | `num`・`name` |
| オッズ | `t_horse_odds_finder_odds` | `num`・`minutes_before_start`・`odds`・`fuku_min`・`fuku_max` |
| 過去走 | `t_horse_odds_finder_shutsuba_history` | 馬名で引き、`date <` 対象日のみ |
| 過去平均単勝 | `t_horse_odds_finder_popularity_rank_average` | `popularity_rank`・`odds_average`・`count`・`start_date`・`end_date` |
| 過去平均複勝最小 | `t_horse_odds_finder_fuku_popularity_rank_average` | 同上 |
| 補正係数 | `t_horse_odds_finder_compute_odds_correction` | `avg_correction_ratio`・`std_correction_ratio`・`sample_count`・`start_date`・`end_date`・`computed_at` |
| 回収率 | `t_horse_odds_finder_odds_gap_recovery` | `gap_band`・`gap_min`・`gap_max`・`popularity_min`・`popularity_max`・`recovery_rate`・`win_rate`・`sample_count`・`start_date`・`end_date`・`computed_at` |
| 過去類似成績 | `t_horse_odds_finder_similar_race_stats` | `popularity_rank`・`horse_count_band`・`top3_rate`・`top5_rate`・`avg_finishing_position`・`outside_rate`・`sample_count`・`reliability`・`updated_at`（集計期間の列は**無い**） |

`レース情報`・`出馬表`・`オッズ` の3表は毎週土曜5:50に `keiba:deleteKeibaTableRecords` でTRUNCATEされる。**上記の集計5表はTRUNCATE対象に入っていない。**

【実装追記 2026-10-20】**集計の更新時刻**。集計を作るcronは全部夜であり、材料が増えるのも夜（`keiba:summary` 20:10／`importRaceResultHistory` 20:30）である。したがって**レース時間帯（9:00〜17:00）に `end_date` がその日になることは構造的に起きない**。

| 集計 | cron | 曜日 |
| --- | --- | --- |
| 過去平均単勝 | 21:10 | 土曜以外 |
| 補正係数 | 21:20 | 毎日 |
| 過去平均複勝最小 | 22:00 | 毎日 |
| 回収率 | 22:20 | 毎日 |
| 過去類似成績 | 23:10 | 毎日 |

### 4.2 取得バッチの項目

| 項目 | 型と意味 |
| --- | --- |
| race_id / slot / batch_id | 正本のレース識別子、分数、同一応答バッチの一意ID |
| slot_scheduled_at | 発走時刻からslot分を引いた予定時刻 |
| source_updated_at | 取得元が示す更新時刻。取得できなければnull |
| request_started_at | 実際に要求を開始した時刻 |
| response_received_at | バッチ全体を受信し終えた時刻 |
| available_at | 保存commit後、予想処理から読み出せると確認した時刻 |
| raw_payload / payload_hash | 原値を保持した本文と照合用hash |
| validation_result / rejection_reason | 採用可能性と、不採用の場合の理由 |

監査行の`available_at`を事前の予定時刻で埋めない。原データをcommitしてから利用可能を確認し、確定した監査行を読めるようにしてから採用対象にする。未確定の監査行は採用しない。

### 4.3 時刻式と採用条件

T＝正本の発走時刻、s＝スロット分数、g(s)＝6なら30秒、それ以外なら120秒とする。

```text
slot_scheduled_at = T − s分
slot_deadline     = slot_scheduled_at ＋ g(s)
cutoff_at         = T − 360秒
input_freeze_at   = cutoff_at ＋ 30秒
```

各採用バッチは以下を全て満たす。

1. `request_started_at >= slot_scheduled_at`。
2. `response_received_at >= request_started_at`。
3. `available_at >= response_received_at`。
4. `available_at <= slot_deadline`かつ`available_at <= input_freeze_at`。
5. レース・slot・馬番・値の型と重複を検証済み。取得元時刻が存在する場合は受信時刻より未来ではない。

期限と同時刻は許容し、1マイクロ秒でも後なら不採用。予想に使うスロットは21・18・15・12・9・6のみ。3分前・発走時の数値オッズは信号へ入れず、取消の監視・事後監査には読む。

採用可能バッチを `(available_at, response_received_at, batch_id)` の昇順で並べ、最初の1件を採用する。時刻は実時刻、batch_idの同値比較は固定のバイト順とする。6分前は同一バッチに全活動馬の有効単勝があることを追加条件にする。別バッチの馬を混ぜて完成させない。採用IDはsnapshotに固定する。

`frozen_at`は実際のsnapshot保存時刻で、情報上限`input_freeze_at`とは別。情報上限に達してから固定し、それ以降に到着した入力を混ぜない。処理が遅れても情報上限と公開期限を延長しない。

【実装追記 2026-10-20】**正本との意味的一致を6番目の条件として追加した**（10月20日005のご指摘2-2・7-4）。発走時刻が渡されているとき、申告された `slot_scheduled_at` が `T − s分` と一致しなければ `SCHEDULE_MISMATCH`、申告された `input_freeze_at` が `T − 330秒` と一致しなければ `FREEZE_MISMATCH` で不採用にする。「RFC3339の形を検査する」ことと「正本anchorとの意味的一致を検査する」ことを分けた。理由文には申告値と正本値の両方を書く。`@ANCHOR-20261020-R122-ANCHORMATCH`、試験 N-05〜N-07。

【実装追記 2026-10-20】**日時の繰上がりを拒否するようにした**。`DateTimeImmutable::createFromFormat` は `2026-02-30` を3月2日へ、`25:00` を翌日01:00へ繰り上げて `false` を返さない。作り直した日付・時刻を元の文字列と突き合わせ、警告・注意も見る。`@ANCHOR-20261020-R122-ROLLOVER`、試験 N-08〜N-14（2月30日・13月・25時・61分・平年の2月29日を拒否、うるう年の2月29日とマイクロ秒つきは通す）。

【実装追記 2026-10-20】**DB形式との変換**。監査行は `DATETIME(6)`（`2026-10-18 15:04:00.123456`）で保存されるため、マイクロ秒を保ったままRFC3339へ変換する関数を別に用意した（`r122DbTime()` / `BaganrikiWorker::dbUs()`）。**RFC3339しか受け付けない `BaganrikiTime::us()` の検査は緩めていない。**

【実装追記 2026-10-20】**採用バッチの原値を使う**（10月20日005のご指摘2-3）。実監査行があるときは、採用した `batch_id` の `raw_payload` から復元した値（`$batchCells`）だけを入力に使い、共通のオッズテーブルからは読まない。取消情報も採用バッチのものを使う。`payload_hash` を再計算して保存hashと照合する。`@ANCHOR-20261020-R122-ACQFIX`、試験 `--r122-acq-test`（13項目。「6分前の登録の値が採用バッチの原値と全頭一致」を含む）。

【実装追記 2026-10-21b】**取得バッチの採用単位。**（10月21日008のご指摘4）

6分前の全頭確認は、**その候補自身の取消だけ**を引いた活動集合で行う。commit済み・時刻適合の候補全部の取消を合算した集合を共用してはならない。合算すると、別の候補が持つ取消で欠損が隠れ、取消を持たない候補が「全頭そろった」として採用される。採用した候補にはその取消が無いので、採用判定と凍結集合が食い違う。

> 例：出馬表が1・2・3番。A（先に利用可能）は1・2番の単勝があり、3番は単勝も取消も無い。B（後に利用可能）は1・2番の単勝と3番の正式取消がある。両方 commit済み・時刻適合。
> 合算するとAが通る。**正しくはAが落ち、Bが採用される。**

実装は `BaganrikiAcquisition::pick()`（`@ANCHOR-20261021-R122-PERCAND`）。引数の活動集合は「正本として確定済みの取消を除いた基礎集合」であり、各候補の取消はこの関数の中で引く。試験 N-30〜N-41。

### 4.4 集合と頭数

| 項目 | 定義 |
| --- | --- |
| entry_count | 正本出馬表の頭数。欠損時はnull |
| acquired_count | 読み込めた対象馬の一意件数。正常出走頭数の代用にしない |
| frozen_count / N | 入力上限までの正式取消を除いた凍結出走集合の頭数 |
| active_count | 凍結集合から最新の累積取消を除いた頭数。集合矛盾時はnull |

出馬表は必須。宣言10頭・出馬表欠落・オッズ9頭なら計算不能であり、9頭立てとして進めない。馬番不明、矛盾する重複、取消状態の矛盾は診断を残す。取消矛盾と馬キー欠落が同時にあっても先のエラーで頭数の意味を取り違えない。

凍結順位は正本の活動馬1〜Nで重複なく、6分前単勝が非減少であることを検査する。同値の正本順位は維持する。固定後の順位詰め直しは禁止する。

### 4.5 出典とcoverage

`past_runs`、`OPI`、`estimated_odds`、`recovery_statistics`、`similar_statistics`、`legacy_qualifications`、`gap_history`の7区分を必須とする。利用済みは`INCLUDED`と参照配列、不足は`UNAVAILABLE`と実際の理由を記録する。

source_registryのscope、対象馬、SIGNAL／CONTEXT、派生依存関係、値、時刻を検証する。今回、情報上限の比較を`cutoff_at`から`input_freeze_at`へ明示変更し、スロットの「予定以前」検査を4.3の取得猶予検査へ置き換える。親出典の利用可能時刻を遡らせず、派生計算の`computed_at`は実計算時刻を保持する。計算時刻と原情報の利用可能時刻を混同しない。

【実装追記 2026-10-20】**7区分の接続状況と、使っていいかの判定**。時刻はA案（真正な取得・算出記録）を採った。**無い時刻は作らない。**出典日付を一律00:00へ置き換えるB案は採っていない。

| 区分 | 実装 | 10月18日のレースでの状態 |
| --- | --- | --- |
| `gap_history` | `r122ExtraRegistry()` | INCLUDED（13〜15件） |
| `past_runs` | `r122PastRunsRegistry()` | INCLUDED（204〜269件） |
| `OPI` | `r122MarketPriorRegistry()` | UNAVAILABLE（集計期間が当日を含む） |
| `estimated_odds` | 同上 | UNAVAILABLE（同） |
| `recovery_statistics` | `r122ExtraRegistry()` | UNAVAILABLE（同） |
| `similar_statistics` | 同上 | UNAVAILABLE（算出が情報上限より後） |
| `legacy_qualifications` | 使わない | UNAVAILABLE（旧資格はr12.2では使わない） |

**使っていいかは2つを重ねて判定する。**
1. 集計期間の終わり（`end_date`）が**対象レースの開催日より前**であること。集計元の `end_date` は `MAX(date)`、つまりその日のレースを含むため、当日を含む集計でその日のレースを評価しない。
2. 算出時刻（`computed_at`／`updated_at`）が**情報上限より前**であること。

②の列が無い表は①だけで見る。①の列が無い表（過去類似成績）は②だけで見る。**どちらも無い時刻を作って埋めることはしない。**読み取った時刻は `observed_at`／`available_at` に入れ、集計期間は `start_date`／`end_date` を別項目として渡す。載せられない区分は**理由つきの `UNAVAILABLE`** を返す。

【実装追記 2026-10-20】**回収率にも①の判定を当てた**。回収率の表は `start_date`／`end_date` を持っているのに、算出時刻しか見ていなかった。OPI・推定確定オッズと同じ判定を当て、使った集計期間を `gaprec_start_date`／`gaprec_end_date` として登録に残すようにした。`@ANCHOR-20261020-R122-COVWIN`。

【実装追記 2026-10-20】**過去走の情報上限**。`t_horse_odds_finder_shutsuba_history` に発走時刻の列が無いため、`observed_at` には「その行を手に入れた時刻」（`created_at` と `updated_at` の遅いほう）を入れる。**レースの発走時刻を流用して作ることはしない。**対象日より前の日付の行だけを引く。1頭あたりの走数は `BAGANRIKI_PAST_RUNS_MAX`（既定5）で制限する。生の8項目は OBSERVED、まとめの3項目は DERIVED で親を指す。

【差替え 2026-10-21】**実装した。**上の文書版1.1の追記は、いまの現物と合わなくなったので差し替える。

> 差替え前（文書版1.1）の文はこうだった。
> 「【実装追記 2026-10-20】**未実装**。参照IDの実在・所有馬・SIGNAL／CONTEXTの**検査**は未実装である。各馬の事実出典hashは `r122FieldSources()` で記録しているが、入力の `source_registry` に実在するかの照合はしていない。採点の再計算も未実装（辞書は正本どおり `BaganrikiDiagnose::SCORE_CAP` に保持）。」

参照IDの意味検証を実装した。`BaganrikiDiagnose::run()` の中で、応答のどの階層にある `source_ref`／`source_refs` も `collectRefs()` で再帰的に拾い、3つを見る。`@ANCHOR-20261020-R122-REFCHECK`。

1. その参照IDが凍結入力の `source_registry` に**実在するか**（無ければ `EVIDENCE_REF_UNKNOWN`）
2. その出典が**その馬のものか**（`horse_nos`／`applicable_horse_nos` に対象馬が入っているか。入っていなければ `EVIDENCE_REF_NOT_OWNED`）
3. その馬に**自分のSIGNAL系統が1つ以上残るか**（残れば①②に汚れがあっても行は落とさない。契約6章「HORSE/PAIR/適用COHORTの確認済み1系統があれば残す」）

馬ごとの内訳（参照数・自分のSIGNAL数・実在しない数・所有外の数）を `diag.ref_stats` として外へ出す。試験 R-26〜R-35（10項目）。

採点と出典の結びつきも実装した。`@ANCHOR-20261020-R122-SCOREEV`。点が付いているのに出典が空（`SCORE_WITHOUT_EVIDENCE`）、採点していないのに理由が空（`SCORE_NULL_WITHOUT_REASON`）、採点の出典が実在しない・所有外（`SCORE_EVIDENCE_REF_UNKNOWN`／`SCORE_EVIDENCE_REF_NOT_OWNED`）を見る。試験 R-36〜R-42（7項目）。

採点の再計算も実装済みである。満点辞書との突き合わせ、`observed_sum`／`observed_cap`、`total_score`、宣言された帯・Tier・資格・採点状態と再計算値の不一致を `BaganrikiDiagnose::run()` が診断する。不正項目だけ `null` にして合計を再計算する。試験 R-43〜R-51（9項目）。採点辞書そのものは正本どおり `BaganrikiDiagnose::SCORE_CAP` に保持し、変えていない。

【実装追記 2026-10-21】**採点欄だけの誤りで正常な根拠を落とさないようにした。**`@ANCHOR-20261020-R122-SALVAGE`。採点集計の数字が合わないだけで1頭まるごと落としていたのを、採点欄だけ空にして行を残す形にした。救済する指摘コードは `R122_SCORE_ONLY_CODES`（14コード）に限り、採点以外の指摘が1つでも混ざれば救済しない。自分のSIGNALが0の馬も救済しない。救済した馬は `diag.salvaged` に残す。試験 R-43〜R-51。

## 5 信号とX6暫定規則

### 5.1 維持する信号式

凍結順位kとk＋1の同じ馬ペアを全時点で使う。Wは単勝の下側÷上側、Pは複勝最小の下側÷上側。短縮率は `(以前−以後)÷以前`。正の元値を正確な分数として比較し、表示用に丸めた比率を使用しない。

【実装追記 2026-10-20】**割り算もfloatも通さず、元値のたすき掛けで比べる**。十進数の文字列のまま、多倍長整数で比較する。`$pairs[k]['exact']` に判定結果を持たせ、X1〜X5と**Zも**これを見る。

```text
W>=2.00        ⇔ 2×下側 <= 上側
W<1.50         ⇔ 2×下側 >  3×上側 の否定（厳密比較）
P>=1.50        ⇔ 2×下側 >= 3×上側
短縮率>=0.20   ⇔ 4×下21×上6 >= 5×下6×上21
複勝短縮>=0.05 ⇔ 19×前 >= 20×後
複勝短縮>=0.03 ⇔ 97×前 >= 100×後
```

W・Pの**数値**は画面と監査のために残すが、**判定には一切使わない。**桁数の上限は決めていない（`usableDecimal()` は小数20桁も受け取る。整数129桁と0は受け取らない）。丸めが起きる桁の値は `source_registry` に載せず、文字列のまま扱う（`r122NumValue()`）。`@ANCHOR-20261020-R122-EXACT` / `@ANCHOR-20261020-R122-DIGITS`、試験 R122-E07。

【実装追記 2026-10-20】**floatを通る代替経路について**。手で組み立てた試験用ペア（`exact` が無いもの）だけが代替比較へ落ちる。**本番の入力からは到達しない**ことを試験にした（R122-E07「本番のペアは代替経路へ落ちない」）。

| 信号 | 条件 |
| --- | --- |
| X1 | k＝1〜min(6,N−1)で、W21≧2かつ「W6＜2またはWの21→6短縮率≧0.20」が1境界以上 |
| X2 | k＝1〜min(10,N−1)で、W6≧2かつP6＜1.50が1境界以上 |
| X3 | N≧7でk＝3,4,5,6のW6＜1.50が全成立。N＜7はfalse |
| X4 | 凍結7番人気以下の異なる2頭以上で、複勝最小の21→6短縮率≧0.05または9→6≧0.03 |
| X5 | k＝7〜min(10,N−1)で、W6≧2が1境界以上 |
| X6 | 今回はN≧7でnull、N＜7でfalse。未検証の類似統計を注入しない |
| Z | k＝1〜min(5,N−1)の異なる2境界以上で、W21≧2、W6≧2、P6≧1.50 |

ANDはfalseを優先し、falseなし・nullありならnull。ORはtrueを優先し、trueなし・nullありならnull。m件以上条件はtrue数がm以上ならtrue、true＋null数がm未満ならfalse、他はnull。必要集合が空の場合はこの件数規則で判定する。

### 5.2 正式modeと配分用mode

`S = 2×X1 + X2 + X3 + X4 + X5 + X6 − 2×Z`。

nullを0/1の全組合せで評価し、S≦1をFIRM、2〜3をMIXED、4以上をVOLATILEとする。可能なmodeが一つなら正式modeはその値、複数ならUNKNOWN。nullが一つでもあればSはnullとし、S_min／S_maxを保持する。

| 条件 | mode | mode_status | allocation_mode | mode_policy_id | selection_enabled |
| --- | --- | --- | --- | --- | --- |
| 可能なmodeが一つ | そのmode | CONFIRMED | modeと同じ | BASE_TRI_STATE_V1 | true |
| 不明がX6だけ、S0＝1 | UNKNOWN | PROVISIONAL | MIXED | X6_ONLY_UPPER_MODE_V1 | true |
| 不明がX6だけ、S0＝3 | UNKNOWN | PROVISIONAL | VOLATILE | X6_ONLY_UPPER_MODE_V1 | true |
| 他の複数mode状態 | UNKNOWN | UNRESOLVED | null | BASE_TRI_STATE_V1 | false |

S0はX6以外の重み付き合計である。処理は必須入力検証→信号→可能mode→上表の順。入力不正や全頭取消をこの表で救済しない。CONFIRMEDでは既存の星算出条件を維持し、PROVISIONALでは`display_rating=null`。

【実装追記 2026-10-20】**Zの判定が丸めた値を見ていた**（10月20日005のご指摘3。本物の不具合だった）。X1〜X5を元値の比較へ置き換えたとき、**Zだけ `round(…,4)` した表示用の値を見たままだった。**19.9999÷10＝1.99999 が 2.0000 に丸められて「壁あり」と判定され、ご指定の16頭の例で**混戦3/3/1が堅い5/2/0に変わっていた**（穴側が4枠から2枠、大穴側が1枠から0枠に減る）。`@ANCHOR-20261020-R122-Z` で元値のたすき掛けへ直した。旧コードはコメントで残してある。

試験 **R122-Z01**（22項目）で、ご指定の16頭を**元値→ペア→全信号→mode→配分**まで通した。

| 項目 | 正本 | いまのコード |
| --- | --- | --- |
| Z | FALSE | FALSE |
| X1／X2／X4／X5 | FALSE | FALSE |
| X3 | TRUE | TRUE |
| X6 | UNKNOWN | UNKNOWN |
| S_min／S_max | 1／2 | 1／2 |
| mode_status | PROVISIONAL | PROVISIONAL |
| allocation_mode | MIXED | MIXED |
| 1stの配分 | 3/3/1 | 3/3/1 |
| 2ndの配分 | 1/3/1 | 1/3/1 |

同じ入力で「表示用のW21を4桁に丸めると `2.0000` になる」ことと「元値の比は2未満（FALSE）」を**両方**試験に入れた。丸めが戻ればこの2行が食い違って落ちる。

【実装追記 2026-10-20】**合成729件を本体へ直接通した**（10月20日005のご指摘6-1）。従来はCommand側の検証用関数 `self::r122Decide` へ通していた。本体 `BaganrikiSignals::v04Classify` へ `mode_cases.json` の729件を通し、**判定6,561項目・不一致0**を確認した（試験 N-01・N-02）。取得境界4件も本体 `BaganrikiTime::acceptBatch` へ通した（N-03・N-04）。**検証用関数側の結果は別の見出しで分けて出す。**

```text
必須入力が不正なら INPUT_INVALID として停止
Nが0なら CANCELLED としてAIを呼ばない
通常の三値判定で可能なmodeを求める
modeが一意なら CONFIRMED
それ以外で unknown_keys がちょうど [X6] なら
    S0=1 は MIXED 配分、S0=3 は VOLATILE 配分、状態は PROVISIONAL
それ以外は UNRESOLVED
```

X6をtrueまたは0へ決め打ちして正式modeを偽装しない。将来の有効化は類似定義・母数50件の数え方・検証・切替条件を別途定める。件数到達だけでは有効化しない。

## 6 配分と候補保持

N＝0はC＝0、1〜3はC＝N、4〜8は4、9〜13は5、14〜15は6、16〜18は7。A＝凍結1〜6人気、B＝7〜10、D＝11以下。表はA／B／D。

| C | FIRM | MIXED | VOLATILE |
| --- | --- | --- | --- |
| 1〜3 | C/0/0 | C/0/0 | C/0/0 |
| 4 | 3/1/0 | 2/2/0 | 2/1/1 |
| 5 | 4/1/0 | 3/2/0 | 2/2/1 |
| 6 | 4/2/0 | 3/2/1 | 3/2/1 |
| 7 | 5/2/0 | 3/3/1 | 3/3/1 |

配分表は`allocation_mode`で引く。1stのcapはC。構造調整後、C≦5なら2ndも同じ配分。C＞5なら2ndはB・Dを維持しA＝5−B−D、cap＝5とする。

| 不足 | 全帯を先に予約した後の処理 |
| --- | --- |
| 構造上の実在馬不足 | 不足帯をD、B、Aの順に処理。DはB→A、BはD→A、AはB→Dから未予約枠だけを移す |
| 評価後の適格馬不足 | 不足帯をD、B、Aの順に処理。DはBのみ、BはDのみ、AはB→D。B／DをAで埋めない |

馬番は重複させず、割当元帯・実際の帯・不足理由を保存する。適格な馬が帯内に足りる場合は指定枠を必ず満たす。

個別SIGNALの確認済み独立根拠が1系統あれば候補資格を保持する。RACE情報のみ、CONTEXTのみでは資格を作らない。旧F AND (R OR O)、OPI必須、複勝短縮必須、Tier A必須、低配当、回収率40％、旧最低点、部分欠損だけによる削除は禁止する。

全頭の5系統レビューと10項目採点を受領する。H上限は18/15/12/9/6、V上限は12/10/8/6/4。帯内の全適格馬がcompleteならSCORE、partialを含むならEVIDENCE。EVIDENCEでは点数を順位キーにしない。根拠不足を架空の採点で埋めない。

【実装追記 2026-10-20】**採点辞書は正本どおり保持している**（`BaganrikiDiagnose::SCORE_CAP`）。H1 能力適性18／H2 時系列15／H3 断層12／H4 類似統計9／H5 単複差6／V1 補正OPI・市場評価12／V2 能力と価格の乖離10／V3 回収率と母数8／V4 推定確定オッズと誤差6／V5 情報・価格の信頼性4。**前回の接続表でH4を「市場の支持の質」、H5を「条件適性」と書いていたのは誤りである。**接続表の呼び名に合わせて採点仕様を変えることはしない。追加材料とこの辞書の対応は基本設計書 文書版1.2 第7章の追記を参照。5系統レビューの有無と資格との整合は `BaganrikiDiagnose::run()` が検査する。

【差替え 2026-10-21】上の段落の末尾は、文書版1.1では「**10項目採点の再計算は未実装。**」だった。**実装したので差し替える。**満点・合計・`observed_sum`／`observed_cap`・`total_score` の再計算と、宣言値との不一致の診断を `BaganrikiDiagnose::run()` が行う。試験 R-43〜R-51。詳しくは第4章末尾の差替えを参照。

【実装追記 2026-10-20】**取消後は最新の頭数から目標頭数と基本配分を引き直す**（10月20日005のご指摘4。本物の不具合だった。従来は旧配分の不足移動しかしていなかった）。**凍結帯は維持し、人気は繰り上げない。**`afterCancellation()`（`@ANCHOR-20261020-R122-RECALC`）、試験 N-15〜N-29。

| 例 | 取消前 | 取消後の目標頭数 | 取消後の基本配分 |
| --- | --- | --- | --- |
| 16→15頭 | 7／3-3-1 | 6 | 3/2/1 |
| 14→13頭 | 6／3-2-1 | 5 | 3/2/0 |
| 9→8頭 | 5／3-2-0 | 4 | 2/2/0 |
| 全頭取消 | 7／3-3-1 | 0 | `all_cancelled=true`。`live_quota=null`。旧配分を返さない |

引き直した基本配分のあとに、実在しない帯の枠を D→B→A の順で移し（`BAND_CAPACITY`）、さらに評価後の不足を `fillShortfall()` で処理する2段構えにしている。16頭で1番人気を取り消した場合、Aの生存は6→5、Bは6のままで、**Bから繰り上げない**（試験 N-28・N-29）。

## 7 入出力Schemaと両AI本文

### 7.1 追加項目

| 配置 | 追加項目 | 型・条件 |
| --- | --- | --- |
| 入力と応答の最上位 | mode_status | CONFIRMED／PROVISIONAL／UNRESOLVED |
| 入力と応答の最上位 | allocation_mode | FIRM／MIXED／VOLATILE／null |
| 入力と応答の最上位 | mode_policy_id | 第5章の2識別子 |
| 入力の最上位 | input_freeze_at | タイムゾーン付きRFC3339 |
| 入力の最上位 | collection_audit | 下記の採用バッチ監査。欠損も明示する |
| 入力のtime_contract | acquisition_grace_ms | slotキー6/9/12/15/18/21と指定ミリ秒の固定対応 |

`collection_audit`は6スロットをキーとし、各値に`status`を持つ。採用時は`ADOPTED`とbatch_id、payload_hash、slot_scheduled_at、source_updated_at、request_started_at、response_received_at、available_atを保持する。欠損時は`UNAVAILABLE`と非空reasonを持ち、時刻を偽造しない。6分前UNAVAILABLEでは本番snapshotを作らない。

旧Schemaの`additionalProperties=false`を維持し、追加項目をproperties・requiredへ明示登録する。旧「mode=UNKNOWNならselection_enabled=false」の条件を第5章の表へ置き換える。応答の3追加項目は入力と完全一致を必須とする。旧版応答は新snapshotに採用しない。

【実装追記 2026-10-20】**Schemaは配布版を1バイトも変えていない。**`input_schema.json`・`output_schema.json`・`source_registry_schema.json` は正本と全バイト一致のまま使っている。したがって追加材料は**既存の `source_registry` の中に入れている**（最上位へ新しいキーを作っていない）。`source_registry_schema` は `additionalProperties=false` なので、`source_ref` 名・`scope`・`source_kind`・`slot_offset`（21/18/15/12/9/6/null のみ）・親子関係の制約をすべて満たす形で入れた。本番の経路で作った入力3レースを両Schemaに当て、**違反0件**を確認している（`--r122-prod-input`）。

| 追加した `source_ref` | scope | source_kind | 内容 |
| --- | --- | --- | --- |
| `h<馬番>_past_<項目>_<n>` | HORSE | OBSERVED | 過去走の生8項目 |
| `h<馬番>_past_<まとめ>` | HORSE | DERIVED | 過去走のまとめ3項目（親を指す） |
| `h<馬番>_odds_gap_pct` | HORSE | DERIVED | 21→6分前の増減率 |
| `gaprec_<帯>_<人気帯>_<項目>` | COHORT | DERIVED | 回収率・勝率・母数 |
| `gaprec_start_date` / `gaprec_end_date` | RACE | OBSERVED | 回収率の集計期間 |
| `simstat_<頭数帯>_<人気>_<項目>` | COHORT | DERIVED | 過去類似成績 |
| `popavg_rank<n>_win_odds` / `_count` | COHORT | OBSERVED | 人気順位別の過去平均単勝と母数 |
| `fukuavg_rank<n>_place_min` / `_count` | COHORT | OBSERVED | 同（複勝最小） |
| `corr_rank<n>_avg_correction_ratio` / `_std_` / `_sample_count` | COHORT | OBSERVED | 補正係数・標準偏差・母数 |
| `popavg_start_date` / `_end_date`、`fukuavg_…`、`corr_…` | RACE | OBSERVED | 各集計の期間 |
| `h<馬番>_opi` | HORSE | DERIVED | 過去平均単勝 ÷ 6分前単勝 |
| `h<馬番>_fuku_opi` | HORSE | DERIVED | 過去平均複勝最小 ÷ 6分前複勝最小 |
| `h<馬番>_estimated_final_odds` | HORSE | DERIVED | 6分前単勝 × 補正係数 |
| `h<馬番>_estimated_final_place_min` | HORSE | DERIVED | 6分前複勝最小 × 補正係数（**複勝専用の係数は未実装のため単勝の係数を流用している**） |
| `h<馬番>_estimated_opi` | HORSE | DERIVED | 過去平均単勝 ÷ 推定確定オッズ |

**式はいまの商品と同じものを使い、作り直していない。**13頭のレースで162項目（RACE 6／COHORT 91／HORSE 65、OBSERVED 97／DERIVED 65）。1番人気の5値をSQLから取り直して突き合わせ、全部一致した（試験 M-10〜M-14）。`BAGANRIKI_MARKET_PRIOR=0` でこの区分だけ止められる（M-16〜M-18）。

【実装追記 2026-10-20】**送信文の大きさ（実測）**。`input_schema` は閉じているため、締切の出どころなど追加情報はINPUT_JSONに入れられない。監査イベントへ残している。

| レース | N | source_registry | 送信文 | 過去走を外すと |
| --- | --- | --- | --- | --- |
| 20261018-4-04-2-1 | 13 | 470件 | 263,891バイト | 127,762バイト |
| 20261018-4-04-2-2 | 15 | 554件 | 310,529バイト | 146,765バイト |
| 20261018-4-04-2-3 | 13 | 451件 | 253,894バイト | 127,750バイト |

**過去走が送信文の約半分を占める。**いちばん重いのは最初の1走である。`BAGANRIKI_PAST_RUNS_MAX` で調整できる。**実AIがこの大きさに何秒で答えるかは未測定である。**走数を何走にするかはご判断をお願いしたい。

【実装追記 2026-10-21b】**内部の監査項目とAIの業務項目を分ける。**（10月21日008のご指摘3）

検証側が付けた内部の監査項目（キーが `r122_` で始まるもの）は、**評価行の中に入れてはならない。**入れると、訂正の照合で「初回にあって訂正に無い項目」となり、**正しい全頭訂正が範囲外の変更として拒否される。**内部の監査項目は別の入れ物に出す（`salvage_detail`）。訂正の側に `r122_` のキーが入っていた場合は、その訂正を採用しない。AIに内部の監査項目を返させる解決はしない。

### 7.2 hashと時刻

共通入力から`ai_role`、`role_cap`、`role_quota`、`snapshot_hash`自身を除いたJSONを一度だけ正確に生成し、そのUTF-8本文をSHA-256でhash化する。追加したmode・取得猶予・情報上限・監査も含める。`request_at`とライブ取消は混ぜない。両roleのJSONは保存した本文をそのまま送信する。

オッズ観測の`observed_at`は本契約では受信完了時刻とする。取得元更新時刻はcollection_auditへ別保存する。過去走や既存統計等は、その出典固有の観測・利用可能時刻を保持し、今回の読取時刻へ置き換えない。過去の保存時刻しかない入力を新取得契約適合と見なさず、`REPLAY_ONLY`の検証記録で扱う。本番公開へ流さない。

### 7.3 両AIに渡す共通指示

> modeは正式な信号判定、allocation_modeはサーバーが指定する配分用判定である。PROVISIONALかつselection_enabled=trueでは指定role_quotaに従って選出する。X6、mode、allocation_mode、元値を変更しない。全頭を独立評価し、確認済み根拠を持つ人気薄を旧資格や主観で除外しない。利用可能情報の上限はinput_freeze_atであり、それより後の情報は使わない。

旧本文に追記するだけではなく、UNKNOWN時の停止条件、取得時刻上限、共通キー、候補配分の規則を1st・2nd同時に置換する。相手roleの応答を渡さない。通常の根拠照合・採点・訂正規則は残す。

## 8 数値と日時の扱い

原JSONはLONGTEXT等で原文保存し、MySQL JSONへの変換結果だけを正本にしない。DBの十進数文字列は明示したアダプタ境界で正確な数値へ変換する。AIが返した文字列を都合よく数値へ変換しない。

計算にはPHP側の任意精度整数・十進数／有理数処理を用意する。閾値は元値からの正確な比較とし、PHP float経由の再シリアライズをしない。Laravelの通常JSON処理へ任せる前に、厳密パーサで型・重複キー・深度・サイズ・Unicode・桁を検査する。

入力JSONと応答1本は4MiB以内、最大64階層、参照20,000件以内、有限数値最大128桁、調整後指数−308〜308を維持する。生成後のDTOも同じ検査を行う。公開factsはvalue_typeを伴い、非整数数値や大きな整数を正確な十進数文字列で返す。Flutterは表示用文字列として保持し、全項目をdoubleへ変換しない。

日時は秒必須、タイムゾーン必須、小数秒は0〜6桁のRFC3339。7桁以上を切り捨てない。比較は同じ基準へ正規化し、元の文字列表現も監査保存する。同時刻の表記違いだけでrevisionを増やさない。

【実装追記 2026-10-20】**できている部分**。入力側は元値の十進数文字列を保持し、丸めが起きる桁は数値化せず文字列のまま扱う（`r122NumValue()`）。日時は正規表現でRFC3339（小数秒0〜6桁・タイムゾーン必須）を強制し、7桁以上は受け取らない。繰上がりも拒否する（`@ANCHOR-20261020-R122-ROLLOVER`）。閾値比較は多倍長整数のたすき掛けで、PHP float を通らない。

【差替え 2026-10-21】**実装した。**上の文書版1.1の追記は、いまの現物と合わなくなったので差し替える。

> 差替え前（文書版1.1）の文はこうだった。
> 「【実装追記 2026-10-20】**未実装**。応答側の厳密パーサは入れていない。`BaganrikiResponse::extract()` は通常の `json_decode`（深さ64の制限のみ）で、**重複キー・任意精度数値・サイズ上限（4MiB）・数値の桁と指数範囲は検査していない。**10月20日005のご指摘7-3は未解消である。実AIを呼ぶ前に入れる必要がある。」

応答側の厳密読込を `BaganrikiJson::strict()` として実装した。`@ANCHOR-20261020-R122-STRICTJSON`。

**走査と値の組み立てを分ける設計にした。**走査（`strict()`）は本文を1文字ずつたどって契約9章の制限を確かめるだけで、値は作らない。値の組み立ては確かめ終わったあとに `json_decode` で行う。自分でパーサを書いて値まで作ると、そのパーサ自身の取り違えが新しい穴になるためである。

見るもの。

| 制限 | 値 | 落ちたときのコード |
| --- | --- | --- |
| サイズ上限 | 4 MiB（`MAX_BYTES = 4194304`） | `TOO_LARGE` |
| 入れ子の深さ | 64（`MAX_DEPTH`） | `TOO_DEEP` |
| 数値の有効桁 | 128（`MAX_DIGITS`） | `NUMBER_TOO_LONG` |
| 調整後の指数 | −308〜308（`EXP_MIN`／`EXP_MAX`） | `EXPONENT_RANGE` |
| 同じ階層の重複キー | 禁止（`$seen[$k]` で検出） | `DUPLICATE_KEY` |
| UTF-8 | 不正なバイト列・孤立サロゲートを拒否 | `BAD_UTF8` |
| 末尾のゴミ | JSON1個のあとに何も無いこと | `TRAILING_DATA` |

重複キーは `json_decode` では後勝ちで黙って片方が消えるため、走査側で見つける。128桁超は `json_decode` で float へ丸まり、`1e400` は `INF` になるため、これも走査側で止める。

**送る前の自分の本文も同じ検査を通す**（`BaganrikiPrompt::selfCheck()`、および訂正依頼は `r122AssembleFix()` の中）。こちらが作った本文が契約の制限を超えていたら、AIを呼ぶ前に止める。

試験 R122-J02（25項目）。10月20日005のご指摘7-3はこれで解消した。

【実装追記 2026-10-21b】**応答JSONの数値と型。**（10月21日008のご指摘6）

1. 走査を通った数値は落とさない。契約9章の有効桁128桁までは受け取る。PHPの数値で正確に持てない（丸まる・無限大になる）トークンは、**原表現の十進数文字列のまま**値に入れる。INF や丸めた別値へ変えてはならない。
2. それだけでは**JSON上の型が消える。**`{"a":1.99…}` と `{"a":"1.99…"}` が同じ値になり、再保存で数値が文字列に化ける。そこで**どの経路がJSON上の数値だったか**を控える（`number_paths`）。原トークン・正確な十進値・JSON上の型がこれで結び付く。再保存は `BaganrikiJson::reserialize($value, $number_paths)` を使い、数値の位置は引用符を付けずに書き戻す。
3. AIが最初から引用符付きで返した文字列は `number_paths` に載らない。**勝手に数値として扱ってはならない。**
4. 文字列の `\uXXXX` は、キーを比べる前に**復号する。**復号しないと `{"a":1,"\u0061":2}` が重複キーの拒否をすり抜け、後段の `json_decode` で黙って片方が消える。代理対は組み立て、片方だけの代理は受け取らない。
5. 型の検査は使う側で行う。採点は整数と決まっているので、丸まる数が採点に来れば文字列のままとなり、採点の検査（`is_int`）が落とす。

実装は `BaganrikiJson::strict()`／`num()`／`str()`／`reserialize()`（`@ANCHOR-20261021-R122-NUMTYPE`・`@ANCHOR-20261021-R122-UNESCAPE`）。試験 R122-J05。

## 9 MySQLの保存設計

既存テーブルの元値は更新しない。以下は専用の新規テーブルとして作る論理設計である。race_idの型・照合規則は既存正本との完全一致を優先し、任意の整数変換をしない。関連する更新は同じMySQLトランザクションで処理する。

| テーブル | 主な項目 | 一意制約・索引 |
| --- | --- | --- |
| baganriki_race_runs | run_id、race_id、generation、run_kind、処理状態、各count、計算不能理由、release | UNIQUE(run_id)、INDEX(race_id,run_kind)。run_kindはPRODUCTION／REPLAY |
| baganriki_acquisition_batches | race_id、slot、batch_id、各実時刻、raw_payload、hash、検証状態・理由 | UNIQUE(batch_id)、INDEX(race_id,slot,available_at) |
| baganriki_snapshots | race_id、generation、release、common_json、role別input_json、snapshot_hash、凍結集合、cutoff、input_freeze_at、frozen_at | UNIQUE(race_id,generation)。不変 |
| baganriki_live_states | snapshot_id、累積取消、active_count、live_close、state_revision、response_revision、publication_revision、latest_publication_id | UNIQUE(snapshot_id)。競合の共通ロック行 |
| baganriki_ai_attempts | snapshot_id、role、attempt_no、内部token、status、予約時刻、受領時刻、complete、原応答、hash、失敗理由 | UNIQUE(snapshot_id,role,attempt_no)、UNIQUE(token) |
| baganriki_validation_results | attempt_id、検証版、正常部分、診断、repair_scope、field_sources、順位出典 | UNIQUE(attempt_id,検証版) |
| baganriki_publication_intents | snapshot_id、job_id、intent_hash、読取時の3revision、state、publication_id | UNIQUE(job_id)、UNIQUE(snapshot_id,intent_hash) |
| baganriki_publications | publication_id、snapshot_id、intent_id、公開時刻、候補・配分・頭数、根拠と出典、使用revision | UNIQUE(intent_id)。履歴を追記 |
| baganriki_audit_events | race_id、snapshot_id、種別、実時刻、旧新revision、診断、関連ID | INDEX(race_id,実時刻) |

内部tokenは公開APIへ出さない。原入力・原応答はアクセス制限した監査用途とする。正確な原文を保持する列と、検索用の整数・状態・日時列を分ける。日時比較列はマイクロ秒を保持する。

対象レースを確定した時点でrace_runを保存し、snapshotを作れない入力不正でも結果を残す。snapshotと監査はrun_idへ対応づける。REPLAYの結果・呼出枠・公開計算はPRODUCTIONから隔離し、公開APIはPRODUCTIONだけを読む。run_kindは学習段階とは別項目で、いずれもlearning_stageはCOLLECT_ONLYとする。

generationは固定入力の識別子であり、締切延長やAI再送枠を得るために自動更新しない。稼働中レースを新しい版に付け替えない。

【実装追記 2026-10-20】**REPLAYとPRODUCTIONの分離を、ラベルではなく保存の可否で実現した**（10月20日005のご指摘9）。`baganriki_snapshots` の一意キーは `(race_id, generation)` なので、`run_kind` を付けるだけでは同じレースのPRODUCTIONが同じsnapshotを見つけてSKIP（既存）扱いになる経路が残る。そこで**仮の監査・締切で作った入力はPRODUCTIONとして保存できないようにした。**REPLAYは `generation=900`、本番は `generation=0`。読取APIは `generation=0` だけを読む（試験 A-02）。

同じ `race_id` でREPLAYを保存したあとPRODUCTIONを走らせ、「止まりました: 仮の監査・締切で作った入力は PRODUCTION として保存しません」と「本番の世代（generation=0）には1件も入っていません」を試験で確認している（`--r122-acq-test`）。

【実装追記 2026-10-20】**読取APIが `generation` を取り違えていた**（本物の不具合だった）。`orderByDesc('generation')` だと試験用の `generation=900` を拾って `REPLAY` を返していた。`generation=0` のみを読むよう直した（試験 A-02）。

【実装追記 2026-10-20】**公開が二重になる経路があった**（本物の不具合だった）。`intent_hash` に `publication_revision` を混ぜていたため、1回公開すると版が増えて同じ計算でも2回目が別intentになり、`ALREADY` を返さずに二重公開していた。**intentに入れるのは「読んだもの」だけ**（`state_revision` と `response_revision`）に直した。`publication_revision` は公開した**結果**なので入れない。`@ANCHOR-20261020-R122-PUB`、試験 P-09〜P-11。

【実装追記 2026-10-20】**実効公開期限が出ない経路があった**（本物の不具合だった）。`sales_close_at` は `DATETIME(6)` なのにRFC3339専用の `BaganrikiTime::us()` へ渡していたため常に null になり、「未公開で期限超過は UNAVAILABLE」が働かずずっと PENDING だった。`BaganrikiWorker::dbUs()` に直した（`live_close` も同じ）。試験 A-25・A-26。

## 10 Queueと外部AI呼出し

### 10.1 初回と訂正の予約

1. DBの保存状態と実効応答期限を読む。診断処理は書込ロック外で行う。
2. 短いトランザクションでlive_state行をロックし、対象role状態・現在時刻・全頭取消・期限を再確認する。
3. 初回0、または必要な訂正1をUNIQUE制約のあるattempt行へINSERTし、response_revisionを増やしてcommitする。
4. このINSERTに成功した実行だけが外部APIを1回呼ぶ。重複ジョブが既存行を読めたことを送信許可にしない。
5. 原応答とcomplete、受領時刻を同じDBへ終端保存し、response_revisionを増やしてから次の処理へ進む。

statusは`RESERVED`、`RECEIVED`、`FAILED`を区別する。予約済みで結果が復元できない枠は結果不明として監査表示し、自動再送しない。予約直後に停止して未送信でも枠は再発行しない。

SDK内の自動リトライは0。Queueの再実行でも同じ枠を使い直さない。外部APIとMySQL間のExactly Onceは保証せず、送信権の再付与を防ぐ設計とする。同じtokenへの同じ終端保存は冪等、違う原応答・終端状態への変更は拒否する。

### 10.2 応答と局所回復

未完了・長さ打切りは`complete=false`で原文保存し、正常なJSONに見えても選出へ使わない。期限後の応答は監査のみ。初回未受領またはFAILEDでは訂正を予約しない。

受領済み初回を検証して訂正が必要な場合に限り、期限内に1回訂正する。初回の保存commit前に訂正を送らない。識別子、版、role、hash、配分、追加mode項目の不一致は応答全体を不採用。馬番重複・欠落・局所不正は該当範囲だけを隔離する。

訂正対象外の正常行は初回を保持する。正常根拠を消す訂正、completeをpartialへ悪化させる訂正は採用しない。事実・採点・順位・表示の出典hashを分け、正常行を新応答由来に偽装しない。順位の互換性を検証できない場合は利用可能な旧順位、検証済み予備根拠順位、独立根拠群数→馬番の順で明示的に縮退し、診断を付ける。

【実装追記 2026-10-21b】**初回を送る者を決める。**（10月21日008のご指摘5）

旧い予想がキャッシュされているレースは、画面からの呼び出しが関数の冒頭のキャッシュ返却で終わる。凍結のやり直しと公開の判定だけでは、**初回の送信を始める者がどこにもいない。**そのレースは永久に送られない。

初回のやり直しは `AiController::r122SendFirst()` とする（`@ANCHOR-20261021-R122-SENDFIRST`）。固定入力（generation=0）から

```
予約（reserve）→ 送信許可（allowSend）→ 送信 → 受領（receive）
→ 検証・訂正・解決・公開（r122AfterReceive）
```

まで進める。呼び出し元は2つ。巡回（`r122SweepPending()`）と、キャッシュ返却後の後始末（`r122AfterCached()`、応答を返したあと）である。枠は `UNIQUE(snapshot_id, role, attempt_no)` なので、画面側と同時に動いても予約できた側だけが送る。応答期限まで最低40秒ないときは送らない。

【実装追記 2026-10-21b】**期限での起動の刻み。**（10月21日008のご指摘5）

1分おきの巡回では、応答期限（発走150秒前）から公開期限（発走120秒前）までの30秒を取りこぼす。巡回は1回の呼び出しの中で、決めた秒数のあいだ短い間隔で回す。既定は55秒を5秒間隔。**終了条件は「終わりの時刻に達するまで」とする。**「今＋間隔 ≧ 終わり」で抜けると、55秒・5秒間隔でも50秒で終わり、次の起動まで約10秒空く。最後の1回は残り時間だけ眠る。

枠の段が off のあいだは拾う対象を見ない。そのときの空回しは10秒で打ち切る（毎分の cron に入れても常駐させないため）。

**まだ足りていないこと。**巡回は訂正の送信を同期で行うので、AIの応答待ちの時間が加わる。複数レースが同時に期限へ来たとき、2本目以降を5秒以内に再確認できるとは限らない。無負荷の刻みの実測は期限遵守の証明にはならない。Job 構成（第10章の正本）が入るまでは、この区間は暫定である。

## 11 統合と公開

### 11.1 順位と最終候補

各帯の凍結頭数をK、role内の検証済み順位をrとし、順位点を`100×(K−r＋1)÷K`とする。統合点は両roleの最大値。両AIの検証済み原公開候補であることを証明できる場合だけ＋5。現在の混在行から一致を推測しない。

同点は原公開候補の両AI一致、独立根拠群数、馬番の昇順を使う。点数比較も正確に行う。勝率・期待値・おすすめ度100点とは表示しない。

統合後にライブ取消を除き、残った馬の凍結帯とactive_countから構造配分を調整する。第6章の評価後不足規則で最終候補を選ぶ。馬番、選出順、割当、根拠、shortfalls、使用応答hashを保存する。

【実装追記 2026-10-21b】**訂正で直してよい帯だけを上書きする。**（10月21日008のご指摘3）

訂正の照合は評価行だけを見ている。`band_ranks` のような最上位の項目は照合していない。したがって「別の馬を直す訂正」が、初回では正常だった帯の並びを変えても通る。**訂正の対象になっていない帯は、初回の並びを保持する。**上書きしてよいのは、初回の `repair_scope.rank_bands` に入っている帯だけである。訂正が対象外の帯の並びを変えていた場合は、採用せず、そのことを記録に残す（`rank_source` に `PREVIOUS_KEPT`、`rank_notes` に理由）。

実装は `AiController::r122ResponseUsed()`（`@ANCHOR-20261021-R122-BANDSCOPE`）。試験 P-58〜P-67。

### 11.2 時刻上限

```text
publish_deadline           = frozen_sales_close_at − 60秒
reply_deadline             = publish_deadline − 30秒
effective_publish_deadline = min(publish_deadline, live_close − 60秒)
effective_reply_deadline   = min(reply_deadline, effective_publish_deadline − 30秒)
```

投票締切は検証済み正本を使い、発走時刻から根拠なく推定しない。正本が不明なレースは公開しない。実効応答期限までにサーバーが受信した応答だけを使う。送信前、訂正予約前、統合時にも期限を検査する。

### 11.3 intentと原子的保存

1. 整合した読取トランザクションでsnapshot、ライブ状態、保存済み応答、3revisionを取得する。
2. job_id、snapshot_id、3revisionを含むintentを保存する。同じジョブの再実行で値を読み替えない。
3. 重い検証・統合をロック外で実施する。
4. 保存直前の短いトランザクションでlive_state、intentの順にロックする。同一intentの公開済み結果があれば同じpublication_idを返す。
5. 未公開intentなら3revisionを再比較し、変更があれば`STALE_INTENT`として旧計算を保存しない。
6. 検証開始≦検証完了≦commit直前の時刻順、実効公開期限内、live_closeより前を再確認する。
7. publicationを追記し、publication_revisionとlatest_publication_id、intent結果、監査を同時にcommitする。

時計逆行、時刻計算の範囲外、期限超過、競合はrollbackする。新しい状態で再統合する場合は新しいjob_idとintentを作る。既存intentの再返却は履歴であり、画面は最新read APIを使う。

統合ジョブは応答・失敗保存後と応答期限到達時に起動する。片側しか使えなくても期限内なら公開可能。もう片側を無期限に待たない。公開状態の変更がないno-opで公開revisionを無駄に増やさない。

## 12 取消と公開API

### 12.1 ライブ更新

取消は累積追加のみ。締切は前倒しのみ。入力全体と時刻計算を保存前に検証し、取消・締切・revision・監査を一つのトランザクションで更新する。途中失敗で取消だけを残さない。同じ内容の再入力でrevisionを増やさない。

公開期限前なら新state_revisionで統合を起動する。期限後はlive_stateだけを更新し、正式publicationは上書きしない。取消解除、締切延長、snapshotの再生成は自動で行わない。

【実装追記 2026-10-21b】**集計の時刻は3つに分ける。**（10月21日008のご指摘8）

過去平均の集計は、新規のレース結果が無い日は値を1行も書かずに終わる。平日は必ずそこを通るので、算出時刻を毎回上書きする作りだと**いつ実際に算出したかという証拠が消える。**r12.2 は「情報上限より前に利用可能だった証拠」を見るので、証拠そのものを毎晩書き換えてはならない。

| 列 | 意味 | 新規データが無い日 |
| --- | --- | --- |
| `computed_at` | 実際に値を算出した時刻 | **触らない**（列を足す前から在る行だけ、初めての記録として入れる） |
| `verified_at` | その値がこの時刻に使えることを確かめた時刻 | 更新する |
| `end_date` | 集計期間の終わり | 触らない |

「もう一度集計して同じ値を確認した」という説明は**誤りなので使わない。**実際に行っているのは「`end_date` より後の新規データが無いことの確認」である。

r12.2 側は `computed_at` を正とし、`computed_at` が無い行だけ `verified_at` で代える。どちらを使ったかは理由文に出す。

### 12.2 読取APIの指定

`GET /api/baganriki/r12-2/races/{race_id}` を新DTO用の読取ルートとする。既存の認証・レース閲覧権限を適用する。既存ルートへ組み込む場合もこの契約を保ち、対応表へ実ルートを記録する。読取ではAI送信や再選出を起動しない。

| 項目 | 意味 |
| --- | --- |
| state | PENDING／PUBLISHED／UNAVAILABLE／CANCELLED。公開状態でありmode_statusとは別 |
| publication | 正式公開ID、公開時刻、prediction_*、publication_target_count、publication_quota、候補と根拠。未公開はnull |
| live_state | state_revision、現在取消、active_count、live_target_count、live_quota、実効公開期限 |
| mode関連 | mode、mode_status、allocation_mode、mode_policy_id、display_rating |
| input_audit | 採用取得時刻、入力上限、coverage、時刻契約版 |
| diagnostics / shortfalls | 公開可能な理由コードと不足説明。内部原文やtokenは含めない |
| buy_recommended | false |

全頭取消はCANCELLED。正式公開がなく、固定入力が計算不能なら期限前でもUNAVAILABLE。処理継続中で期限内ならPENDING、未公開のまま期限超過ならUNAVAILABLEとする。有効な正式公開履歴はPUBLISHEDとして返し、現在の取消をlive_stateで重ねる。

Flutterはサーバーの候補順を保持し、勝手な人気順ソートや穴枠削除をしない。暫定では「混戦として暫定選出」「波乱として暫定選出」と表示する。原AI説明文をそのまま表示せず、検証済みfactのテンプレートから表示文を作る。

| 主な理由コード | 処理 |
| --- | --- |
| ENTRY_MISSING / ENTRY_SET_MISMATCH | 計算停止。対象レースを集計に残す |
| FREEZE_ODDS_MISSING / CANCEL_CONFLICT | 計算停止。既知の数と不明の数を分離する |
| ACQUISITION_DEADLINE_EXCEEDED | 当該バッチを不採用。6分前の有効バッチなしなら停止 |
| MODE_UNRESOLVED | 選出停止。X6以外への暫定例外拡張なし |
| AI_FAILURE / PARTIAL_AI_VALIDATION | 正常な別role・別馬を保持する |
| RANK_RECOVERY_FALLBACK | 検証済みの縮退順位と理由を表示する |
| PUBLICATION_DEADLINE_EXCEEDED / STALE_INTENT | 新規公開保存を拒否。正常な保存済み応答は保持 |

既存診断コードと意味が同一なら既存名を維持し、対応表で今回名と対応させる。

## 13 受入試験

Laravelの自動試験とDB・Queue接続試験で固定JSON入力／期待値を検証し、Flutter表示と実AI試験を別に行う。以下のmode試験は単体試験用の信号組合せで、実レースの根拠を代替しない。

| ID | 入力・操作 | 期待結果 |
| --- | --- | --- |
| M01 | N≧7、X2=true、他X1〜X5=false、Z=false、X6=null | S0=1、S範囲1〜2、正式UNKNOWN、暫定MIXED |
| M02 | N≧7、X1=true、X2=true、他X3〜X5=false、Z=false、X6=null | S0=3、S範囲3〜4、正式UNKNOWN、暫定VOLATILE |
| M03 | X1〜X5=false、Z=false、X6=null | S範囲0〜1、CONFIRMED FIRM、星は一意でなければnull |
| M04 | X1=null、X2〜X5=false、Z=false、X6=null | S範囲0〜3、UNRESOLVED、暫定適用なし |
| M05 | N＜7 | X6=false。欠損時は通常の三値規則を使用 |
| Q01 | N16、MIXED、各帯十分 | 1st=3/3/1、2nd=1/3/1 |
| Q02 | N10、VOLATILE | 構造調整後2/3/0 |
| Q03 | 評価後D不足、B余剰あり | Bだけから補充。Aへの流入なし |
| Q04 | 個別SIGNAL1系統、旧資格なし・低配当・部分欠損 | その条件だけで候補から削除しない |
| T01 | 6分前available_atが予定＋30秒／その1µs後 | 前者許容、後者不採用 |
| T02 | 9分前available_atが予定＋120秒／その1µs後 | 前者許容、後者不採用 |
| T03 | 応答受信は期限内、保存利用可能は期限後 | 不採用 |
| T04 | 要求開始が予定前、または日時逆行 | 当該バッチ不採用 |
| T05 | 遅れて到着したデータがinput_freeze_at超過 | snapshotへ混入しない |
| D01 | 宣言10頭、出馬表欠落、オッズ9頭 | ENTRY_MISSING。9頭立て計算へ進まない |
| D02 | 対象2日中1日のオッズ全欠損 | 対象レース分母を維持、欠損理由を記録 |
| D03 | 6分前数値、3分前に取消 | 固定計算不変、ライブ取消へ反映 |
| D04 | 取消矛盾と馬キー欠落が同時 | 両診断と各countの意味を維持、active_count=null |
| W01 | 同role初回Jobを同時・重複実行 | 予約1件、外部送信権1回 |
| W02 | 予約commit後の停止・再起動 | 結果不明を記録し、同枠を再送しない |
| W03 | 初回保存後に停止して再起動 | 保存済み初回から訂正判定を再開、初回を再送しない |
| W04 | 訂正が正常根拠を削除 | 初回の正常部分を保持 |
| W05 | 未完了応答、片側失敗 | 未完了を採用せず、他方の正常部分を保持 |
| P01 | 検証中に取消または応答追加 | revision不一致で古い公開を拒否 |
| P02 | 同intentを再実行 | 同じpublication_id。履歴と最新表示を区別 |
| P03 | 公開期限直後に取消 | ライブ表示のみ更新。候補追加・入替なし |
| P04 | 締切前倒し、時刻演算失敗、時計逆行 | 期限再計算。失敗は関連更新全体rollback |
| J01 | 128桁・129桁、64階層超過、4MiB超過、重複キー | 規定境界で判定し、制御されたエラー。桁を丸めて通さない |
| U01 | 暫定・不足・取消、数値文字列 | Flutterが表示を維持し独自再選出しない |

【実装追記 2026-10-20】**実装した試験の一覧と、上表との対応**。すべてサーバの実環境で実行した。ロールバックするものは毎回「DBへ残る書き込み0件」を確認している。

【差替え 2026-10-21】**試験件数の表を、今回の実行に合わせて差し替える。**

> 差替え前（文書版1.1）の表の合計は **799** だった。10月20日006で
> 「799の算術は 553＋67＋25＋24＋29＋29＋18＋9＋13＋32 で整合します」と
> ご確認いただいている。各行の値は次のとおりだった。
> `--v04-test` 553／`--r122-queue-test` 67／`--r122-response-test` 25／
> `--r122-publish-test` 24／`--r122-read-test` 29／`--r122-native-test` 29／
> `--r122-market-test` 18／`--r122-prod-input` 9／`--r122-acq-test` 13／
> `--r122-invalid-cases` 32。

今回（2026年10月20日 20:17 JST の実行）の件数。

| コマンド | 件数 | 前回 | 上表との対応 | 結果 |
| --- | --- | --- | --- | --- |
| `--v04-test` | 601 | 553 | M01〜M05・Q01〜Q04・T01〜T05・J01、R122-Z01（Zの16頭）、R122-CL01〜08（締切）、**R122-J02（厳密読込25）**、**R122-E08（桁落ち14）** | 不合格0 |
| `--r122-queue-test` | 97 | 67 | W01〜W03（Q-01〜Q-44・W-01〜W-09）、旗とshadow（S-01〜S-14）、**実送信の後への接続（X-01〜X-30）** | 不合格0 |
| `--r122-response-test` | 51 | 25 | W04・W05（R-01〜R-25）、**参照IDの意味検証（R-26〜R-35）**、**採点と出典（R-36〜R-42）**、**採点欄だけの救済（R-43〜R-51）** | 不合格0 |
| `--r122-publish-test` | 40 | 24 | P01〜P04（P-01〜P-24）、**未完了・資格・凍結K（P-25〜P-33）**、**直すところがある応答の順位（P-34〜P-40）** | 不合格0 |
| `--r122-read-test` | 29 | 29 | U01（A-01〜A-29）。公開後の取消表示・暫定表示を含む | 不合格0 |
| `--r122-native-test` | 29 | 29 | **本体へ直通**（N-01〜N-29）。729件・境界4件・取消4例・繰上がり・正本一致 | 不合格0 |
| `--r122-market-test` | 18 | 18 | 4.5の2条件とOPI・推定確定オッズ（M-01〜M-18） | 不合格0 |
| `--r122-prod-input` | 9 | 9 | 本番経路の入力をSchemaへ。D01〜D04の実データ側 | 不合格0 |
| `--r122-acq-test` | 13 | 13 | 監査行→採用→入力→凍結保存→読戻し。REPLAY分離を含む | 不合格0 |
| `--r122-invalid-cases` | **未実行** | 32 | 異常例32本（入力23・出力9）。**今回は回していないので合計から外した** | － |
| `--r122-cases` | 729＋4 | 729＋4 | 検証用関数側。**本体の試験とは分けて記載する** | 不一致0 |
| `--r122-fingerprint` | － | － | 元値不変の指紋。**比較証跡はまだ1組だけ** | 実行可 |
| `--r122-33races` | 33 | 33 | 33レースの候補比較（CSV出力） | 実行可 |
| `--r122-sweep` | － | （新規） | 止まったレースのやり直し。旗がoffなので何もしない | 実行可 |
| **合計（件数のあるもの）** | **887** | 767 | | **不合格0** |

算術は 601＋97＋51＋40＋29＋29＋18＋9＋13＝887 である。
前回の799から異常例32本を引くと767で、上の「前回」欄の合計と合う。

合格件数が多いことは正しさの証明ではない。**誤った期待値や未検査の処理は合格数からは分からない。**そのため、試験IDを添えられない項目は下に「未実装」として分けて書いた。

【差替え 2026-10-21】**未実装・未検証の表を、いまの現物に合わせて差し替える。**

> 差替え前（文書版1.1）の表はこうだった。4行が「未実装」、1行が「未接続」だった。
>
> | 項目 | 状態（文書版1.1） |
> | --- | --- |
> | 実AIの呼び出し | **一度もしていない。**受領時間・出力完了・サイズ・期限の実測は未実施。 |
> | 本番の凍結入力 | **0件。**`BAGANRIKI_R122_PROMPT=shadow`／`BAGANRIKI_R122_QUEUE=0`。 |
> | 応答JSONの厳密読込 | 未実装（第8章の追記を参照）。 |
> | 参照IDの実在・所有馬・SIGNAL／CONTEXTの検査 | 未実装。事実出典hashは記録している。 |
> | 10項目採点の再計算 | 未実装。辞書は正本どおり保持。 |
> | 異常出力の呼出経路 | `extract → checkEnvelope → isolateHorses` までは通した。訂正→順位→統合までは未接続。 |
> | Flutterの実機表示 | `flutter analyze lib` は警告0。実機での表示確認は未実施。 |
> | 既存AC01〜AC14の新契約更新 | 未着手。 |

いまの状態（文書版1.2）。

| 項目 | 状態 | 試験ID |
| --- | --- | --- |
| 実AIの呼び出し | **いまも一度もしていない。**受領時間・出力完了・サイズ・期限の実測は未実施。10月24日・25日の非公開検証で測る。 | なし（測れない） |
| 本番の凍結入力 | **いまも0件。**`BAGANRIKI_R122_PROMPT=shadow`／`BAGANRIKI_R122_QUEUE=0`。 | なし |
| 応答JSONの厳密読込 | **実装した。**`BaganrikiJson::strict()`。サイズ4MiB・深さ64・有効桁128・指数±308・重複キー・UTF-8・末尾ゴミ。送信前の自分の本文も通す。 | R122-J02（25） |
| 参照IDの実在・所有馬・SIGNAL／CONTEXTの検査 | **実装した。**`BaganrikiDiagnose::run()` の `collectRefs()` で入れ子のどこにある参照IDも拾い、実在・所有馬・自分のSIGNALを見る。馬ごとの内訳は `diag.ref_stats`。 | R-26〜R-35（10） |
| 10項目採点の再計算 | **実装した。**満点・合計・`observed_sum`／`observed_cap`・`total_score` の再計算と宣言値との不一致の診断。採点と出典の結びつきも見る。 | R-36〜R-51（16） |
| 異常出力の呼出経路 | **訂正→順位→統合→公開まで繋いだ。**本番の2関数から `r122AfterReceive()` を呼ぶ。第10章の差替えを参照。 | X-01〜X-30（30） |
| 訂正の実送信 | **経路は書いたが通っていない。**送る手前で断る3経路と依頼本文の中身までが試験済み。実送信は10月24日・25日でしか測れない。 | X-06〜X-19 |
| 時刻A案（OPI・推定確定オッズ） | **列は入れた。値はまだ入っていない。**集計2本に `computed_at` の記録を足し、2表に `DATETIME(6) NULL` の列を追加した（既存行はNULLのまま。無い時刻は作らない）。夜の集計が1回まわれば本物の時刻が入る。 | M-06・M-10〜M-14 |
| 元値の不変の証明 | **方式は直した。比較証跡はまだ1組だけ。**必須対象の欠落・読取失敗を合格にしない形にした。変更前後の指紋JSONの対を次回提出する。 | なし（運用で取る） |
| Flutterの実機表示 | `flutter analyze lib` は警告0。**実機での表示確認は未実施。** | なし |
| 既存AC01〜AC14の新契約更新 | **未着手。** | なし |

72レース回帰では正式mode39確定／33UNKNOWN、計算不能0という元計算を維持し、追加状態は39CONFIRMED／33PROVISIONALとする。これは旧データが新時刻契約に適合するとの判定ではない。

33レースではS0=1のFIRM対MIXED、S0=3のMIXED対VOLATILEを同じ検証済み全頭評価で比較する。両role配分が同じ6件も含め、馬番・順序・根拠・不足理由を提出する。評価が無ければ事前入力による再生評価を別記録で作り、当時の実予想や成績と混ぜない。

【実装追記 2026-10-20】**33レースの比較を実行した**。サーバ実測で**33レース**、候補が違うもの**27件**、同じもの**6件**。`--r122-33races` でCSVを出力する。保存済みの全頭評価をそのまま両側へ当てており、**再評価はしていない**（読み取りだけ）。

【差替え 2026-10-21】上の段落のあとに、文書版1.1ではこう書いていた。

> 「手元の試算では26／7になり1レースずれたが、原因が分かっている。10月18日・京都7日目9Rの7番が**最終スロットだけ取消**で、手元の簡易版がこの馬を除外していた。サーバ側が正しい。」

10月20日006のご指摘6-3は正しかった。**結論（サーバ側が正しい）は変わらないが、理由の書き方が正本と合っていなかった。**正本は「`input_freeze_at` までに利用可能な正式取消なら凍結集合から外す」であり、「6分前の取消は残す」という規則ではない。

実データで確かめた。10月18日・4回京都7日目9R（発走14:20、`num_horses=8`・出馬表8頭）の7番のオッズは、21分前25.8／18分前29.3／15分前30.3／12分前34.2／9分前36.6／**6分前39.8**／3分前47.6 と全スロットに実数値があり、`取消` の文字は**確定（`minutes_before_start = -999`）の行にしかない。**

したがって7番が凍結集合に残るのは「6分前の取消だから残す」ではなく、**`input_freeze_at` までに利用可能な正式取消が存在しなかったから**である。N=8 は `races.num_horses = 8` かつ出馬表8頭から来ており、取消による減少ではない。

コード（`modeCheckOne()`）は最初から正本どおりだった。取消が見えた最も早い時点を `slotOrd()` で比べ、6分前の時点以前なら `cancelPre`（外す）、6分前に実数値がありその後なら `cancelPost`（残す）、6分前が欠損で根拠が凍結後だけなら `cancelLate`（残す）に分ける。6分前の取得期限は `input_freeze_at` 以前である（試験 R122-CL06）。3分前の行は `input_freeze_at` より後なので使わない。間違っていたのは説明文だけだった。

対象2日間の取消は3件だけなので、`--r122-33races` の出力に実データの表を入れた（`@ANCHOR-20261020-R122-33CANCEL`）。

| レース | 馬番 | 取消が見えた時点（早い順） | 6分前の単勝 | 分類 | 凍結 | N／出馬表／num_horses |
| --- | --- | --- | --- | --- | --- | --- |
| 10/17 4回東京6日目5R | 5 | 21→18→15→12→9→6→3→確定 | （6分前は欠損） | `cancelPre` | 外す | 8／9／9 |
| 10/18 4回新潟2日目5R | 3 | 21→18→15→12→9→6→3→確定 | （6分前は欠損） | `cancelPre` | 外す | 14／15／15 |
| 10/18 4回京都7日目9R | 7 | 確定 のみ | 39.8 | `cancelPost` | 残す | 8／8／8 |

**この表には取得時刻の列が無い。**`t_horse_odds_finder_odds` に時刻の列は1つも無いので、「正式取消がいつ利用可能になったか」を時刻で示すことはできない。契約どおり、取得時点（スロット）の前後で判定している。この比較が読む時点は 21・18・15・12・9・6・3・確定 の8つで、999（最初の取得）は信号6点に含まれないため読んでいない。そのため、表の `cancel_slots` を直接数えた値（999を含む）とは999のぶんだけ並びが違う。

**この比較を作るときに本物の不具合が2件見つかった。**①`modeCheckOne()` は「馬番=>true」のマップを期待するのに `array_keys()` 済みのリストを渡していたため、キーが0〜N−1になって全72レースが「出馬表に無い馬番のオッズがある」で計算不能になっていた（本番の判定経路は正しい形で渡していたので本番は無事だった）。②`merge_result` の照合に場（`basho_code`）が入っておらず、東京(05)と京都(08)で `kaisuu`・`day`・`race` がそろうと別の場の行を拾っていた。どちらも直した。

最大18頭の実API試験では初回と必要な訂正の受領時間、出力完了、サイズ、期限を実測する。固定入力試験だけで実API合格としない。既存AC01〜AC14も本契約へ更新して証跡を残す。

過去データに要求・受信時刻が無い場合、数式と候補配分の回帰は本番取得の合否から切り離して検査する。実API結合試験で現在の期限が必要なら、保存済み馬データから別IDの試験用入力を作り、試験用時刻であることと元データへの対応を試験台帳に記録する。元の入力・時刻は上書きしない。この試験入力はREPLAYに隔離し、新開催の実取得合格として数えない。

## 14 導入と実装対応表

新機能フラグ、専用Job、読取APIから段階導入する。schema・prompt・configの版が揃わなければ起動判定を不合格にする。週次洗い替え前に原データ・実時刻・評価・応答・理由を保存し、照合できることを確認する。

10月23日は再生と実環境接続の判定、24日は選定2レースで新取得を含む非公開実測。合格後に未凍結レースから公開する。不具合時はこれから凍結するレースの新経路を止める。旧履歴を削除せず、同レースを旧AIへ自動で送り直さない。

【実装追記 2026-10-20】**段階導入にshadow段を追加した**。旗を `1` にした瞬間に、r12.2の入力が組めないレースで商品の予想が止まる。初日にそれを避けるため、`BAGANRIKI_R122_PROMPT` を `0`／`shadow`／`1` の3段にした。`shadow` は入力の組み立て・凍結保存・coverage・取得監査の紐づき・送信文の大きさを記録するが、**AIへ送る本文は旧版のまま**で、キューの枠も取らない。例外を外に出さない。

**10月24日・25日はこの `shadow` 段で動かすことを提案する。**商品の予想を落とさずに、実取得・実監査・実凍結の経路を実測できる。実AIの応答時間は1レースだけ手で呼ぶ形でも測れる。旗を `1` にして実送信する案もあるが、初めて実地で動かす段で商品を落とす側に賭けたくない。**ご判断をお願いしたい。**

【実装追記 2026-10-20】**公開日について。**今回見つかった4件（本体直通・取消4例の試験・日時の繰上がり・正本との一致検査）を含め、「修正済み」と書いて直っていなかった分の見積りを入れ直す必要がある。**現時点で公開日は約束できない。**是正込みの工程表は同梱の `02_是正込み工程表.md` に分けた。

以下は開発担当者が実コード確認後に埋める環境対応記録である。採用方針①〜③は再選択しない。

| 確認対象 | 記録する内容 | 現在の状態（2026年10月20日 実装担当が記入） |
| --- | --- | --- |
| レース・出馬表・順位 | 実テーブル・列・主キー・対象日条件 | `t_horse_odds_finder_races`（`date`・`kaisuu`・`basho`・`day`・`race`・`start_time`）、`t_horse_odds_finder_horses`（`num`・`name`）、`t_horse_odds_finder_odds`（`num`・`minutes_before_start`・`odds`・`fuku_min`・`fuku_max`）。レース識別子は `YYYYMMDD-回-場-日-R` 形式（`r122RaceId()`）。詳細は第4.1章の追記。 |
| 発走と投票締切 | 正本、取得元、列、前倒し通知経路 | 発走時刻は `t_horse_odds_finder_races.start_time`。**投票締切の列はDBに存在しない。**JRA公式の規則から `T − 60秒` として導出（`BaganrikiTime::salesCloseFromPost()`、出どころ名 `JRA_INTERNET_1MIN_BEFORE_POST`）。前倒しの通知経路は無く、`repairStartTime`（毎分9:00〜18:59）が発走時刻を直したら期限も引き直す。**「1分前」の秒の扱いはご判断待ち。** |
| 取得監査 | 要求・受信・利用可能時刻を記録する実コード箇所 | `app/Console/Commands/ImportKeibaOdds.php` の `r122Now()`・`r122RecordBatch()`（10月20日提出版から変更なし）。読込側は `r122GatherContext()`。採用は `BaganrikiTime::acceptBatch()`・`pickBatch()`。DB形式の変換は `r122DbTime()`・`BaganrikiWorker::dbUs()`。 |
| 正確な数値処理 | Laravel内の部品、JSONと桁・閾値境界の試験 | 多倍長整数のたすき掛けを `BaganrikiSignals` 内に自作（外部ライブラリを入れていない）。`usableDecimal()`・`r122NumValue()`・`BaganrikiSnapshot::scale()`。試験 R122-E07・J01。【差替え 2026-10-21】文書版1.1では「**応答側の厳密パーサは未実装**」と書いていた。**実装した**（`BaganrikiJson::strict()`、試験 R122-J02 25項目）。 |
| AI接続 | role別サービス、モデルID、生成設定、上限、終了条件、実測時間 | 既存の `AnthropicService` を使う（1st）。2nd は既存の DeepSeek の HTTP 呼び出し。`BAGANRIKI_R122_PROMPT` が `1` のときだけ r12.2 の本文で送る。待ち時間は応答期限から逆算（`r122AiTimeout()`。5秒残す・下限20秒・上限170秒）。訂正の送信は `r122CallAi()`（自動再試行0、`maxAttempts: 1`）。**実測時間はいまも未測定（実AIを一度も呼んでいない）。** |
| Queueと永続性 | 接続名、worker、タイムアウト、重複・再起動試験 | `BAGANRIKI_R122_QUEUE` は現在 `0`（off）。永続予約は `baganriki_ai_attempts`（`UNIQUE(snapshot_id,role,attempt_no)`・`UNIQUE(token)`）。重複送信の防止・結果不明の記録・再起動の後始末は `BaganrikiWorker` に実装し、試験で確認。【取り下げ 2026-10-21b】文書版1.2の「作らないことを選んだ。正本の指定との差なのでご判断をいただきたい」は取り下げる。**正本の Job 構成を維持する。Jobクラスは未実装である。**いまはHTTPと巡回で同じ順序を通している暫定。やり直しは `r122SweepPending()`／`--r122-sweep`、初回は `r122SendFirst()`。第10章の取り下げを参照。 |
| 実送信の後への接続 | 呼出経路、順序、例外時の扱い | 【実装追記 2026-10-21】`AiController::r122AfterReceive()`。`getHorseOddsFinderAiAnalysis()`（1st・`app()->terminating()` の中）と `getHorseOddsFinderSecondAiOpinion()`（2nd・その場）から呼ぶ。順序は 検証→訂正→解決→公開。公開は両roleが終端になってから（応答期限を過ぎたら待たない）。中で例外が出ても外へ出さず、旧来の予想は返す。旗の食い違い（`PROMPT=1`＋`QUEUE=0`）は `r122WireConfig()` が止める。試験 X-01〜X-30。 |
| APIとFlutter | 実ルート、認可、DTO、画面、配布方法、表示試験 | 読取APIの経路は `baganriki/r12-2/races`。Flutterは `lib/models/baganriki_r122_model.dart`（手書きクラス・`fromJson`）、`lib/controllers/baganriki_r122/baganriki_r122.dart`（Riverpod）、`lib/screens/components/baganriki_r122_display_alert.dart`。`race_content_page.dart` に「r12.2」ボタンを追加。`flutter analyze lib` は警告0。**実機での表示確認は未実施。** |
| リリース | 版一覧、コミット、担当工数、AC証跡、機能フラグ、切戻し手順 | 版一覧とSHA-256は同梱の `01_版とハッシュ.txt`。機能フラグは第2章の追記の7キー。切戻しは、サーバの `~/baganriki_20261018/before_v49_*/` に置換前のファイルを毎回退避しているので、それを戻して `php artisan config:clear` で元に戻る。**担当工数の再見積りと既存AC01〜AC14の更新は未着手。** |

参照：変更計画書 文書版1.2、r12.1実装仕様書・実装契約・入出力Schema・配分設定、提出2026-10-19_07のv8と確認結果。本書は実装構成、X6暫定例外、時刻契約と必要な版・項目を明示改訂する。
