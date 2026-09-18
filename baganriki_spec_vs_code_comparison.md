# 馬眼力 仕様書 vs 現行コード 比較・改造計画書

**作成日**: 2026-09-11  
**対象仕様書**: 馬眼力_エンジニア提出用_最終確定版_固定配点・断層パターン学習強化_20260910.txt  
**対象コード**: AiController.php（/app/Http/Controllers/Api/AiController.php）

---

## 読み方の案内

各ブロックは以下の構成で書いています。

- **仕様書の定義**: 新仕様書がどう定めているか
- **現状コード**: 今のコードがどうなっているか
- **差分**: 何が足りていないか／間違っているか
- **改造内容**: 具体的に何を直せばいいか
- **優先度**: 🔴 必須（Flutterへの表示に直接影響）／🟡 重要（品質・精度に影響）／🟢 将来（シャドー検証後）

---

---

## ブロック1【🔴 必須】出力フォーマット：「レース指標」行の欠落

### 仕様書の定義

AIの出力は3行構成が必須：
```
1行目: 厳選穴レース|1または0
2行目: レース指標|波乱度: X|下位進入度: X|大穴進入度: X
3行目以降: 馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜
```

「波乱度・下位進入度・大穴進入度」はAIが1〜5の整数で判定して出力する。  
この行はFlutterがパースして画面表示に使う。

### 現状コード

プロンプトの出力指示には以下の2行しかない：
```
厳選穴レース|1または0
馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜
```

「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」が**完全に抜けている**。

また `_parseAiHorses()` はこの行を読み込む処理を持っていない。  
`getHorseOddsFinderSecondAiOpinion()` では2nd AIプロンプトから「レース指標行を除去」する処理が必要だが、そもそも1st AI側に存在しないため除去もされていない。

### 差分

- プロンプトの出力指示に2行目が抜けている
- AIへの「波乱度・下位進入度・大穴進入度を判定する」指示も抜けている
- PHPがこの行をパースして保存・利用するロジックが存在しない

### 改造内容

1. `_getAiAnalysisPrompt()` の出力指示セクションに2行目の出力指示を追加
2. 「波乱度・下位進入度・大穴進入度の判定方法」の指示文をプロンプトに追加（仕様書「断層構造タイプ・波乱度ルール」セクションの内容）
3. `getHorseOddsFinderAiAnalysis()` でAI回答から「レース指標|...」行を抽出して保存する処理を追加
4. `getHorseOddsFinderSecondAiOpinion()` でDeepSeekへ送るプロンプトからこの行の出力指示を除去
5. `t_horse_odds_finder_ai_analysis` テーブルに `wave_level`・`lower_entry_level`・`big_gap_entry_level` カラムの追加（またはJSON列に保存）を検討

---

---

## ブロック2【🔴 必須】1st AI（Claude）システムプロンプト

### 仕様書の定義

システムプロンプトは以下の通り（仕様書に全文掲載）：

> あなたは競馬オッズ分析の専門家（1st AI）です。入力された取得開始S〜発走6分前までの全頭データだけを使用し、全頭を評価してから候補を決定してください。DB・PHP算出済みの人気順、OPI、流入ランク、推定確定オッズ、断層構造タイプ、厳選穴レース条件を再計算・変更してはいけません。3分前、確定オッズ・確定人気、実着順、払戻金その他発走後情報、入力に存在しない情報を使用・推測・創作してはいけません。主目的は的中頭数ではなく長期回収率の向上です。最低基準点、低配当除外、回収率フィルター、断層位置別・人気帯別上限を順守し、上限を埋めるための追加をしてはいけません。能力・適性は時系列オッズの補強材料として評価し、能力・適性だけで候補を決めてはいけません。有料公開するため正しい日本語を使用し、ユーザープロンプトで指定されたFlutter互換フォーマット以外の前置き・後書き・見出し・補足を出力してはいけません。

仕様書の指示：「既存コード側に別のClaudeシステムプロンプトがある場合は**併用せず**、上記へ**置換**する」

### 現状コード

`AnthropicService::sendWithRetry()` 内でシステムプロンプトがどう設定されているかは今回の確認範囲外。  
`baganriki_brain.txt` を補足情報として読み込んでいる。

### 差分

システムプロンプトの内容が仕様書と一致しているかどうか未確認。特に「能力・適性は補強材料に過ぎない」という制約が含まれているかが重要。

### 改造内容

1. `AnthropicService` のシステムプロンプトを仕様書の全文に置換（または `getHorseOddsFinderAiAnalysis()` 内でシステムプロンプトを上書き指定）
2. `baganriki_brain.txt` を廃止するか、仕様書に定義されていない追加指示が含まれている場合は内容を精査して統合

---

---

## ブロック3【🔴 必須】固定配点（信頼度60点＋妙味40点）の採点指示

### 仕様書の定義

おすすめ度 = 信頼度60点（7項目）+ 妙味40点（4項目）の**固定配点**。

**信頼度 60点**
1. 複勝支持・安定性：0〜15点
2. 単勝・複勝の継続資金流入：0〜12点
3. 単複人気差・支持差：0〜8点
4. 断層位置・時間変化・単複一致：0〜10点
5. 類似レース統計：0〜8点
6. 予測補正の維持・直前傾向：0〜4点
7. 能力・今回条件への適性：0〜3点（A=3、B=2、C=1、D=0）

**妙味 40点**
1. 推定確定配当水準：0〜15点
2. 3種類の回収率の裏付け：0〜12点
3. OPI・予測補正OPIによる市場評価：0〜8点
4. 配当と市場流入・断層構造の整合性：0〜5点

項目の追加・削除・配点変更・信頼度と妙味の間での点数移動は**禁止**。

### 現状コード

現在のプロンプト内にどの程度この固定配点が指示されているか、`_getAiAnalysisPrompt()` の該当箇所を全文確認する必要がある。  
（コード読み込み時の確認では「おすすめ度計算方法」ブロックは存在していたが内容の詳細は未確認）

### 差分

仕様書通りの7項目+4項目・各配点上限・採点順序の指示がプロンプトに正確に含まれているかどうかを要確認。

### 改造内容

1. `_getAiAnalysisPrompt()` の「おすすめ度計算方法」セクションを仕様書の内容（項目名・配点上限・判定基準）に完全一致させる
2. 特に「項目7：能力・適性（0〜3点）」が追加された点を確認・追加
3. 低配当時の妙味上限（推定確定複勝最小オッズ1.5倍未満→妙味小計5点以下）の記述が含まれているか確認

---

---

## ブロック4【🔴 必須】2nd AI（DeepSeek）システムプロンプトの修正

### 仕様書の定義

DeepSeekへのシステム指示には以下が明記されている：

> 「DB・PHP算出値の変更禁止、未来情報禁止、全頭評価、回収率優先、低配当除外、頭数上限は、1st AIと2nd AIの**両方**に適用される絶対ルールです」

自由に遊んでいいのは「確定値の解釈・指標間の重み付け・採点・候補選出」についてであり、DB/PHP値の再計算は2nd AIにも禁止。

### 現状コード

現在のDeepSeekシステムプロンプトには：

> 「しかし、それらは1st AIを縛るための規則であり、**あなたへの縛りではありません**」

という文言が含まれており、仕様書と**真逆の内容**になっている。

### 差分

現状コードでは「DB/PHP値の再計算禁止」が2nd AIに適用されていない。  
仕様書はこれを両AIへの絶対ルールとしている。

### 改造内容

`getHorseOddsFinderSecondAiOpinion()` 内のDeepSeekシステムプロンプトを仕様書の全文に置換する。

```
旧: 「これらは1st AIを縛るための規則であり、あなたへの縛りではありません」
新: 「DB・PHP算出値の変更禁止、未来情報禁止、全頭評価、回収率優先、低配当除外、頭数上限は、
    1st AIと2nd AIの両方に適用される絶対ルールです。
    お前は自由に遊んでいい。ただし、真剣にやれ。
    独立性は確定値の解釈・指標間の重み付け・採点・候補選出で発揮する」
```

---

---

## ブロック5【🔴 必須】2nd AIプロンプトの除去ブロック

### 仕様書の定義

1st AIのプロンプトから以下を除去してDeepSeekへ送る：

1. 「厳選穴レースの判定ルール」ブロック全体
2. 出力フォーマット内の「厳選穴レース|1または0」行
3. 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」行
4. 末尾の1st AI専用出力指示

除去してはいけないもの（維持必須）：
- DB・PHP確定値の変更禁止
- 未来情報禁止
- 全頭評価
- 回収率優先
- 低配当除外
- サンプル数評価
- 断層位置別人気帯上限
- 最低基準点
- 能力・適性評価
- 頭数を埋めない規則

### 現状コード

`getHorseOddsFinderSecondAiOpinion()` では現在、以下を除去している：
- 「厳選穴レース判定ルール」ブロック ✅
- 「おすすめ度計算方法」ブロック ❌（仕様書では除去しない）
- 「このシステムの目的」ブロック ❌（仕様書では除去しない）
- 「回収率優先・低配当除外ルール」ブロック ❌（仕様書では「維持必須」）

### 差分

- 「おすすめ度計算方法」「システムの目的」「回収率優先・低配当除外ルール」を誤って除去している
- 「レース指標行」（ブロック1で指摘）の除去処理が未実装

### 改造内容

1. 2nd AIへの除去ブロックを仕様書の4項目のみに変更
2. 「おすすめ度計算方法」「システムの目的」「回収率優先・低配当除外ルール」は除去しないよう修正
3. 「レース指標|...」行の出力指示を除去する処理を追加（ブロック1実装後）

---

---

## ブロック6【🔴 必須】2nd AI独自発見枠：F1〜F8フラグ判定への変更

### 仕様書の定義

2nd AI独自発見馬の採用条件は以下の8フラグ（F1〜F8）でPHPが機械判定：

| フラグ | 条件 | 元データ |
|--------|------|----------|
| F1 | 市場妙味基礎点Aが12点以上 | PHP算出（高配当強化機能が必要） |
| F2 | 複勝人気順位が単勝人気順位より2順位以上高い | DB値 |
| F3 | 複勝流入ランクが同人気帯グループ内1位または2位 | PHP算出済み |
| F4 | 7番人気以下かつ9分前→6分前で単複最小オッズがともに2%超低下 | DB値 |
| F5 | 市場妙味基礎点Eが6点以上 | PHP算出（高配当強化機能が必要） |
| F6 | 類似レース統計サンプル30件以上かつ5着以内率70%以上 | DB値 |
| F7 | 予測補正OPIが0.95以下 | DB値 |
| F8 | 過去回収率/OPI帯別/フェーズパターン別回収率のいずれかがサンプル30件以上かつ110%以上 | DB値 |

**重要**: AIの選出理由の文章からキーワードを探す方法は禁止。必ずDB・PHP数値から判定する。

### 現状コード

`_mergeAiResults()` の採用条件は `evidenceKeywords` 配列にある正規表現パターンで  
AI選出理由のテキストを検索するキーワードマッチ方式：

```php
$evidenceKeywords = [
    '複勝.*継続.*下落',
    '継続.*複勝.*下落',
    // ... 19パターン
];
// AIの選出理由テキストからパターンマッチ → evidence >= 2 なら採用
```

これは仕様書が**明示的に禁止**している方法。

### 差分

- キーワードマッチ方式 → F1〜F8のDB値による機械判定に全面変更が必要
- F1とF5は「市場妙味基礎点」に依存するため、ブロック9（高配当精度強化）の実装が前提

### 改造内容

`_mergeAiResults()` の独自発見採用判定ロジックを以下に変更：

1. F2・F3・F4・F6・F7・F8はDB/PHP既存値から即時判定可能 → 先行実装可
2. F1（基礎点A）・F5（基礎点E）はブロック9の高配当基礎点算出後に実装
3. 当面の暫定対応として F1・F5 を「不明（非カウント）」扱いにして他6フラグで判定
4. `_mergeAiResults()` の引数に各馬のDB値（複勝人気・単勝人気・流入ランク・9分前オッズ・OPI・回収率等）を追加
5. 「evidenceKeywords」のキーワードマッチを廃止

---

---

## ブロック7【🔴 必須】断層タイプ別の2nd AI独自発見枠上限（タイプAの修正）

### 仕様書の定義

| 断層タイプ | 独自発見枠上限 | 追加条件 |
|-----------|--------------|---------|
| A | 原則0頭 | 80点以上かつF1〜F8のtrueが3個以上の場合のみ最大1頭 |
| B | 最大1頭 | — |
| C | 最大1頭 | — |
| D | 最大2頭 | — |
| E | 最大2頭 | — |

### 現状コード

```php
$secondUniqueLimit = match ($gapType) {
    'A'     => 1,      // ← タイプAも1頭になっている
    'B', 'C'=> 1,
    'D', 'E'=> 2,
    default => 1,
};
$strictScoreForA = ($gapType === 'A');  // Aは80点以上必要
```

タイプAは「80点以上」の条件はあるが、「原則0頭」になっておらず、F1〜F8 3個以上の条件もない。

### 差分

タイプAの独自発見枠が「原則0頭（例外あり1頭）」ではなく「1頭（80点以上）」になっている。

### 改造内容

タイプAの判定を以下に変更：
- デフォルトは0頭
- おすすめ度80点以上かつF1〜F8のtrue数が3個以上の場合のみ1頭まで許可

```php
// 変更後イメージ
if ($gapType === 'A') {
    $secondUniqueLimit = 0;  // 原則0頭
    // F1〜F8の判定後、true数 >= 3 かつ score >= 80 なら 1 に昇格
}
```

---

---

## ブロック8【🔴 必須】断層位置別・人気帯別上限の細分化

### 仕様書の定義

断層が「どの人気順間」にあるかで上限が異なる（仕様書の表）：

| 断層位置 | 1〜6番人気の上限 | 7〜10番人気の上限 | 11番人気以下の上限 |
|---------|----------------|-----------------|-----------------|
| 1〜2番人気間 | 最大3頭 | 最大2頭 | 最大1頭 |
| 2〜3番人気間 | 最大4頭 | 最大2頭 | 最大1頭 |
| 3〜4番人気間 | 最大4頭 | 最大2頭 | 最大1頭 |
| 4〜5番人気間 | 最大4頭 | 最大2頭 | 最大1頭（大穴進入度1〜2なら0頭） |
| 5〜6番人気間 | 最大3頭 | 最大3頭 | 最大2頭 |
| 6番人気以降 | 最大2頭 | 最大3頭 | 最大2頭 |
| 断層なし・判定困難 | 最大3頭 | 最大3頭 | 最大2頭 |

### 現状コード

断層タイプ（A〜E）で上位/中位/下位の3区分に大まかに分けている：

```php
case 'A': $pickupUpperMax = 4; $pickupMidMax = 0; $pickupLowerMax = 0;
case 'B': $pickupUpperMax = 4; $pickupMidMax = 2; $pickupLowerMax = 1;
case 'C': $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 1;
case 'D': $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 2;
case 'E': $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 1;
```

### 差分

- 仕様書の「断層位置別」（1〜2間、2〜3間...）の細分化が未実装
- 「1〜6人気 / 7〜10人気 / 11人気以下」という3区分での上限管理も未実装（現在は「上位/中位/下位」という曖昧な区分）

### 改造内容

1. プロンプト内の選出指示は仕様書の表を正確に記載（これはすでに一部含まれている可能性あり）
2. PHP側の `_mergeAiResults()` に断層位置別・人気帯別上限のPHP機械判定を追加
3. 「主断層位置」（何番人気間に断層があるか）を `_getAiAnalysisPrompt()` で計算して `_mergeAiResults()` に渡す
4. 1〜6番人気、7〜10番人気、11番人気以下の3区分でカウントして上限チェック

---

---

## ブロック9【🟡 重要】能力・適性評価（1st AI・2nd AI共通）

### 仕様書の定義

1st AI・2nd AIの両方が既存の1回の分析内で能力・適性評価を行う。  
第3のAI、別モデル、別APIの追加は禁止。

**採点（100点満点・6項目）**
1. 基礎能力・クラス実績：25点
2. 近走内容・着差・相手関係：20点
3. コース・距離・芝ダート・馬場適性：20点
4. 脚質・想定展開・枠順との適合：15点
5. 上がり性能・位置取り・レース内容：10点
6. 斤量・騎手・馬体重・休養間隔などの補正：10点

評価区分：A（80〜100）B（70〜79）C（60〜69）D（59以下）

選出理由の冒頭に「能力適性:A（82点）。〜」の形式で記載。

渡すデータ：全出走馬の直近5〜10走（着順・着差・人気・距離・馬場・クラス・上がり3F等）

### 現状コード

現時点で `_getAiAnalysisPrompt()` に過去成績データ（直近走）を含めているかどうかは、  
コードの全文確認では明確でなかった。  
（`t_horse_odds_finder_forecast_from_last_race` から一部データ取得はしているが、  
「直近5〜10走の着順等」をどこまで渡しているかは要確認）

### 差分

- 能力・適性評価の採点指示（6項目・100点満点）がプロンプトに含まれているかどうか不明
- 直近5〜10走の詳細な過去成績データをAIに渡す処理が実装されているか不明
- 選出理由の冒頭「能力適性:A（82点）」フォーマットが求められていない

### 改造内容

1. `_getAiAnalysisPrompt()` 内で各馬の直近走データ（DBの着順・着差・人気・オッズ・距離・馬場・クラス・上がり3F等）を取得してプロンプトに追加
2. 能力・適性評価の6項目採点指示をプロンプトに追加
3. 選出理由の先頭に「能力適性:X（XX点）。」を必ず書くよう出力指示に追記
4. DeepSeek側も同じデータを受け取る（1st AIのプロンプトと共通）

---

---

## ブロック10【🟢 将来】高配当精度強化（市場妙味基礎点・高配当総合点）

### 仕様書の定義

PHP側で「市場妙味基礎点（0〜80点）」を算出し、AI回答後に「能力適性補正（0〜20点）」を加算して「高配当総合点（上限100点）」を計算する。

**市場妙味基礎点の構成（0〜80点）**  
A. 複勝継続流入（複数時点でオッズ継続下落か）  
B. 複勝優位性（複勝人気が単勝人気より高いか）  
C. OPI妙味（予測補正OPIが0.95以下か）  
D. 回収率の裏付け（110%以上の回収率が1種類以上あるか）  
E. 断層変化根拠（断層が縮小・矛盾等の構造変化があるか）

**シャドー検証中の動作**（重要）：  
まず200〜300レースはシャドー期間とし、Flutter本番表示は変えずにPHP内部でのみログ保存する。  
検証完了後に明示的に本番有効化した場合だけ、AIへ送信・表示に使う。

### 現状コード

完全に未実装。

### 改造内容（フェーズ管理）

**フェーズ1（今すぐ実装）**：
1. `_getAiAnalysisPrompt()` 実行後、PHP側で市場妙味基礎点A〜Eを算出するロジックを追加
2. 算出した基礎点・内訳をログテーブルに保存（新テーブル `t_horse_odds_finder_market_score` など）
3. AIへは送信しない（シャドー期間中）

**フェーズ2（AI回答後）**：
4. AI回答から能力適性点（A〜D）を抽出し、能力適性補正（A=20、B=15、C=10、D=0）を算出
5. 高配当総合点 = 基礎点 + 能力適性補正（上限100点）を計算してログ保存

**フェーズ3（200〜300レース検証後）**：
6. 検証結果を確認してから本番有効化フラグをONにする
7. AIへ基礎点を送信、Flutter表示に高配当総合点を使う

注意：この機能はF1（基礎点A≥12）とF5（基礎点E≥6）の判定にも使われる（ブロック6参照）

---

---

## ブロック11【🟢 将来】断層パターン学習（M1〜M4）

### 仕様書の定義

外部AIではなく、PHPから呼び出すローカル/サーバー内の機械学習モデル4種類：

- **M1** 上位完結モデル：5着以内が全員1〜6番人気なら1
- **M2** 下位進入モデル：5着以内に7〜10番人気が1頭以上いれば1
- **M3** 大穴進入モデル：5着以内に11番人気以下が1頭以上いれば1
- **M4** 馬別5着以内モデル：対象馬が5着以内なら1

**本番有効化の条件**：
- シャドー期間（1,000レース未満）：特徴量スナップショットの保存のみ、予測は行わない
- 予備モデル（1,000レース以上）：モデル推論開始、ただしFlutter表示には使わない
- 本番有効化（別途明示）：3,334レース以上が目安

### 現状コード

完全に未実装。

### 改造内容（フェーズ管理）

**フェーズ1（今すぐ実装）**：
1. レース終了後のバッチ処理で正解ラベル（M1〜M4）を記録するテーブルを作成
2. 各レース予測時に「特徴量スナップショット」（オッズ時系列・断層データ等）を保存するロジックを追加
3. 1,000レース蓄積を待つ（現時点では予測は行わない）

**フェーズ2（1,000レース以上）**：
4. Python/scikit-learnなどでM1〜M4モデルを訓練
5. PHPからモデルを呼び出す（ONNX形式などでサーバー側に配置）
6. 予測結果をログ保存（Flutter表示にはまだ使わない）

**フェーズ3（3,334レース以上・別途有効化）**：
7. M1〜M4の予測を断層タイプ判定の補助として利用
8. Flutterへの表示を有効化

---

---

## ブロック12【🟡 重要】PHP側の回収率ハード除外

### 仕様書の定義

PHP側で以下の機械判定を行う（AIの採点とは別）：

- **ハード除外**：有効値（サンプル30件以上の回収率）が2種類以上あり、そのうち2種類以上が90%未満の馬
- **自動除外しない**：有効値が2種類以上あるが90〜99%の馬（AI採点の減点のみ）
- **除外しない**：有効値が2種類未満、欠損、「－」、0レース（不明として中立扱い）

### 現状コード

`_mergeAiResults()` にこのハード除外ロジックは実装されていない。  
回収率フィルターはAIの採点（プロンプト指示）に依存している。

### 差分

PHP側での回収率ハード除外が未実装。AIが誤って採用しても機械的に弾く仕組みがない。

### 改造内容

1. `_mergeAiResults()` 呼び出し前に、各馬の回収率データ（過去回収率・OPI帯別・フェーズパターン別）とサンプル数を引数として受け取る
2. 有効値2種類以上かつ2種類以上90%未満の馬を除外リストに追加
3. 統合処理でこの除外リストを適用

---

---

## ブロック13【🟡 重要】タイブレーク・最終順位決定ルール

### 仕様書の定義

**シャドー検証中の順位決定（正規）**：
1. 統合おすすめ度の降順
2. 同点なら両AI一致馬を優先
3. それでも同じなら馬番の小さい馬を優先

（高配当総合点・市場妙味基礎点・能力適性補正はシャドー中は順位に使わない）

### 現状コード

`_mergeAiResults()` では `usort` でスコア降順のみ。同点時のタイブレークが未実装。

### 改造内容

1. `usort` の比較関数に「同点なら一致馬優先、次に馬番小さい方」を追加
2. シャドー検証完了後の本番有効化時の複雑なタイブレーク（高配当総合点→複勝継続流入点→回収率点→OPI→能力適性点→馬番）は将来実装

---

---

## ブロック14【🟡 重要】一致馬の表示理由と能力適性表記

### 仕様書の定義

- 両AI一致馬の**Flutter表示用「選出理由」は1st AIの原文**を使用する
- 2nd AIの選出理由はログに保存するがFlutter表示には使わない
- PHPは両文章を連結・要約・生成しない
- 一致馬の能力適性表記は1st AI原文のままとする（統合後の平均能力適性点は内部値のみ）

### 現状コード

現在の `_mergeAiResults()` では一致馬の `reason` に1st AIの原文を使っており、`reason_2nd` に2nd AIの理由を保持している（適合）。  
ただし、保存後にFlutter側がどちらを使うかは確認が必要。

### 改造内容

1. 現状の `category: 'matched'` の `reason` = 1st AI、`reason_2nd` = 2nd AI の実装は正しい
2. 保存する `t_horse_odds_finder_ai_analysis`（統合後）のカラム設計を確認し、`reason_2nd` が別カラムに保存されているか確認
3. Flutterに返すJSONが常に `reason`（1st AI）のみを使っているか確認

---

---

## ブロック15【🔴 必須】「厳選穴レース」の再判定タイミング

### 仕様書の定義

1. 1st AIの出力をそのまま暫定値として保持
2. PHP側で最終候補確定後、条件B・C・Dを優先判定
3. 最終候補内に7〜10番人気が1頭以上いるかで条件Aを再判定
4. 先頭行を最終値に上書き

### 現状コード

`getHorseOddsFinderAiAnalysis()` 内で1st AIの出力をそのまま保存している。  
統合後に「厳選穴レース」を再判定して上書きするロジックが明示されていない。

### 差分

1st AIが出力した「厳選穴レース|X」を最終候補確定後にPHPが再判定・上書きする処理が不明瞭。  
条件B・C・D（PHPで事前算出済み）を優先して再判定するロジックが必要。

### 改造内容

`_mergeAiResults()` または `getHorseOddsFinderSecondAiOpinion()` の後処理に以下を追加：
1. 最終候補確定後、条件B・C・Dのいずれかが成立 → 0に確定
2. B・C・D全不成立かつ最終候補に7〜10番人気が1頭以上 → 1
3. それ以外 → 0
4. DB保存済みの「厳選穴レース」フラグを上書き

---

---

## 改造の推奨実施順序

実装の依存関係を考慮した推奨順序：

```
1. ブロック4（2nd AIシステムプロンプト修正）     ← 1時間・リスク低
2. ブロック5（2nd AIの除去ブロック修正）         ← 1時間・リスク低
3. ブロック1（出力フォーマット・レース指標追加）  ← 3〜4時間・Flutter影響あり
4. ブロック2（1st AIシステムプロンプト確認・修正）← 1〜2時間
5. ブロック3（固定配点採点指示の確認・修正）      ← 2〜3時間
6. ブロック15（厳選穴レース再判定）               ← 2時間
7. ブロック13（タイブレーク追加）                 ← 1時間
8. ブロック8（断層位置別・人気帯別上限の細分化）  ← 4〜6時間
9. ブロック12（回収率ハード除外）                 ← 2〜3時間
10. ブロック7（タイプAの独自発見枠修正）          ← 1時間（ブロック6に依存）
11. ブロック9（能力・適性評価のデータ追加）       ← 4〜8時間（DBスキーマ確認が必要）
12. ブロック6（F1〜F8フラグ判定への変更）        ← 4〜6時間（ブロック9完了後）
13. ブロック10（高配当精度強化・シャドー）        ← 8〜16時間（フェーズ1から）
14. ブロック11（断層パターン学習・シャドー）      ← 別プロジェクト規模
```

---

## 全体サマリー

| # | ブロック | 優先度 | 概算工数 |
|---|---------|--------|---------|
| 1 | レース指標行の追加 | 🔴必須 | 3〜4h |
| 2 | 1st AIシステムプロンプト | 🔴必須 | 1〜2h |
| 3 | 固定配点の採点指示 | 🔴必須 | 2〜3h |
| 4 | DeepSeekシステムプロンプト修正 | 🔴必須 | 1h |
| 5 | 2nd AIの除去ブロック修正 | 🔴必須 | 1h |
| 6 | F1〜F8フラグ判定 | 🔴必須 | 4〜6h |
| 7 | タイプAの独自発見枠 | 🔴必須 | 1h |
| 8 | 断層位置別・人気帯別上限 | 🔴必須 | 4〜6h |
| 9 | 能力・適性評価の実装 | 🟡重要 | 4〜8h |
| 10 | 高配当精度強化（シャドー） | 🟢将来 | 8〜16h |
| 11 | 断層パターン学習（シャドー） | 🟢将来 | 別途 |
| 12 | 回収率ハード除外 | 🟡重要 | 2〜3h |
| 13 | タイブレーク修正 | 🟡重要 | 1h |
| 14 | 一致馬の表示理由 | 🟡重要 | 1h |
| 15 | 厳選穴レース再判定 | 🔴必須 | 2h |

🔴必須合計：約17〜24時間  
🟡重要合計：約8〜13時間  
🟢将来：別途フェーズ管理

---

---

# ══════════════════════════════════════════════════
# 追記セクション（2026-09-11 調査結果）
# ══════════════════════════════════════════════════

---

## 【Flutter調査結果】ai_analysis_display_alert.dart の現状

> 調査ファイル: `/Users/toyodahideyuki/Documents/keiba_odds_finder/lib/screens/components/ai_analysis_display_alert.dart`

### ✅ 既に実装済み（Flutter側はほぼ完成）

以下のパラメータとUIが既に存在していた：

```dart
// コンストラクタ引数（既存）
final List<AiResponseRecommendHorseModel>? mergedHorseList;   // マージ済み馬リスト
final int? upsetRaceValue;                                      // 厳選穴レース 0/1
final Map<String, int>? raceMetrics;                           // ← レース指標データ
```

```dart
// _buildRaceMetrics() が既に実装済み
Widget _buildRaceMetrics(Map<String, int> metrics) {
  String stars(int v) => '★' * v + '☆' * (5 - v);
  return Container(
    // 波乱度 / 下位進入度 / 大穴進入度 を星表示するUIが完成
    child: Row(children: [
      _buildMetricItem('波乱度',      metrics['波乱度']!,      stars(metrics['波乱度']!)),
      _buildMetricItem('下位進入度',  metrics['下位進入度']!,  stars(metrics['下位進入度']!)),
      _buildMetricItem('大穴進入度',  metrics['大穴進入度']!,  stars(metrics['大穴進入度']!)),
    ]),
  );
}
```

表示条件: `if (widget.raceMetrics != null) _buildRaceMetrics(widget.raceMetrics!)` として、  
`mergedHorseList` があれば統合リスト表示も対応済み。

### ⚠️ ブロック1への影響（修正が必要な箇所の変更）

`AiResponseRecommendHorseModel` にフィールドを追加する**必要はない**。  
波乱度・下位進入度・大穴進入度は `Map<String, int>` として親 Widget に渡す形が確定している。

**PHP側でやるべきこと（修正内容の変更点）：**
1. `getHorseOddsFinderAiAnalysis()` のAPIレスポンスJSON に `race_metrics` キーを追加
2. `raceMetrics` を Flutter に返す形は `{"波乱度": 3, "下位進入度": 2, "大穴進入度": 1}` 形式

**Flutter側でやること（ほぼ不要）：**
- 呼び出し元で `raceMetrics` を渡すように修正するだけ
- `AiResponseRecommendHorseModel` は変更不要

→ **ブロック1の工数は2〜3時間に短縮される（Flutter側UI実装の時間は不要）**

---

---

## 【DBスキーマ調査結果】既存テーブル一覧と新規必要テーブル

> 調査ファイル: `/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/database.txt`（960行）

### 既存テーブル一覧

| テーブル名 | 用途 |
|-----------|------|
| `t_horse_odds_finder_ai_analysis` | 1st AI（Claude）の出力テキスト保存 |
| `t_horse_odds_finder_ai_analysis2` | 2nd AI（DeepSeek）の出力テキスト保存 |
| `t_horse_odds_finder_compute_odds_correction` | 6分前→確定オッズの補正係数（人気別平均） |
| `t_horse_odds_finder_forecast_from_last_race` | 前走予測の推奨馬番 |
| `t_horse_odds_finder_fuku_popularity_rank_average` | 複勝OPI計算用・人気別平均複勝オッズ |
| `t_horse_odds_finder_horse_scores` | 馬名別スコア累計 |
| `t_horse_odds_finder_horses` | 出走馬一覧（馬番・馬名・騎手・調教師） |
| `t_horse_odds_finder_jockey_scores` | 騎手別スコア |
| `t_horse_odds_finder_line_users` / `login_users` | ユーザー管理 |
| `t_horse_odds_finder_odds` | オッズ時系列データ |
| `t_horse_odds_finder_odds_gap_recovery` | 断層タイプ別回収率 |
| `t_horse_odds_finder_odds_get_timing` | オッズ取得タイミング管理 |
| `t_horse_odds_finder_opi_recovery` | OPI帯別回収率 |
| `t_horse_odds_finder_phase_pattern_recovery` | フェーズパターン別回収率 |
| `t_horse_odds_finder_popularity_horse_check` | 人気別チェック |
| `t_horse_odds_finder_popularity_rank_average` / `_median` | 人気別単勝オッズ平均・中央値 |
| `t_horse_odds_finder_push_send_logs` / `subscriptions` | プッシュ通知 |
| `t_horse_odds_finder_race_introspection` | レース振り返りテキスト |
| `t_horse_odds_finder_race_result_history` | レース結果・着順 |
| `t_horse_odds_finder_race_result_payout` | 払戻金データ |
| `t_horse_odds_finder_race_results` | レース結果サマリー |
| `t_horse_odds_finder_races` | レース情報 |
| `t_horse_odds_finder_races_popularity_ratio` | 人気比率 |
| `t_horse_odds_finder_schedules` | 開催スケジュール |
| `t_horse_odds_finder_shutsuba_history` | **出走履歴（過去成績）← ブロック9で使用** |
| `t_horse_odds_finder_similar_race_stats` | 類似レース統計（人気別・頭数帯別3着以内率等） |
| `t_horse_odds_finder_summary` | オッズ時系列サマリー（各馬・全時刻） |

**マージ結果を保存するテーブルは存在しない → 新規作成が必要。**

---

### `t_horse_odds_finder_shutsuba_history` のカラム詳細

ブロック9（能力・適性評価）でAIに渡すデータとして利用する。

```sql
CREATE TABLE `t_horse_odds_finder_shutsuba_history` (
  `id`                 int NOT NULL AUTO_INCREMENT,
  `name`               varchar(50),        -- 馬名
  `date`               date,               -- レース日
  `basho`              text,               -- 場所名
  `basho_code`         char(2),            -- 場所コード
  `race`               int,                -- レース番号
  `race_name`          varchar(100),       -- レース名
  `grade`              varchar(20),        -- グレード
  `finishing_position` int,                -- 着順
  `num_horses`         int,                -- 出走頭数
  `gate`               int,                -- 枠番
  `popularity`         int,                -- 人気
  `jockey`             varchar(50),        -- 騎手名
  `burden_weight`      varchar(10),        -- 斤量
  `dist`               varchar(20),        -- 距離（例: "1600芝"）
  `time`               varchar(20),        -- タイム
  `condition`          varchar(20),        -- 馬場状態
  `horse_weight`       varchar(10),        -- 馬体重
  `corner_1〜4`        int,                -- コーナー通過順（1〜4コーナー）
  `last_3f`            varchar(20),        -- 上がり3F
  `fin_horse`          varchar(50),        -- 1着馬名
  `fin_time_diff`      varchar(10),        -- 着差（秒）
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shutsuba_history` (`name`,`date`,`basho_code`)
);
```

**ブロック9のAI送信データとして使えるカラム：**  
着順・出走頭数・人気・騎手・斤量・距離・タイム・馬場状態・馬体重・コーナー通過順・上がり3F・1着馬名・着差

---

### 新規作成が必要なテーブルとCREATE文

#### ① `_mergeAiResults()` 出力の保存先テーブル

現状は `_mergeAiResults()` の結果（最終統合馬リスト）がどこにも保存されていない。  
Flutterが統合結果を参照するためのテーブルを新規作成する。

```sql
CREATE TABLE `t_horse_odds_finder_ai_merge_result` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `date`            date NOT NULL,
  `kaisuu`          tinyint unsigned NOT NULL,
  `basho_code`      char(2) NOT NULL,
  `day`             tinyint unsigned NOT NULL,
  `race`            tinyint unsigned NOT NULL,
  `race_name`       varchar(200) DEFAULT NULL,
  `upset_race`      tinyint(1) DEFAULT NULL         COMMENT '厳選穴レース 0/1（再判定後の最終値）',
  `wave_level`      tinyint unsigned DEFAULT NULL    COMMENT '波乱度 1〜5',
  `lower_entry`     tinyint unsigned DEFAULT NULL    COMMENT '下位進入度 1〜5',
  `big_gap_entry`   tinyint unsigned DEFAULT NULL    COMMENT '大穴進入度 1〜5',
  `gap_type`        char(1) DEFAULT NULL             COMMENT '断層タイプ A〜E',
  `result_json`     json DEFAULT NULL                COMMENT '統合馬リスト（JSON配列）',
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merge_result` (`date`,`kaisuu`,`basho_code`,`day`,`race`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='_mergeAiResults()の最終出力（統合馬リスト・レース指標）保存';
```

`result_json` の中身イメージ：
```json
[
  {
    "num": 3,
    "name": "ラパンラピッド",
    "popularity": 1,
    "odds_6": 3.0,
    "score": 72,
    "score_1st": 72,
    "score_2nd": 68,
    "category": "matched",
    "reason": "1st AIの選出理由テキスト",
    "reason_2nd": "2nd AIの選出理由テキスト"
  }
]
```

---

#### ② 市場妙味基礎点ログテーブル（ブロック10 フェーズ1用）

高配当精度強化のシャドー検証用。Flutterへは出力しない。

```sql
CREATE TABLE `t_horse_odds_finder_market_score_log` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `date`            date NOT NULL,
  `kaisuu`          tinyint unsigned NOT NULL,
  `basho_code`      char(2) NOT NULL,
  `day`             tinyint unsigned NOT NULL,
  `race`            tinyint unsigned NOT NULL,
  `num`             tinyint unsigned NOT NULL         COMMENT '馬番',
  `name`            varchar(50) DEFAULT NULL,
  `score_a`         tinyint unsigned DEFAULT NULL     COMMENT '複勝継続流入点（0〜20）',
  `score_b`         tinyint unsigned DEFAULT NULL     COMMENT '複勝優位性点（0〜15）',
  `score_c`         tinyint unsigned DEFAULT NULL     COMMENT 'OPI妙味点（0〜15）',
  `score_d`         tinyint unsigned DEFAULT NULL     COMMENT '回収率裏付け点（0〜15）',
  `score_e`         tinyint unsigned DEFAULT NULL     COMMENT '断層変化根拠点（0〜15）',
  `base_total`      tinyint unsigned DEFAULT NULL     COMMENT '市場妙味基礎点合計（0〜80）',
  `ability_grade`   char(1) DEFAULT NULL              COMMENT 'AI採点能力適性グレード A〜D（AI回答後に更新）',
  `ability_score`   tinyint unsigned DEFAULT NULL     COMMENT '能力適性補正点（20/15/10/0）',
  `high_score`      tinyint unsigned DEFAULT NULL     COMMENT '高配当総合点（0〜100）',
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_market_score` (`date`,`kaisuu`,`basho_code`,`day`,`race`,`num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='高配当精度強化シャドー検証用・市場妙味基礎点ログ';
```

---

#### ③ 断層パターン学習 特徴量スナップショット（ブロック11 フェーズ1用）

1,000レース分のデータ蓄積用。予測には使わない。

```sql
CREATE TABLE `t_horse_odds_finder_ml_snapshot` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `date`            date NOT NULL,
  `kaisuu`          tinyint unsigned NOT NULL,
  `basho_code`      char(2) NOT NULL,
  `day`             tinyint unsigned NOT NULL,
  `race`            tinyint unsigned NOT NULL,
  `gap_type`        char(1) DEFAULT NULL              COMMENT '断層タイプ A〜E',
  `features_json`   json DEFAULT NULL                 COMMENT '特徴量スナップショット（オッズ時系列・断層データ等）',
  `label_m1`        tinyint(1) DEFAULT NULL           COMMENT '正解ラベル M1: 上位完結（5着以内が全員1〜6番人気なら1）',
  `label_m2`        tinyint(1) DEFAULT NULL           COMMENT '正解ラベル M2: 下位進入（7〜10番人気が5着以内に1頭以上なら1）',
  `label_m3`        tinyint(1) DEFAULT NULL           COMMENT '正解ラベル M3: 大穴進入（11番人気以下が5着以内に1頭以上なら1）',
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ml_snapshot` (`date`,`kaisuu`,`basho_code`,`day`,`race`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='断層パターン学習M1〜M3用 特徴量スナップショット';
```

---

## 【ブロック別】調査結果を受けた追記・訂正

### ブロック1（レース指標行）→ Flutter工数大幅削減

**追記**: Flutter側の `_buildRaceMetrics()` UIは**完成済み**。  
PHP側が `race_metrics` をJSONレスポンスに乗せるだけで表示される。  
`AiResponseRecommendHorseModel` への追加フィールドは不要。  
工数を3〜4時間 → **2時間**に修正。

### ブロック9（能力・適性評価）→ shutsuba_history活用

`t_horse_odds_finder_shutsuba_history` に着順・上がり3F・着差・馬場・距離など必要カラムが全て揃っている。  
`UNIQUE KEY`が `(name, date, basho_code)` なので、馬名で絞って直近N走を取得可能。

```sql
-- 各馬の直近8走を取得するクエリイメージ
SELECT finishing_position, num_horses, popularity, dist, condition,
       horse_weight, last_3f, fin_time_diff, grade, race_name, `date`
FROM t_horse_odds_finder_shutsuba_history
WHERE name = :horseName
ORDER BY `date` DESC
LIMIT 8;
```

### ブロック14（マージ結果の保存）→ 新テーブル設計確定

上記の `t_horse_odds_finder_ai_merge_result` で保存する方針。  
既存の `t_horse_odds_finder_ai_analysis` は1st AIの生テキストを保存するテーブルであり、  
マージ後の最終統合データとは別テーブルで管理するのが適切。

### ブロック12のF2・F6・F7・F8のデータ在処

| フラグ | 参照テーブル |
|--------|------------|
| F2（複勝人気 vs 単勝人気） | `t_horse_odds_finder_odds`（複勝系カラム） |
| F3（複勝流入ランク1〜2位） | PHP算出済み（既にプロンプトに含まれている）|
| F4（7番人気以下・9分→6分単複下落） | `t_horse_odds_finder_summary`（time系列カラム）|
| F6（類似レース統計 サンプル30件・5着以内率70%）| `t_horse_odds_finder_similar_race_stats` |
| F7（予測補正OPI ≤0.95） | PHP算出済み |
| F8（回収率 110%以上） | `t_horse_odds_finder_opi_recovery` / `phase_pattern_recovery` / `odds_gap_recovery` |

---

## 【改訂版】全体サマリー（調査追記後）

| # | ブロック | 優先度 | 概算工数 | 備考 |
|---|---------|--------|---------|------|
| 1 | レース指標行の追加 | 🔴必須 | **2h** | Flutter UI は実装済みで工数削減 |
| 2 | 1st AIシステムプロンプト | 🔴必須 | 1〜2h | |
| 3 | 固定配点の採点指示 | 🔴必須 | 2〜3h | |
| 4 | DeepSeekシステムプロンプト修正 | 🔴必須 | 1h | |
| 5 | 2nd AIの除去ブロック修正 | 🔴必須 | 1h | |
| 6 | F1〜F8フラグ判定 | 🔴必須 | 4〜6h | F2/F6/F7/F8のデータ源確認済み |
| 7 | タイプAの独自発見枠 | 🔴必須 | 1h | |
| 8 | 断層位置別・人気帯別上限 | 🔴必須 | 4〜6h | |
| 9 | 能力・適性評価の実装 | 🟡重要 | 3〜5h | shutsuba_historyスキーマ確認済み |
| 10 | 高配当精度強化（シャドー） | 🟢将来 | 6〜12h | 新テーブル設計完了 |
| 11 | 断層パターン学習（シャドー） | 🟢将来 | 別途 | 新テーブル設計完了 |
| 12 | 回収率ハード除外 | 🟡重要 | 2〜3h | |
| 13 | タイブレーク修正 | 🟡重要 | 1h | |
| 14 | マージ結果保存 | 🔴必須 | 2h | 新テーブル追加（CREATE文完成） |
| 15 | 厳選穴レース再判定 | 🔴必須 | 2h | |

🔴必須合計：約18〜24時間（旧より+2h、ブロック14追加）  
🟡重要合計：約6〜9時間（ブロック9は工数削減）  
🟢将来：別途フェーズ管理

---

*最終更新: 2026-09-11（Flutter調査・DBスキーマ調査・新規テーブル設計を追記）*

---

---

# ══════════════════════════════════════════════════
# 追記セクション2（2026-09-11 baganriki_brain.txt 調査・回収率テーブル方針確定）
# ══════════════════════════════════════════════════

---

## 【ブロック2 大幅更新】baganriki_brain.txt の内容確認結果

> ファイル: `/Users/toyodahideyuki/Downloads/baganriki_brain.txt`（更新版55、115行、18,570バイト）
> このファイルが現行の AnthropicService（Claude）のシステムプロンプトに使われている。

### 現行プロンプトの方針（旧パラダイム）

現行の `baganriki_brain.txt` は「**オッズ安定性分析**」に特化したコンテンツ：

- 全馬を 0.05倍刻みで計測し「変動幅が最も小さい馬TOP5を帯域無視で選ぶ」
- 複勝1.5倍未満の低帯一極集中時は中位帯8〜15倍を強制再スキャン
- 段階的短縮が3段以上の馬を優先配置
- 断層値2.0以上で「低帯だけの選出を廃止し帯域分散」
- 終盤でオッズが上昇に転じた馬は自動下位判定

### 新仕様書の方針（新パラダイム）

仕様書のシステムプロンプトは「**DB/PHP算出値に従い固定配点でスコアリング・回収率優先**」：

- DB・PHP確定値（人気順・OPI・流入ランク・断層タイプ等）の再計算は禁止
- 採点は信頼度60点（7項目）＋妙味40点（4項目）の固定配点
- 能力・適性は補強材料（0〜3点）、時系列オッズで判断しオッズだけで決めない
- 仕様書の明示指示：「既存システムプロンプトと**併用せず、置換する**」

### ⚠️ 重要な判断ポイント

**旧プロンプトの「オッズ安定性アルゴリズム」は新プロンプトに引き継がれない。**

旧：AIが自分でオッズ変動を計算して馬を選ぶ（主役はAI）  
新：PHPが計算したDB値をAIに渡し、AIはそれに基づきスコア採点する（主役はPHP）

これは根本的な役割の違いであり、`baganriki_brain.txt` の内容は新プロンプトとは**思想が異なる**。  
仕様書の「置換」指示に従い、新システムプロンプト（仕様書掲載の全文）に**完全差し替え**する。

### 改造内容（ブロック2 確定版）

1. `baganriki_brain.txt` を参照している箇所（AnthropicService またはAiController）を特定
2. 仕様書掲載のシステムプロンプト全文に**完全置換**（baganriki_brain.txt は廃止）
3. 置換後は `baganriki_brain.txt` を削除またはバックアップに退避

---

## 【ブロック12・F8 方針確定】回収率ハード除外の計算元テーブル

ユーザー指示：回収率ハード除外（ブロック12）とF8判定は `t_horse_odds_finder_race_result_payout` を使って計算する。

### race_result_payout テーブルの特徴（注意点）

払戻カラムは `|` 区切り・`/` 区切りの複合形式：

| カラム | 例 | 意味 |
|--------|-----|------|
| `tan`  | `14\|180` | 馬番14が1着、単勝払戻180円 |
| `fuku` | `14\|110/5\|150/1\|480` | 3頭の複勝（馬番/払戻）|
| `umaren` | `3-7\|470` | 馬連3-7で470円 |
| `trio` | `1-5-14\|3040` | 三連複で3040円 |

### 過去回収率の計算ロジック（設計方針）

```
① shutsuba_history から対象馬の直近N走を取得（nameで絞り込み）
② 各レースの race_result_payout を date・basho_code・race でJOIN
③ fuku カラムをパースし、対象馬番が含まれているか確認
④ 含まれていれば複勝払戻金を取得
⑤ 回収率 = (複勝払戻金 / 100) × 100%  ← 100円購入想定
⑥ サンプル30件以上 + 平均回収率を算出
```

**パース処理のポイント（実装注意）：**

```php
// fukuカラムのパース例
$fukuStr = "14|110/5|150/1|480";
$entries = explode('/', $fukuStr);
foreach ($entries as $entry) {
    [$num, $payout] = explode('|', $entry);
    if ((int)$num === $targetNum) {
        // この馬は複勝的中。payoutが払戻金（円）
    }
}
```

`tan`（単勝）も同様にパース可能。ブロック12・F8では複勝回収率を主に使う。

### ブロック12「回収率ハード除外」の実装方針（確定）

- `t_horse_odds_finder_shutsuba_history` + `t_horse_odds_finder_race_result_payout` から過去回収率を算出
- サンプル30件以上の「複勝回収率」と「単勝回収率」を各種計算
- 2種類以上が90%未満 → `_mergeAiResults()` 前にハード除外リストに追加

### F8「過去回収率 110%以上」の実装方針（確定）

同テーブルから算出した複勝または単勝回収率が110%以上かつサンプル30件以上ならF8=true。  
OPI帯別・フェーズパターン別（`t_horse_odds_finder_opi_recovery` 等）は既存集計テーブルを参照。

---

## 【未確認事項 最終リスト】

以上の調査を経て、実装前に残る未確認事項は以下のみ：

| # | 内容 | 影響ブロック | 実装時の対応 |
|---|------|------------|------------|
| A | `AnthropicService.php` のファイルパスと、`baganriki_brain.txt` 読み込み箇所の特定 | ブロック2 | サーバーで `find /var/www/ -name "AnthropicService.php"` |
| B | Flutter の `AiAnalysisDisplayAlert` 呼び出し元（どのScreenが `raceMetrics` を渡すか） | ブロック1 | `grep -r "AiAnalysisDisplayAlert" lib/` で一発 |
| C | `断層の主要発生位置（何番人気と何番人気の間）` をPHPが算出済みかどうか | ブロック8 | `_getAiAnalysisPrompt()` 内の断層計算部分を確認 |

これら3点は**実装しながら grep 1回で解決できる**レベル。  
実装前に詰まることはない。

---

## 【改造計画書 完成宣言】

以上で以下が全て確定した：

- ✅ 15ブロック全ての差分・改造内容・優先度
- ✅ 実装順序と工数見積もり
- ✅ Flutter 現状調査（AiAnalysisDisplayAlert の実装済み確認）
- ✅ DBスキーマ全テーブル把握
- ✅ 新規テーブル3本のCREATE文（merge_result・market_score_log・ml_snapshot）
- ✅ baganriki_brain.txt（現行システムプロンプト）の内容と置換方針
- ✅ 回収率計算の元テーブルと `|` 区切りパースの注意点
- ✅ shutsuba_history のカラム詳細（ブロック9のデータ設計）
- ✅ F2〜F8 の各フラグのデータ参照先
- ✅ 残存未確認3点は実装時に grep 1回で解決可能なもの

*最終更新: 2026-09-11 完成*

---

---

# ══════════════════════════════════════════════════
# 追記セクション3（2026-09-11 SummaryMakeBaganrikiBrain・crontab 調査）
# ══════════════════════════════════════════════════

---

## 【ブロック2 最終確定】baganriki_brain.txt の生成元と廃止方針

### SummaryMakeBaganrikiBrain.php の動作

- **cron**: 毎日 23:50 実行
- **処理**: `t_horse_odds_finder_race_introspection`（レース振り返りテキスト）を30件ずつClaudeに送り、「どのオッズ推移パターンで有力馬を選ぶか」の目線を蒸留して `baganriki_brain.txt` に書き出す
- **役割**: 過去の的中・外れデータから自動的に分析眼を磨く**自己学習ループ**
- **保存先**: `/var/www/horse_odds_finder/public/baganriki_brain.txt`（public/配下 = HTTP公開パスにある）

### 廃止の判断

新仕様書は「システムプロンプトを固定テキストに置換する」方針であり、自動学習で更新される brain.txt とは設計思想が根本的に異なる。

| | 旧（baganriki_brain.txt方式） | 新（仕様書方式） |
|--|------|------|
| プロンプトの性質 | AIが過去経験から自己学習して更新し続ける | 人間が設計した固定スコアリングルール |
| 変わるもの | レース振り返りのたびに内容が進化する | 変わらない（改訂は手動） |
| 主体 | AIの判断（安定性・帯域・短縮段数） | PHP/DBの計算値に基づくスコア |

→ 新実装完了後、`baganriki_brain.txt` をコードから参照しなくなれば、この cron は**不要になる**。

### 廃止手順（明日の実装完了後）

1. AiController.php から `baganriki_brain.txt` 読み込み箇所を削除（ブロック2の作業）
2. 動作確認後、crontab から `keiba:makeBaganrikiBrain` の行を削除
3. `baganriki_brain.txt` 本体はバックアップとして残しておく（削除は不要）

⚠️ **実装前は絶対に cron を止めない**（コード変更と同時に行う）

---

## 【crontab 全体確認】各 cron の継続・廃止の判断

> ファイル: `/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/crontab.txt`

| 時刻 | コマンド | 今回の作業との関係 | 判断 |
|------|----------|-------------------|------|
| 土5:50 | `deleteKeibaTableRecords` | 無関係 | ✅ 継続 |
| 毎日6:00 | `importSchedule` | 無関係 | ✅ 継続 |
| 土6:10 | `summaryRacesPopularityRatio` | 無関係 | ✅ 継続 |
| 土日6:20 | `summaryCalculateHorseScore` | 無関係 | ✅ 継続 |
| 土日6:30 | `summaryCalculateJockeyScore` | 無関係 | ✅ 継続 |
| 土日7:00 | `importBaseOdds` | 無関係 | ✅ 継続 |
| 土日7:30 | `summaryForecastFromLastRace` | 過去レースからClaude APIで注目馬番を抽出。ブロック9（能力適性）と役割が重なる可能性あり | ⚠️ 要確認（後述） |
| 土日8:00 | `ImportRacesPopularityRatio` | 無関係 | ✅ 継続 |
| 土日8:30 | `summaryPopularityRankMedian` | 無関係 | ✅ 継続 |
| 9〜18時毎分 | `importOdds` | 無関係 | ✅ 継続 |
| 9〜19時毎分 | `importJraRaceOneResult` | 無関係 | ✅ 継続 |
| 9〜18時毎分 | `repairStartTime` | 無関係 | ✅ 継続 |
| 毎日20:10 | `summary` | 無関係 | ✅ 継続 |
| 毎日20:20 | `importJraRaceResult` | 無関係 | ✅ 継続 |
| 金以外20:30 | `importRaceResultHistory` | 無関係 | ✅ 継続 |
| 毎日20:40 | `summaryHistoryFinishingPosition` | 無関係 | ✅ 継続 |
| 毎日20:50 | `SummaryRacesIntrospection` | 振り返りテキストを生成。brain.txt廃止後もこのデータ自体は価値がある | ✅ 継続 |
| 毎日21:00 | `summaryHistoryPopularityRank` | 無関係 | ✅ 継続 |
| 土以外21:10 | `summaryPopularityRankAverage` | 無関係 | ✅ 継続 |
| 毎日21:20 | `SummaryComputeOddsCorrection` | 無関係 | ✅ 継続 |
| 毎日21:30 | `popularityHorseCheck` | 無関係 | ✅ 継続 |
| 毎日21:40 | `SummaryOddsPhasePatternRecoveryRate` | F8・ブロック12で参照するテーブルのデータ源 | ✅ 継続（重要） |
| 毎日21:50 | `importRaceResultPayout` | ブロック12・F8の計算元テーブル（race_result_payout）の更新 | ✅ 継続（重要） |
| 毎日22:00 | `summaryFukuPopularityRankAverage` | 無関係 | ✅ 継続 |
| 毎日22:10 | `importShutsubaHistory` | ブロック9（能力適性）のデータ源。shutsuba_historyを毎日更新 | ✅ 継続（重要） |
| 毎日22:20 | `SummaryOddsGapRecoveryRate` | F8・ブロック12で参照する回収率テーブルの更新 | ✅ 継続（重要） |
| 毎日22:30 | `SummaryOpiRecoveryRate` | F8で参照するOPI帯別回収率の更新 | ✅ 継続（重要） |
| 毎日22:40 | `importPayoutCourseDist` | 無関係 | ✅ 継続 |
| 毎日22:50 | `importPayoutInnerOuter` | 無関係 | ✅ 継続 |
| 毎日23:00 | `importPayoutGrade` | 無関係 | ✅ 継続 |
| 毎日23:10 | `summarySimilarRaceStats` | F6（類似レース統計）のデータ源 | ✅ 継続（重要） |
| 毎日23:40 | `ai-analysis-compensate` | AI予想の補完処理。今回の改造後も動作 | ✅ 継続 |
| 毎日23:50 | `makeBaganrikiBrain` | ブロック2の実装完了後に**廃止** | 🔴 実装後に廃止 |

### ⚠️ `summaryForecastFromLastRace`（7:30）の扱いについて

このコマンドは「過去出走データを使ってClaude APIで注目馬番を抽出し、`t_horse_odds_finder_forecast_from_last_race` に保存する」処理。ブロック9（能力・適性評価）では `shutsuba_history` を直接使うため、**役割が重複する可能性がある**。

ただし現在のAI分析（`_getAiAnalysisPrompt()`）が `forecast_from_last_race` をどのように利用しているかを確認してから判断する必要がある。明日の実装時に `_getAiAnalysisPrompt()` を確認して判断する。

---

*最終更新: 2026-09-11 crontab・SummaryMakeBaganrikiBrain調査追記*

---

---

# ══════════════════════════════════════════════════
# 追記セクション4（cron継続方針・SummaryRacesIntrospection / SummaryMakeBaganrikiBrain 改造計画）
# ══════════════════════════════════════════════════

---

## 【方針転換】makeBaganrikiBrain cron は廃止しない

> **ユーザー意向**: `SummaryMakeBaganrikiBrain.php` は `SummaryRacesIntrospection.php` の総括として作ったファイル（= 馬眼力の脳みそ）。この2ファイルを変更しつつ、cron（23:50）は継続して動かす。

### 現在の役割（旧）

```
SummaryRacesIntrospection（20:50）
  → レース振り返りを生成 → t_horse_odds_finder_race_introspection に保存
  ↓ （## 分析 セクションを読む）
SummaryMakeBaganrikiBrain（23:50）
  → Claude API で分析眼を蒸留 → baganriki_brain.txt に書き出す
  ↓
AiController.php
  → baganriki_brain.txt をシステムプロンプトとして使用（AI予想の判断基準）
```

### 新しい役割（新仕様対応後）

```
SummaryRacesIntrospection（20:50）★変更あり
  → レース振り返りを生成 → t_horse_odds_finder_race_introspection に保存
  ↓ （## 分析 セクションを読む）
SummaryMakeBaganrikiBrain（23:50）★変更あり
  → Claude API で「断層タイプ別傾向・OPI帯別傾向・直近パターン」を統計サマリーとして生成
  → baganriki_brain.txt に書き出す（役割が「命令」→「統計コンテキスト」に変わる）
  ↓
AiController.php ★変更あり（ブロック2）
  → 固定システムプロンプト（仕様書の内容）を使用
  → baganriki_brain.txt は「補足統計情報」としてユーザーメッセージ末尾に付加
```

---

## SummaryMakeBaganrikiBrain.php 改造計画

### 変更箇所①：Claudeに送るプロンプト

**現在**：「以下の振り返りデータから、有力馬を選ぶ際の判断基準を蒸留してください」

**変更後**：「以下の振り返り分析から、断層タイプ別・OPI帯別・人気帯別の傾向をサマリーしてください」

出力形式の変更イメージ：

```
【直近レース統計サマリー（参考情報）】

■ 断層タイプ別の傾向
・A型（上位収束）: 1〜3番人気内での決着が多い。高人気信頼型。
・B型（中間断層）: 4〜6番人気が馬連・3連複に絡みやすい。
・C型（下位断層）: 10番人気以下の飛び込みが頻発している時期。穴候補要注目。
・D型（多断層）: 波乱傾向高。下位進入度が高い傾向。
・E型（断層なし）: オッズが平均的に並んでいるフラットな状況。

■ OPI帯別傾向（直近20レース）
・OPI >1.2（過剰人気）の馬が入賞した割合: XX%
・OPI <0.8（人気薄）の馬が入賞した割合: XX%

■ その他気になるパターン
・（直近の分析テキストから特徴的なパターンを抽出）
```

### 変更箇所②：システムプロンプト（生成時のClaude指示）

- 旧：brain.txt自身を生成に使う（自己参照ループ）
- 新：brain.txt生成専用の固定システムプロンプトを持つ

---

## SummaryRacesIntrospection.php 改造計画

### 変更箇所①：brain.txt の使い方

**現在**：`baganriki_brain.txt` をシステムプロンプトとして Claude API に渡す

```php
// 現在
$responses = $this->anthropic->sendPool(array_column($batch, 'prompt'), $brain !== '' ? $brain : null);
//                                                                        ↑ これがシステムプロンプト
```

**変更後**：振り返り分析専用の固定システムプロンプトを使い、brain.txt はユーザーメッセージ末尾の「参考情報」として付加

```php
// 変更後
$fixedSystemPrompt = "あなたは競馬のオッズ分析の専門家です。過去のレースデータを振り返り、的中・外れの要因を客観的に分析してください。";
$responses = $this->anthropic->sendPool(array_column($batch, 'prompt'), $fixedSystemPrompt);
// brain.txt の内容はプロンプト末尾に付加済み（後述）
```

### 変更箇所②：プロンプト末尾の brain.txt 付加方法

**現在**（メッセージ最後の行）：
```php
if ($brain !== '') {
    $msgs[] = 'あなたの馬眼力ブレインに蓄積された知識と判断基準を最大限に発揮して...';
}
```

**変更後**：統計情報として付加する形に書き換え

```php
if ($brain !== '') {
    $msgs[] = '';
    $msgs[] = '【直近の断層タイプ・OPI帯別統計サマリー（参考）】';
    $msgs[] = $brain;
}
```

---

## AiController.php への影響（ブロック2 と連動）

ブロック2の実装で「システムプロンプトを仕様書の固定テキストに置き換える」際、

**旧**：システムプロンプト = baganriki_brain.txt の内容（可変）

**新**：システムプロンプト = 仕様書の固定テキスト（不変）
     ＋ baganriki_brain.txt の内容をユーザーメッセージ末尾に付加（統計コンテキスト）

```php
// イメージ（ブロック2 実装時に組み込む）
$brainFile = public_path('baganriki_brain/baganriki_brain.txt');
$brain = file_exists($brainFile) ? trim(file_get_contents($brainFile)) : '';

if ($brain !== '') {
    $prompt .= "\n\n" . $brain;  // ユーザーメッセージ末尾に付加
}
```

---

## まとめ：変更が必要なファイルとタスク

| ファイル | 変更内容 | タイミング |
|----------|----------|-----------|
| `SummaryMakeBaganrikiBrain.php` | 生成プロンプトを「判断基準の蒸留」→「統計サマリー生成」に変更 | ブロック2 と同タイミング |
| `SummaryRacesIntrospection.php` | システムプロンプトを固定化、brain.txtを「参考情報」として付加 | ブロック2 と同タイミング |
| `AiController.php` | 固定システムプロンプトに変更 ＋ brain.txtを補足情報として付加 | ブロック2 |
| `crontab` | **変更不要**。両cronはそのまま継続 | — |

---

*最終更新: 2026-09-11 cron継続方針・2ファイル改造計画を追記*

---

---

# ══════════════════════════════════════════════════
# 作業チェックリスト（1セッション≒1時間）
# ══════════════════════════════════════════════════

> 作業前に必ずこのチェックリストを開き、どのセッションから再開するかを確認してから始める。
> 完了したら `[ ]` → `[x]` に書き換える。

---

## 【事前準備】DB テーブル作成（15分）

- [ ] `t_horse_odds_finder_ai_merge_result` CREATE TABLE 実行
- [ ] `t_horse_odds_finder_market_score_log` CREATE TABLE 実行
- [ ] `t_horse_odds_finder_ml_snapshot` CREATE TABLE 実行
- [ ] `summaryForecastFromLastRace`（7:30 cron）が `_getAiAnalysisPrompt()` でどう使われているか grep 確認

---

## Session 1（～1時間）：ブロック4 ＋ ブロック5

> **目標**: DeepSeek連携の基礎不具合を直す（最優先・他ブロックの土台）

- [x] **ブロック4**：DeepSeek に渡す system prompt を仕様書の内容に修正
  - 対象：`AiController.php` の DeepSeek 呼び出し部分
  - 確認：仕様書の「第二AIへのシステムプロンプト」をコピーして差し替え
- [x] **ブロック5**：DeepSeek レスポンスのプロンプト文字列除去を修正
  - 対象：`AiController.php` の `_stripPromptFromResponse()` または同等箇所
  - 確認：DeepSeek が返すテキストからプロンプト部分が正しく除去されること

---

## Session 2（～1時間）：ブロック1

> **目標**: レース指標をFlutterに届ける（FlutterのUIは実装済みなのでPHP側のみ）

- [x] **ブロック1**：AI出力の `レース指標|波乱度:X|下位進入度:X|大穴進入度:X` 行をパース
  - 対象：`AiController.php` のレスポンス解析部分
  - 追加：`race_metrics` キー（`{wave_level, lower_entry, big_gap_entry}`）をJSONレスポンスに含める
  - 確認：Flutterの `_buildRaceMetrics()` が受け取れる形式になっているか
- [x] 動作確認：Flutter でレース指標が星表示されること
  > ※ PHP側変更不要。Flutterの`parseRaceMetrics(analysisText)`が`analysis_text`から直接パース済み。`race_content_page.dart:205`, `functions.dart:319`参照

---

## Session 3（～1時間）：ブロック2 前半（システムプロンプト差し替え）

> **目標**: 第一AIのシステムプロンプトを固定化する（旧brain.txt方式から脱却）

- [x] **AiController.php**：
  - 旧：`baganriki_brain.txt` をシステムプロンプトとして渡していた箇所を変更
  - 新：仕様書の固定テキストをシステムプロンプトとして直書き（またはファイル参照）
  - brain.txt の内容はユーザーメッセージ末尾に「補足統計情報」として付加する形に変更
- [x] **SummaryRacesIntrospection.php**：
  - 旧：brain.txt をシステムプロンプトとして渡していた箇所を変更
  - 新：振り返り専用の固定システムプロンプトに差し替え
  - brain.txt の内容はユーザーメッセージ末尾に「参考情報」として付加する形に変更
- [x] 動作確認：AI予想が新しいシステムプロンプトで動作すること

---

## Session 4（～1時間）：ブロック2 後半（SummaryMakeBaganrikiBrain 改造）

> **目標**: brain.txt の生成内容を「統計サマリー」に切り替える

- [x] **SummaryMakeBaganrikiBrain.php**：
  - Claudeに送るプロンプトを「判断基準の蒸留」→「断層タイプ別・OPI帯別傾向のサマリー生成」に変更
  - 出力フォーマットを「統計サマリー形式」（断層タイプ別/OPI帯別/直近パターン）に変更
  - 生成に使うシステムプロンプトを brain.txt 生成専用の固定テキストに変更（自己参照ループを解消）
- [x] 手動で `php artisan keiba:makeBaganrikiBrain` を実行して内容を確認
- [x] 生成された brain.txt の内容がAIへの補足情報として適切な形式になっていること

---

## Session 5（～1時間）：ブロック3 ＋ ブロック15

> **目標**: 固定配点ルールの明文化 ＋ 厳選穴レース再判定

- [x] **ブロック3**：AIへの固定配点指示を仕様書通りに修正
  - 信頼度60点（7項目）＋ 妙味40点（4項目）= 100点満点の採点指示
  - 確認：AIが各項目を正しく採点できるか（テスト送信）
- [x] **ブロック15**：厳選穴レース の再判定ロジックを追加
  - 対象：`AiController.php` の最終マージ処理部分
  - 確認：再判定後の `upset_race` フラグが正しく更新されること

---

## Session 6（～1時間）：ブロック13 ＋ ブロック7

> **目標**: タイブレーカー ＋ Type A 固定枠の修正

- [x] **ブロック13**：同点タイブレーカーロジックを追加
  - 仕様書記載の優先順位に従った処理を実装
- [x] **ブロック7**：断層タイプA の unique スロットを修正
  - 現状：デフォルト 0 になっている
  - 修正：仕様書の Type A 固定枠ルールに従った値を返すように変更
- [x] 動作確認：同点ケースで正しい馬が選ばれること

---

## Session 7（～1時間）：ブロック8 前半（断層位置別・人気帯別制限）

> **目標**: ポジションベースの制限ロジック実装（前半：データ整理と構造設計）

- [x] 仕様書のブロック8「断層位置別・人気帯別の選出上限」を読み返して実装設計
- [x] 対象馬の断層位置・人気帯を判定するロジックを実装
- [x] 各カテゴリのカウンタ管理ロジックを実装（選出上限チェック）

---

## Session 8（～1時間）：ブロック8 後半 ＋ ブロック12

> **目標**: 断層制限の適用 ＋ 回収率によるハード除外

- [x] **ブロック8 後半**：選出上限ロジックを `_selectHorses()` に組み込んで動作確認
  - Block 8前半で `_mergeAiResults()` Step 9 として実装済み（上限ロジック完成）
- [x] **ブロック12**：`t_horse_odds_finder_race_result_payout` を使った回収率ハード除外
  - `|` 区切りのパース処理（`_parsePayoutString()` で実装済み）
  - 回収率が閾値以下の馬をハード除外する条件分岐を追加（`_mergeAiResults()` 前に適用）
  - 確認：正しく除外されること

---

## Session 9（～1時間）：ブロック6（F1〜F8 フラグ DB値判定）

> **目標**: キーワードマッチングを廃止し、DB値による正確なフラグ判定に切り替え

- [x] **ブロック6**：F1〜F8 の各フラグをAIテキスト解析からDB値で判定する方式に変更
  - F2: 複勝人気 - 単勝人気 ≥ 2（DB: t_horse_odds_finder_odds 6分前）
  - F3: 複勝流入ランク ≤ 2（$oddsData テキストパース）
  - F4: 7番人気以下 かつ 9→6分前で単複2%超下落（$oddsData テキストパース）
  - F6: 類似レース統計 N≥30 かつ 5着以内率≥70%（$oddsData テキストパース）
  - F7: 予測補正OPI ≤ 0.95（$oddsData テキストパース）
  - F8: 回収率≥110% かつ N≥30（$oddsData テキストパース）
  - F1・F5: Block 9 依存のため暫定 false（スキップ）
  - 既存の evidenceKeywords キーワードマッチングを完全削除
  - $horseFlagsMap[num] = [F1..F8] を構築して _mergeAiResults() に渡す
  - タイプA昇格ロジック: F-flagsが3個以上 かつ score≥80 → $secondUniqueLimit 0→1
- [ ] 動作確認：各フラグが正しく立つこと（実データで確認）

---

## Session 10〜11（各～1時間、計2時間）：ブロック9（出走履歴による能力適性評価）

> **目標**: `t_horse_odds_finder_shutsuba_history` を使った能力評価を第2AIに追加

- [x] **Session 10**：
  - 対象馬の出走履歴を `shutsuba_history` から取得するクエリを実装（t_horse_odds_finder_horsesで馬名取得→直近5走）
  - 取得データを整形して DeepSeek へのプロンプトに付加する処理を実装（$b9HistoryText として $oddsData に追記）
  - system prompt に能力・適性評価の6項目採点指示を追加（「能力適性:X（XX点）。」先頭フォーマット）
  - 正しい出力例を更新（能力適性グレード付き）
- [x] **Session 11**：
  - DeepSeek のレスポンスから能力適性グレード（A〜D）を抽出するパース処理を実装
    （`^能力適性:([ABCD])[（(](\d+)点[）)]` で reason 先頭をパース）
  - 高配当総合点への能力適性補正（A=+20/B=+15/C=+10/D=+0）を適用する処理を追加
    （`min(100, $score + $corr)` で上限100点キャップ）
  - $b9h に ability_grade / ability_score キーを追加
- [ ] 動作確認：実データで能力評価が正しく付与されること

---

## Session 12（～1時間）：ブロック14（統合結果テーブル保存）

> **目標**: `t_horse_odds_finder_ai_merge_result` への保存を実装

- [x] **ブロック14**：`_mergeAiResults()` の出力を `ai_merge_result` テーブルに INSERT/UPDATE ✅ Session 12実装済み
  - 実DBスキーマ（`merge_result text`）に合わせ、全データをJSONで格納
  - 保存内容: `upset_race`, `gap_type`, `wave_level`(null予定), `lower_entry`(null予定), `big_gap_entry`(null予定), `merged_horses`
  - UPSERT（INSERT ... ON DUPLICATE KEY UPDATE）で実装、`try/catch`で例外を握り潰し本体処理は継続
- [ ] 動作確認：レース実行後にレコードが保存されていること

---

## Session 13（～1時間）：ブロック10（高配当精度強化 シャドーログ）

> **目標**: Phase 1 = ログ記録のみ（まだ採点には使わない）

- [x] **ブロック10**：PHP側で市場妙味基礎点（スコアA〜E）を計算 ✅ Session 13実装済み
  - score_a（複勝流入ランク 0〜20）/score_b（複勝優位性 0〜15）/score_c（OPI妙味 0〜15）/score_d（回収率 0〜15）/score_e（断層タイプ 0〜15）
  - `$b9HorseRows`（全馬）をループ、`$oddsHorseBlocks`・`$b6TanPopMap`・`$b6FukuPopMap` から算出
  - バルク INSERT ... ON DUPLICATE KEY UPDATE で一括 UPSERT
  - try/catch(\Throwable) でエラー吸収、フェーズ1（シャドー）: AIへ未送信・Flutter未表示
- [ ] 動作確認：ログが正しく記録されること（採点への適用は将来フェーズ）

---

## Session 14（～1時間）：ブロック11（断層パターン ML スナップショット）

> **目標**: Phase 1 = 特徴量保存のみ（モデル学習は将来フェーズ）

- [x] **ブロック11**：レース実行時に断層パターンの特徴量を `t_horse_odds_finder_ml_snapshot` に保存 ✅ Session 14実装済み
  - `gap_type`（断層タイプ A〜E）, `features`（JSON: 断層構造・レース指標・統合馬サマリー・スコア指標）を INSERT
  - 保存特徴量: gap_type/primary_gap_upper_pop/merge_limits/horse_count/upset_race/cond_a〜d/merged_counts/merged_pops/merged_scores/score_e/first_second_ai_count
  - `result_label` は結果確定後のバッチで更新（今回は NULL のまま）
  - try/catch(\Throwable) でエラー吸収、フェーズ1（シャドー）: 予測は行わない
- [ ] 動作確認：スナップショットが正しく保存されること

---

## 全セッション完了後の最終確認

- [ ] 全ブロック動作確認（実レースデータで一通り流す）
- [ ] `makeBaganrikiBrain`（23:50）cron を手動実行して統計サマリーが正しく生成されること
- [ ] `SummaryRacesIntrospection`（20:50）cron を手動実行して振り返りが正しく保存されること
- [ ] Flutter でレース指標・配点・厳選穴レースフラグが正しく表示されること

---

*追記: 2026-09-11 セッション分割・作業チェックリスト追加*
