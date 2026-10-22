# 馬眼力 詳細設計書

文書版：1.0  
作成日：2026年10月19日  
対象：r12.2、Laravel＋Flutter、既存MySQL、Laravel Queue  
対応文書：変更計画書 文書版1.2、基本設計書 文書版1.0

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

## 4 DB入力と取得監査

### 4.1 既存データとの境界

対象日の正本レース一覧を先に取得する。各レースを必ず結果集合へ残し、オッズ全欠損の日を`continue`で読み飛ばさない。「開催なし」「レース中止」「対象レースのデータ欠損」は別状態にする。

既存オッズの `num`、`minutes_before_start`、`odds`、`fuku_min`、`fuku_max` はそれぞれ馬番、スロット、単勝、複勝最小、複勝最大へ対応づける。実テーブル名と出馬表・レース情報の列は対応表へ記録する。文字列「取消」は正式取消として分離し、数値へ変換しない。

`get_datetime`は今回の提出資料では保存完了時刻である。取得元更新時刻や応答受信時刻として流用しない。過去に無かった時刻は推定で作らない。

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

## 5 信号とX6暫定規則

### 5.1 維持する信号式

凍結順位kとk＋1の同じ馬ペアを全時点で使う。Wは単勝の下側÷上側、Pは複勝最小の下側÷上側。短縮率は `(以前−以後)÷以前`。正の元値を正確な分数として比較し、表示用に丸めた比率を使用しない。

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

## 11 統合と公開

### 11.1 順位と最終候補

各帯の凍結頭数をK、role内の検証済み順位をrとし、順位点を`100×(K−r＋1)÷K`とする。統合点は両roleの最大値。両AIの検証済み原公開候補であることを証明できる場合だけ＋5。現在の混在行から一致を推測しない。

同点は原公開候補の両AI一致、独立根拠群数、馬番の昇順を使う。点数比較も正確に行う。勝率・期待値・おすすめ度100点とは表示しない。

統合後にライブ取消を除き、残った馬の凍結帯とactive_countから構造配分を調整する。第6章の評価後不足規則で最終候補を選ぶ。馬番、選出順、割当、根拠、shortfalls、使用応答hashを保存する。

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

72レース回帰では正式mode39確定／33UNKNOWN、計算不能0という元計算を維持し、追加状態は39CONFIRMED／33PROVISIONALとする。これは旧データが新時刻契約に適合するとの判定ではない。

33レースではS0=1のFIRM対MIXED、S0=3のMIXED対VOLATILEを同じ検証済み全頭評価で比較する。両role配分が同じ6件も含め、馬番・順序・根拠・不足理由を提出する。評価が無ければ事前入力による再生評価を別記録で作り、当時の実予想や成績と混ぜない。

最大18頭の実API試験では初回と必要な訂正の受領時間、出力完了、サイズ、期限を実測する。固定入力試験だけで実API合格としない。既存AC01〜AC14も本契約へ更新して証跡を残す。

過去データに要求・受信時刻が無い場合、数式と候補配分の回帰は本番取得の合否から切り離して検査する。実API結合試験で現在の期限が必要なら、保存済み馬データから別IDの試験用入力を作り、試験用時刻であることと元データへの対応を試験台帳に記録する。元の入力・時刻は上書きしない。この試験入力はREPLAYに隔離し、新開催の実取得合格として数えない。

## 14 導入と実装対応表

新機能フラグ、専用Job、読取APIから段階導入する。schema・prompt・configの版が揃わなければ起動判定を不合格にする。週次洗い替え前に原データ・実時刻・評価・応答・理由を保存し、照合できることを確認する。

10月23日は再生と実環境接続の判定、24日は選定2レースで新取得を含む非公開実測。合格後に未凍結レースから公開する。不具合時はこれから凍結するレースの新経路を止める。旧履歴を削除せず、同レースを旧AIへ自動で送り直さない。

以下は開発担当者が実コード確認後に埋める環境対応記録である。採用方針①〜③は再選択しない。

| 確認対象 | 記録する内容 | 現在の状態 |
| --- | --- | --- |
| レース・出馬表・順位 | 実テーブル・列・主キー・対象日条件 | 開発担当者が実環境で記入 |
| 発走と投票締切 | 正本、取得元、列、前倒し通知経路 | 開発担当者が実環境で記入 |
| 取得監査 | 要求・受信・利用可能時刻を記録する実コード箇所 | 開発担当者が実環境で記入 |
| 正確な数値処理 | Laravel内の部品、JSONと桁・閾値境界の試験 | 開発担当者が実環境で記入 |
| AI接続 | role別サービス、モデルID、生成設定、上限、終了条件、実測時間 | 開発担当者が実環境で記入 |
| Queueと永続性 | 接続名、worker、タイムアウト、重複・再起動試験 | 開発担当者が実環境で記入 |
| APIとFlutter | 実ルート、認可、DTO、画面、配布方法、表示試験 | 開発担当者が実環境で記入 |
| リリース | 版一覧、コミット、担当工数、AC証跡、機能フラグ、切戻し手順 | 開発担当者が実環境で記入 |

参照：変更計画書 文書版1.2、r12.1実装仕様書・実装契約・入出力Schema・配分設定、提出2026-10-19_07のv8と確認結果。本書は実装構成、X6暫定例外、時刻契約と必要な版・項目を明示改訂する。
