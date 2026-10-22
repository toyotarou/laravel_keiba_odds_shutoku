# 読み下しメモ：AiController.php（現状）

- 作成：2026-10-22（木）JST　Claude
- 対象：`KEIBA/_____laravel/app/Http/Controllers/Api/AiController.php`
  - md5 `0f62cbf053e6b5bd570d8daf35c8f721`・30,552行・1,863,365バイト（本番サーバと同一。引き継ぎ資料より）
- このメモの役割：**現状（002）**の控え。よっしーの願望（001）との突合はまだしていない。
  - 突合しやすいように、11章に「001の章 → このファイルのどこを見るか」の索引を付けた
- 行番号はこのmd5の版のもの。版が変わったらずれる
- コードを読んだ結果であり、実行して確かめたものではない（7章は特に要確認）

---

## 0. ひとことで（現状）

**このファイルには「2つの予想の仕組み」が同居している。**

1. **旧経路（いま本番で客に出ているもの）**
   アプリが叩く `getHorseOddsFinderAiAnalysis`（1st）→ `getHorseOddsFinderSecondAiOpinion`（2nd）→ `_mergeAiResults`（統合）。
   プロンプトはこのファイルの中に直書き。断層タイプA〜E、資格F/R/O、配分モード「通常／中／強」で選ぶ
2. **r12.2 新経路（001で頼まれたもの・いまは旗で止めてある）**
   `r122*` 関数群＋末尾の `Baganriki*` クラス群。mode／X6暫定／A・B・D配分／時刻契約／予約枠／訂正／公開／読取API。
   プロンプト本文はこのファイルに無い（外部ファイルを読む）

さらに、その中間に **v0.4（X1〜X6・Z）の構造判定** が「記録だけ（shadow）」で動いている。r12.2 はこの v0.4 の部品を土台にしている。

---

## 1. ファイルの全体像

| 行 | 中身 |
|---|---|
| 12〜21333 | `class AiController`（メソッド196本） |
| 25 | `SHADOW_ONLY_KEYS`：Flutterへ返さない内部値の一覧 |
| 1078〜1145 | 一覧API（Record／Record2） |
| 1146〜1730 | **旧1st：`getHorseOddsFinderAiAnalysis`** |
| 1774〜2132 | v0.3 構造判定（記録のみ・v0.4に置換済み） |
| 2133〜2576 | 補充方式（FILL_MODE）・v0.4 スイッチと定数 |
| 2577〜4343 | v0.4 の帯・根拠・選出・H/V採点（研究用） |
| 4344〜6062 | `v04SelfTest`（1,719行の自己試験） |
| 6068〜8805 | **旧1stプロンプト生成：`_getAiAnalysisPrompt`**（2,738行） |
| 8806〜11371 | **旧2nd：`getHorseOddsFinderSecondAiOpinion`**（2,566行。2ndプロンプト・統合呼出し・保存・応答） |
| 11372〜15296 | 旧経路の部品（採点の解析、DB照合、統合 `_mergeAiResults` 12265〜、市場妙味、保存など） |
| 15297〜15354 | 機械学習の中継（本体は `BaganrikiMl` へ移設済み） |
| 15355〜15589 | 馬眼力指数API：`getHorseOddsFinderBaganrikiIndex` |
| 15590〜15841 | **r12.2 読取API：`getBaganrikiR122Race`** |
| 15842〜21333 | **r12.2 の経路：`r122*` 関数群** |
| 21334〜30552 | 末尾の15クラス（下表） |

末尾のクラス：

| クラス | 行 | 役割 |
|---|---|---|
| `BaganrikiSignals` | 21334 | 十進比較、X1〜X6・Z の算出、mode の判定（`v04Classify`） |
| `BaganrikiAllocation` | 22082 | 目標C、A/B/D配分、構造調整、2nd配分、帯 |
| `BaganrikiTime` | 22277 | 時刻契約（スロット猶予、cutoff、凍結、締切、公開・応答期限） |
| `BaganrikiAcquisition` | 22510 | 取得バッチの採用 |
| `BaganrikiSnapshot` | 22648 | 集合照合、凍結順位、共通JSON |
| `BaganrikiRanking` | 22862 | 帯内順位、統合、補充（`fillShortfall`）、取消後の作り直し |
| `BaganrikiResponse` | 23155 | AI応答の抽出、外形検査、訂正の受入れ |
| `BaganrikiPublicView` | 23671 | 読取APIの返却形、暫定表示文、診断コード |
| `BaganrikiMl` | 23797 | 断層学習（COLLECT_ONLY・本番の選出には未使用） |
| `BaganrikiPrompt` | 27152 | r12.2 本文の読込み、INPUT_JSON 組立て、hash |
| `BaganrikiWorker` | 28057 | 予約枠・受領・失敗・再開・旗（QUEUE/JOBS/ENFORCE） |
| `BaganrikiDiagnose` | 28751 | 応答の診断 |
| `BaganrikiDecimal`／`EmptyObject`／`Json` | 29456〜 | 桁を保つJSON・十進数 |

---

## 2. 旧経路の流れ（いま客に出ているもの）

### 2-1 1st（1146行〜）
1. `t_horse_odds_finder_ai_analysis` にあればキャッシュを返す（内部JSONは `publicPart` で落とす）。返したあと `r122AfterCached` を呼ぶ（r12.2 の後始末・旗が下りていれば何もしない）
2. レース排他ロック → もう一度キャッシュ確認
3. `_getAiAnalysisPrompt` でプロンプト生成。作れなければ空回答を保存して HTTP 200（レースを止めない方針 `@ANCHOR-20260928-NO-RACE-STOP`）
4. プロンプトを `public/prompt/prompt_{date}_{kaisuu}_{basho}_{day}_{race}.data` に保存（2nd が再利用する）
5. **`public/baganriki_brain/baganriki_brain.txt` があれば末尾に「参考情報」として追記**（1320行）
6. system プロンプトは1343行の固定文（1st AI＝回収率優先、DB値の再計算禁止など）
7. r12.2 の旗によって分岐（1398〜1496行。7章参照）
8. Anthropic へ1回だけ送信（`maxAttempts: 1`・待ち時間90秒、H/V研究時240秒）。ログ上のモデル名は `claude-haiku-4-5`（実際のモデルは AnthropicService 側で決まる・このファイル外）
9. 原文を `t_horse_odds_finder_ai_analysis` に保存 → 返却
10. 応答後（`app()->terminating`）に **2nd を先読み実行**（1668行）

### 2-2 2nd（8806行〜）
1. キャッシュ（`ai_analysis2`）と保存済み統合結果を返せるなら返す
2. 1st の完了が前提（無ければ 409）
3. プロンプトは 1st が保存した `.data` を読み、2nd 用に整形（厳選穴判定・配分ブロック等を除去）。除去前に条件B/C/Dと配分を読み戻して凍結値として使う
4. system は 9669行のヒアドキュメント（出力形式の厳守、「自由に遊んでいい。ただし、真剣にやれ」、独立評価）
5. DeepSeek `deepseek-chat` へ送信（待ち60秒、H/V研究時120秒）
6. DB照合（Block 16）→ 統合 `_mergeAiResults` → 最低基準点・低配当・回収率の**購入回避フラグ**（Block 13b/13a/12・候補からは外さない）→ 厳選穴の再判定（Block 15）→ `ai_merge_result` へ保存（Block 14）
7. Flutter へ返すもの：`analysis_text`、`merged_horses`（`SHADOW_ONLY_KEYS` を除去）、`upset_race`／`upset_race_status`、`race_metrics`（波乱度・下位進入度・大穴進入度）

### 2-3 旧経路の選び方の要点
- 目標C：`targetSelectionCount`（970行）N≦8→min(N,4)、9〜13→5、14〜15→6、16〜→7
- 断層タイプ A〜E（7238〜7282行）：6番人気以内の単勝断層（比率2.0以上）の数、単複の矛盾などで決定
- **資格 F/R/O**（7285〜7401行）：資格あり＝7番人気以下 かつ F 成立 かつ（R または O）
  - F1：複勝最小 21→6分で10%以上短縮・5区間中2区間以上短縮・9→6分で上昇なし／F2：9→6分で5%以上短縮・12→9でも短縮・12→6で合計短縮
  - R：単勝人気−複勝人気 ≧2／O：予測補正OPI ≦0.85
- **配分モード 通常／中／強**（7493〜7561行）：人気側（1〜6番人気）＋資格あり穴側の2区分
  - C4：通常3+1／中3+1／強2+2、C5：4+1／3+2／2+3、C6：4+2／3+3／3+3、C7：5+2／4+3／3+4
  - 中・強に入るのは「条件B/C/Dが全部不成立 かつ 資格ありの7〜10番人気が1頭以上」のときだけ
- 2nd の独自発見枠（12303行）：タイプA 0頭、B/C 1頭、D/E 2頭。2nd の上限5頭
- おすすめ度：信頼度60（7項目）＋妙味40（4項目）。全頭採点JSONを `<<<BAGANRIKI_ALLSCORE_BEGIN>>>`〜`END>>>` で受け取る
- 厳選穴レース（1/0/不明）、レース指標（波乱度・下位進入度・大穴進入度）を AI に出させ、PHP が再判定

---

## 3. 旧経路のプロンプト（目次）

### 3-1 1st（`_getAiAnalysisPrompt`）
各馬のデータ行（単勝・複勝の時系列、流入ランク、OPI、推定確定オッズ、回収率、類似統計）を並べたあと、次の見出しが続く。

| 行 | 見出し |
|---|---|
| 7955 | 【断層構造タイプ（PHP算出済み）】＋タイプ別の方針 |
| 7966 | 【波乱度の修正ルール】 |
| 7982 | 【断層時系列（単勝・6分前人気順基準）】 |
| 7994〜 | 注目馬番①断層 ②期待数値 ③過去走から絞った馬番 |
| 8018 | 【⚠️ 絶対ルール：DB・PHP算出値は変更・再計算禁止】 |
| 8028 | 【このシステムの目的（最重要）】回収率 |
| 8036 | 【このAIの過去回収率実績】（データがあるときだけ） |
| 8060〜 | 実出走頭数と目標数C |
| 8072／8081 | 人気帯別の優先目安（配分なしのとき）／断層タイプ |
| 8086 | 【能力・適性評価（100点満点・6項目）】 |
| 8105 | 【配分（PHP算出済み・変更禁止）】N／C／配分モード／資格あり馬番／資格根拠／資格判定（三値） |
| 8168 | 【入力スナップショット】snapshot_id |
| 8179 | 【7番人気以下で根拠を確認した馬（参考）】 |
| 8197 | 【出力フォーマット（厳守）】厳選穴レース行／レース指標行／「馬番：…」行 |
| 8210 | 【全頭採点（内部用）】JSONの書式 |
| 8229 | 【厳選穴レースの判定ルール】条件A〜D |
| 8255 | 【おすすめ度の計算方法】信頼度60＋妙味40の内訳 |
| 8302 | 【低配当の採点制限】 |
| 8314 | 【回収率の扱い】 |
| 8321〜8380 | 選出ルール、目標数、タイプD・E時の人気帯別条件、11番人気以下の材料、選出理由の記述ルール、下位進入度・大穴進入度 |
| 8417〜 | 【各馬の直近成績（過去最大10走）と今走データ】（`shutsuba_history` から対象日より前の最大10走） |
| 8582 | 【最後に・出力の締めくくり】（全頭採点の出力を念押し） |

### 3-2 2nd（`getHorseOddsFinderSecondAiOpinion`）
- user は 1st と同じ本文を整形して使う（配分・厳選穴判定ブロックなどを除去）
- system（9669行〜）：【絶対厳守】出力フォーマット／【唯一の例外 ― 全頭採点（内部用）】／【あなた（2nd AI）へ ― 最も重要なメッセージ】／【能力・適性評価】

---

## 4. v0.4（X1〜X6・Z）— 記録だけ

- 旗 `BAGANRIKI_V04_SHADOW`（既定 true）で計算して監査ログへ。`BAGANRIKI_V04_ENABLED`（既定 false）
- **【要確認】`v04Enabled()` は監査ログの表示（2156・2643行）でしか使われていない。** true にしても旧経路の選出は変わらない作りに読める
- 信号：S = 2·X1 + X2 + X3 + X4 + X5 + X6 − 2·Z（2295行）。S≦1 FIRM、2〜3 MIXED、4以上 VOLATILE
- 閾値（2283行）：単勝の壁2.00、複勝の壁1.50、類似の必要件数50 ほか
- `V04_X6_BASELINE = null`（2368行）なので、X6 は N≧7 で常に UNKNOWN

---

## 5. r12.2 新経路の中身（旗が上がったら使われるもの）

### 5-1 mode と X6（`BaganrikiSignals`）
- X6（21800行〜）：N<7 → FALSE、N≧7 かつ基準率未設定 → UNKNOWN（B案）
- `v04Classify`（21915行）：不明信号の全組合せで取り得る mode を列挙
  - 1つに決まる → CONFIRMED／`BASE_TRI_STATE_V1`
  - 不明が X6 だけで S0=1 → PROVISIONAL・配分MIXED／S0=3 → PROVISIONAL・配分VOLATILE（`X6_ONLY_UPPER_MODE_V1`）
  - それ以外 → UNRESOLVED・配分なし・`selection_enabled=false`
  - 星（display_rating）は CONFIRMED で星が1つに決まるときだけ
  - 旧規則「不明3信号以上なら UNKNOWN」「UNKNOWN なら MIXED を流用」はコメントで残してある

### 5-2 配分（`BaganrikiAllocation`・定数は2319行）
- 目標C：`v04TargetCount`（N≦8→min(N,4) など旧と同じ式）
- 配分表 `V04_QUOTA_BY_TARGET`：C7 FIRM 5/2/0・MIXED 3/3/1・VOLATILE 3/3/1、C6 4/2/0・3/2/1・3/2/1、C5 4/1/0・3/2/0・2/2/1、C4 3/1/0・2/2/0・2/1/1（C7 MIXED は旧 4/2/1 から変更済み）
- C≦3 は C/0/0、N=0 は配分なし、`allocation_mode=null` は配分なし
- 構造調整 `v04StructuralAdjust`：D不足→B→A、B不足→D→A、A不足→B→D
- 2nd 配分 `v04SecondQuota`：C≦5 は1stと同じ、C≧6 は A=5−B−D
- 評価後の不足 `BaganrikiRanking::fillShortfall`（23028行）：D不足→Bのみ、B不足→Dのみ、A不足→B→D

### 5-3 時刻（`BaganrikiTime`）
- スロット猶予：21/18/15/12/9分前 120秒、6分前 30秒。3分前・発走時は取消の見張りだけ
- cutoff＝T−360秒、入力凍結＝T−330秒、安全余裕60秒、応答は公開の30秒前
- 投票締切＝**発走時刻−60秒で導出**（出どころ名 `JRA_INTERNET_1MIN_BEFORE_POST`、JRA公式「ネット投票は発走1分前」）。`BAGANRIKI_SALES_CLOSE_DERIVE=0` で導出を止められる
- 時刻文字列は RFC3339・小数6桁まで。存在しない日付・時刻の繰上がりは拒否

### 5-4 取得と凍結
- `r122GatherContext`（15873行）：races・horses・odds を読み、6時点＋取消の見張り2時点を分ける。`baganriki_acquisition_batches` があれば要求・受信・利用可能時刻つきのバッチを読む
  - **このファイルは `baganriki_acquisition_batches` を読むだけで、書いていない**（書く側はこのファイル外）
- `r122Freeze`（16620行）：出馬表欠損・集合不一致・6分前単勝欠損などで停止。`baganriki_snapshots` に不変保存
- 診断コード（23758行）：ENTRY_MISSING、ENTRY_SET_MISMATCH、FREEZE_ODDS_MISSING、CANCEL_CONFLICT、ACQUISITION_DEADLINE_EXCEEDED、MODE_UNRESOLVED、AI_FAILURE ほか

### 5-5 送信・受領・訂正・公開
- 本文：`BaganrikiPrompt`。**プロンプト本文はこのファイルに無い。** `storage/app/baganriki/prompts/r12_2/` の `prompt_1st.txt`・`prompt_2nd.txt`・`common_prompt.txt` を読み、マーカー・版・未置換記号・UTF-8 を確かめて SHA-256 を記録（`BAGANRIKI_R122_PROMPT_DIR` で場所を変えられる）
- INPUT_JSON の必須キー35件（27175行）、coverage 7区分（27187行）
- 共通hash：`ai_role`・`role_cap`・`role_quota`・`snapshot_hash` を除いた本文を、キー昇順・余白なしで SHA-256（`exact-common-payload-sha256-r8`）
- 予約枠：`baganriki_ai_attempts`（attempt_no 0 と 1）。予約を確定してから1回だけ送る
- `r122SendFirst`（20637行）／`r122SendFix`（20497行）／`r122CallAi`（20401行：1st は Anthropic、2nd は DeepSeek `deepseek-chat`・max_tokens 8192）
- 応答検証 `r122InspectResponse`（17476行）、訂正 `BaganrikiResponse::acceptCorrection`（23303行）、公開 `r122Publish`（18043行）
- 再開：`r122SweepPending`（21160行。`php artisan keiba:ai-analysis-compensate --r122-sweep` から呼ぶ想定）
- Job：`r122DispatchForRace`（19769行）が `App\Jobs\R122*` を積む唯一の入口

### 5-6 読取API（`getBaganrikiR122Race`・15590行）
- 守り：`r122ReadGate`（20131行）。`BAGANRIKI_R122_READ` 既定 key。鍵 `BAGANRIKI_R122_READ_KEY` がヘッダ `X-R122-Key` かクエリ `r122_key` で一致したときだけ返す。鍵が未設定なら誰にも返さない。断るときも HTTP 200（state=UNAVAILABLE）。artisan からは素通り
- 返す形（`BaganrikiPublicView::build` 23697行）：mode、mode_status、allocation_mode、mode_policy_id、display_rating（暫定時 null）、provisional（「混戦として暫定選出」「波乱として暫定選出」＋「過去類似判定が未確定のため」）、publication、live_state（取消・現在頭数・3つの revision・実効期限）、worker_state、input_audit（取得監査・凍結時刻・coverage・時刻契約の版・締切と出どころ）、diagnostics、shortfalls、`buy_recommended=false`、`learning_stage=COLLECT_ONLY`、`model_version=null`
- 数値は十進数文字列のまま返す

### 5-7 版の定数
- `SCHEMA_VERSION = baganriki-v041-r12-2`、`PROMPT_VERSION = baganriki-v041-r12-2-20261020`（27157行）
- 時刻契約 `baganriki-post-slot-grace-v1`、`SLOT_FIRST_VALID_COMMITTED_V1`、`anchor_kind=POST_TIME`（読取APIの返却に固定値で入る）
- マーカー `<<<BAGANRIKI_V041_R8_BEGIN>>>`／`END>>>`

---

## 6. 旗（.env）の一覧

| 旗 | 既定 | 意味 |
|---|---|---|
| `BAGANRIKI_R122_PROMPT` | 0 | 0＝旧本文のみ／shadow＝r12.2入力を作って凍結保存だけ（送信は旧本文）／1＝r12.2本文で送る |
| `BAGANRIKI_R122_QUEUE` | 0 | 0＝枠なし／shadow＝記録だけ（失敗しても止めない）／1＝契約どおり止める |
| `BAGANRIKI_R122_JOBS` | 0 | 1 かつ QUEUE=1 のとき Job 構成で動かす |
| `BAGANRIKI_R122_TARGETS` | 空 | 対象 race_id を読点区切り |
| `BAGANRIKI_R122_TARGETS_ENFORCE` | 0 | 対象限定を効かせる。**JOBS が有効なら値に関係なく効く**（28132行） |
| `BAGANRIKI_R122_READ`／`_READ_KEY` | key／空 | 読取APIの守り |
| `BAGANRIKI_R122_PROMPT_DIR` | 空 | r12.2本文の置き場所 |
| `BAGANRIKI_SALES_CLOSE_DERIVE`／`_OFFSET_SEC` | 1／60 | 締切の導出 |
| `BAGANRIKI_PAST_RUNS_MAX` | 未設定 | 1頭あたりの過去走数 |
| `BAGANRIKI_MARKET_PRIOR` | 1 | OPI・推定確定オッズを載せるか |
| `BAGANRIKI_V04_ENABLED`／`_SHADOW`／`_RESEARCH_EXTREME`／`_HV_PROMPT`／`_ML_STAGE` | false／true／false／false／COLLECT_ONLY | v0.4 系（4章） |
| `BAGANRIKI_V03_ENABLED` | false | v0.3（記録のみ） |
| `BAGANRIKI_LABEL_FIX` | false | 短縮率・変化率の向きを言葉で添える |
| `BAGANRIKI_FILL_MODE` | A | 補充方式（A＝資格未達の7番人気以下で数合わせしない） |
| `KEIBA_ALLOC_20261004` | true | 旧経路の可変配分（通常／中／強）を使うか |
| `DEEPSEEK_API_KEY` | ― | 2nd の鍵（値は資料に書かない） |

`BAGANRIKI_R122_AUDIT` はこのファイルには出てこない（別ファイルで使われているはず）。

---

## 7. 旗の組み合わせで何が起きるか（コードを読んで分かったこと）【要確認・重要】

実行はしていない。24日の手順に関わるので、作業前に必ず確かめること。

1. **PROMPT=shadow のままだと、r12.2 の AI 送信は1回も起きない**
   - `r122SendFirst`（20637行〜）は `BaganrikiPrompt::sending()`（＝PROMPT=1）でなければ `FLAG_OFF` で何もしない
   - Job の `R122RunRoleJob` が呼ぶのも `r122SendFirst` だけ（作業一式の Jobs を grep して確認）
   - 引き継ぎの24日手順（PROMPT=shadow のまま QUEUE=1・JOBS=1）だと、入力の凍結までは進むが、**新しい予想は作られない**読みになる
2. **PROMPT=1 にすると、旧経路の入口（アプリが叩く1st・2nd）の送信本文が r12.2 に置き換わる**（1430行・9851行）
   - この置き換えの経路には**対象レース（TARGETS）の確認が無い**。`r122TargetCheck` を呼んでいるのは SendFirst・SendFix・SweepPending・AfterCached・DispatchForRace の5か所（＋Job）だけ
   - 置き換えた応答（r12.2 形式）は旧テーブル `t_horse_odds_finder_ai_analysis` に保存され、`analysis_text` としてアプリへ返る。**旧画面が「馬番：…」行を読めず、全レースで候補が出なくなる**おそれがある
3. **TARGETS の限定は JOBS=1（かつ QUEUE=1）なら自動で効く**（`targetsEnforced()` 28132行）。001メモ10章⑥は訂正済み
4. **r12.2 が動くきっかけ**：アプリが旧1stを叩いたとき（キャッシュ返却後の `r122AfterCached`、送信後の terminating）、または `--r122-sweep` の巡回。引き継ぎでは crontab は未変更なので、巡回は動いていない
5. 旗が全部下りている今は、旧経路は1バイトも変わらない（各所が `enabled()`／`sending()` で素通り）

---

## 8. DB テーブル

| テーブル | 主な使い方 |
|---|---|
| `t_horse_odds_finder_races`／`_horses`／`_odds`／`_shutsuba_history` | 正本の読取り（レース・出走馬・オッズ・過去走） |
| `t_horse_odds_finder_ai_analysis`／`_ai_analysis2` | 旧1st・2nd の原文キャッシュ |
| `t_horse_odds_finder_ai_merge_result` | 旧統合結果 |
| `_similar_race_stats`、`_popularity_rank_average`／`_median`、`_fuku_popularity_rank_average`、`_odds_gap_recovery`、`_opi_recovery`、`_phase_pattern_recovery`、`_ai_recovery`、`_compute_odds_correction`、`_forecast_from_last_race` | 旧プロンプトの材料（統計・回収率・補正） |
| `_race_results`／`_race_result_payout`／`_race_result_history`、`_ml_snapshot`、`_ai_analysis_check` | 事後の記録・学習 |
| `baganriki_snapshots`、`baganriki_ai_attempts`、`baganriki_live_states`、`baganriki_audit_events`、`baganriki_validation_results`、`baganriki_race_runs`、`baganriki_publications`、`baganriki_publication_intents`、`baganriki_acquisition_batches` | r12.2 の記録（acquisition_batches は読むだけ） |

---

## 9. AI の呼び方

- 1st：`AnthropicService::sendWithRetry`（`maxAttempts: 1`＝自動再試行なし）。モデル名はログでは `claude-haiku-4-5`
- 2nd：DeepSeek `https://api.deepseek.com/v1/chat/completions`、`deepseek-chat`
- 旧経路は1レースにつき 1st＋2nd の2回まで。待ち時間は r12.2 の予約があるときだけ `r122AiTimeout` で応答期限から決める

---

## 10. このファイルの外にあって、今回読んでいないもの

- `routes/api.php`（どのメソッドが入口か。引き継ぎでは7本）
- `app/Jobs/R122*.php`（5本。7章①のために grep だけした）、`app/Console/Commands/SummaryAiAnalysisCompensate.php`（巡回・試験）
- r12.2 本文 `storage/app/baganriki/prompts/r12_2/*.txt`、`config/r122_contract.json` など
- `AnthropicService`（1st の実モデル）、取得バッチを書く側（`ImportKeibaOdds` など）
- Flutter 側

---

## 11. 001 と突合するときの索引（判断はまだしていない）

| 001 の章 | このファイルで見る場所 |
|---|---|
| 1章 確定4点 | X6：21800行／暫定配分：`v04Classify` 21915行／取得猶予：`BaganrikiTime::GRACE_MS` 22280行 |
| 2章 mode・暫定 | `v04Classify`、`BaganrikiPublicView::provisionalLabel` |
| 3章 人気帯と配分 | `V04_QUOTA_BY_TARGET` 2319行、`BaganrikiAllocation`、`fillShortfall` 23028行／旧：資格F/R/O 7285行、通常／中／強 7493行 |
| 3章 禁止する足切り | 旧：Block 13b/13a/12 は「購入回避フラグ」（10459行〜）／`FILL_MODE`／r12.2：`r122InspectResponse` |
| 4章 時刻と入力固定 | `BaganrikiTime`、`BaganrikiAcquisition`、`r122GatherContext` 15873行 |
| 5章 応答・公開期限 | `BaganrikiTime::publishDeadline`〜`effectiveDeadlines`、`BaganrikiWorker::deadlines`、`R122_FIX_*` 20227行 |
| 6章 DB読込の修正A〜C | `r122GatherContext`（取消の前後分け）、`r122Freeze`、診断コード 23758行 |
| 7章 Laravel構成・保存 | `r122*`、`BaganrikiWorker`、`baganriki_*` テーブル、`r122DispatchForRace` |
| 7章 既存認証の読取API | `getBaganrikiR122Race` 15590行、`r122ReadGate` 20131行 |
| 8章 AI本文 | `BaganrikiPrompt`（本文は外部ファイル）／旧：3章の目次 |
| 8章 Flutter | `BaganrikiPublicView::build` 23697行（Flutter 側は未読） |
| 8章 版 | 27157行、`BaganrikiPublicView::build` |
| 9章 受入試験 | `v04SelfTest` 4344行（R122-* の検査を含む）、`BaganrikiDiagnose` |
| 10章 切戻し・旗 | このメモの6章・7章 |
