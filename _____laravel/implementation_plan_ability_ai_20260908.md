# 実装計画：能力・適性AI追加版（3系統統合）
作成日：2026-09-08  
対象ファイル：`app/Http/Controllers/Api/AiController.php`  
参照：`馬眼力_統合完成プロンプト_能力適性AI追加版_20260908.txt`

---

## 変更点サマリー

| # | 種別 | 内容 |
|---|------|------|
| 1 | 新規 | 能力・適性AI（④）のバッチ処理クラス追加 |
| 2 | 新規 | 能力・適性AI結果保存用DBテーブル |
| 3 | 変更 | `_mergeAiResults()` を3系統対応に拡張 |
| 4 | 変更 | `getHorseOddsFinderSecondAiOpinion()` で能力AIデータを取得し統合に渡す |
| 5 | 変更 | `forecastNums`（③注目馬番）の生成元を能力AI候補から生成する仕様の確認 |
| 6 | 変更 | 2nd AIプロンプト整形ロジック（削除対象ブロック追加）|

---

## 変更点詳細

---

### 変更① 新規：能力・適性AI バッチ処理

**現状：** 存在しない  
**新仕様：** 土日 7:30 のバッチで全出走馬の過去成績をClaudeに投げ、能力評価をJSON取得してDBに保存

#### 推奨実装場所
`app/Console/Commands/RunAbilityAiBatch.php`（新規Artisanコマンド）  
またはLaravelスケジューラに登録（`App\Console\Kernel`）

#### バッチ処理の流れ
```
1. 当日レース一覧を t_horse_odds_finder_races から取得
2. 各レースの全出走馬の直近5〜10走データを取得
   （t_horse_odds_finder_horses + 過去成績テーブル）
3. 能力・適性AI用システムプロンプト＋ユーザープロンプトを組み立て
4. Claude API に送信
5. JSON レスポンスをパース
6. DB（新テーブル）に保存
7. forecastNums 文字列（パイプ区切り）を t_horse_odds_finder_forecast_from_last_race に保存
8. パース失敗時はログ保存＋従来の馬番選出結果へフォールバック
```

#### 能力・適性AI システムプロンプト（プロンプト文書 p.509〜558 そのまま）
- 100点採点 6項目（基礎能力25＋近走内容20＋コース適性20＋脚質展開15＋上がり10＋補正10）
- 評価区分：A(80〜100) / B(70〜79) / C(60〜69) / D(59以下)
- 出力形式：JSONのみ（前置き・後書き・Markdown禁止）

#### 出力JSONフォーマット
```json
{
  "candidates": [
    {
      "horse_number": 3,
      "horse_name": "サンプルホース",
      "ability_score": 82,
      "grade": "A",
      "reasons": ["近3走で同クラス上位", "今回と同じ距離で安定", "想定される流れに脚質が合う"]
    }
  ]
}
```

#### 候補上限（能力AIの選出上限）
- 8頭以下：最大4頭
- 9〜13頭：最大5頭
- 14〜15頭：最大6頭
- 16頭以上：最大7頭
- 原則70点以上のみ候補とし、基準不足なら上限まで埋めない

---

### 変更② 新規：DBテーブル

**現状：** 能力AI専用テーブルなし。`t_horse_odds_finder_forecast_from_last_race` は `forecast_nums`（文字列）のみ保存  
**新仕様：** 能力評価の詳細を保存する専用テーブルを新設（または既存テーブルを拡張）

#### 推奨：新テーブル `t_horse_odds_finder_ability_ai`

```sql
CREATE TABLE t_horse_odds_finder_ability_ai (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  date          DATE         NOT NULL,
  kaisuu        VARCHAR(4)   NOT NULL,
  basho         VARCHAR(4)   NOT NULL,
  day           INT          NOT NULL,
  race          INT          NOT NULL,
  horse_number  INT          NOT NULL,
  horse_name    VARCHAR(64)  NOT NULL,
  ability_score INT          NOT NULL,
  grade         CHAR(1)      NOT NULL,  -- A/B/C/D
  reasons       JSON         NOT NULL,
  ai_run_at     DATETIME     NOT NULL,
  prompt_version VARCHAR(32) NOT NULL,
  created_at    TIMESTAMP    NULL,
  updated_at    TIMESTAMP    NULL,
  UNIQUE KEY uq_race_horse (date, kaisuu, basho, day, race, horse_number)
);
```

#### `t_horse_odds_finder_forecast_from_last_race` への影響
- 現在：`forecast_nums` カラムに馬番パイプ区切り文字列を保存
- 新仕様：能力AIのcandidates（horse_number）から馬番リストを生成して `forecast_nums` に書き込む
- **既存の読み込み処理（`_getAiAnalysisPrompt` 内の `$forecastNums`）は変更不要**

---

### 変更③ 変更：`_mergeAiResults()` を3系統対応に拡張

**現状のシグネチャ：**
```php
private function _mergeAiResults(
    array  $firstAiHorses,
    array  $secondAiHorses,
    string $gapType,
    int    $totalHorses
): array
```

**新シグネチャ（案）：**
```php
private function _mergeAiResults(
    array  $firstAiHorses,
    array  $secondAiHorses,
    string $gapType,
    int    $totalHorses,
    array  $abilityAiData = []   // ← 新規追加（省略可能で後方互換を維持）
): array
```

#### `$abilityAiData` の構造
```php
// t_horse_odds_finder_ability_ai から取得した配列
// キー = horse_number
[
  3 => ['grade' => 'A', 'ability_score' => 82, 'reasons' => [...]],
  7 => ['grade' => 'B', 'ability_score' => 74, 'reasons' => [...]],
]
```

#### 新処理ステップ（既存8ステップに追加）

```
Step1: 出走頭数別の最終表示上限を決定（変更なし）
Step2: 断層タイプ別の2nd AI独自発見枠上限（変更なし）
Step3: 馬番インデックス構築（変更なし）
Step4: matched / firstOnly / secondOnly に分類・統合おすすめ度算出（変更なし）

★ Step4.5: 各候補に能力評価(grade/score)を付与する【新規】
  foreach ($allNums as $num) {
      $ability = $abilityAiData[$num] ?? null;
      // matched/firstOnly/secondOnly の各馬に ability_grade, ability_score を追加
  }

Step5: 2nd AI独自発見馬の採用条件判定（変更なし）

★ Step5.5: 能力AIだけが選んだ馬の市場根拠判定【新規】
  能力AIにしかいない馬（$abilityAiData にあるが firstMap/secondMap にない）を対象に、
  以下を全て満たす場合のみ最終比較に残す：
  - ability_score >= 80（Aグレード）
  - 市場根拠2項目以上（複勝継続下落・複勝流入ランク上位・単勝より複勝支持強・
    直前流入加速・断層接近/縮小/矛盾・予測補正OPI妙味・回収率110%以上）
  ※ 市場根拠はオッズデータを持たないため、ここでは「条件を満たす可能性がある」
    として $qualifiedAbilityOnly に追加し、Step7で比較対象に含める

Step6: メイン候補をスコア降順でソート（変更なし）
Step7: 独自発見枠の追加/入れ替え（変更あり）

★ Step7の優先順位変更【変更】
  同程度の統合おすすめ度での最終枠比較時のみ、以下の順で優先：
  1. 両オッズAI一致 ＋ 能力 A/B
  2. 両オッズAI一致
  3. オッズAI片方 ＋ 能力 A/B ＋ 市場根拠2項目以上
  4. 2nd AI独自発見条件を満たす馬
  5. 能力A ＋ 市場根拠2項目以上

  ※ この優先順位は低配当除外・回収率・人気帯上限・最低基準点より「下位」ルール

★ Step7.5: 能力AI単独候補の入れ替え判定【新規】
  $qualifiedAbilityOnly が存在し、独自発見枠に空きがある場合：
  - 最低点候補との比較（$candidate['score'] > $weakestScore、または5点差以内で2項目以上優れる）
  - タイプAは能力AIだけで最大1頭、B/Cは最大1頭、D/Eは最大2頭（2nd AIと合算）

Step8: displayLimit 以内にスライス（変更なし）
```

#### 既存ルールを必ず維持
- 馬番による重複排除
- 両オッズAI一致馬への一致加点5点
- 2nd AI独自発見馬の保護条件（$evidenceKeywords、スコア70以上）
- 断層タイプ別人気帯上限
- 出走頭数別の最終表示上限
- 低配当除外・回収率フィルター
- Flutterパース形式の維持

---

### 変更④ 変更：`getHorseOddsFinderSecondAiOpinion()` 内の統合呼び出し

**現状（L.1690〜1697）：**
```php
$firstAiHorses  = $this->_parseAiHorses($firstAiText);
$secondAiHorses = $this->_parseAiHorses($analysisText);
$mergedHorses   = $this->_mergeAiResults(
    $firstAiHorses,
    $secondAiHorses,
    $gapTypeForMerge,
    $horseCount2nd
);
```

**新実装：**
```php
// 能力・適性AI結果をDBから取得
$abilityRows = DB::table('t_horse_odds_finder_ability_ai')
    ->where('date',   $date)
    ->where('kaisuu', $kaisuu)
    ->where('basho',  $basho)
    ->where('day',    $day)
    ->where('race',   $race)
    ->get(['horse_number', 'ability_score', 'grade', 'reasons']);

$abilityAiData = [];
foreach ($abilityRows as $row) {
    $abilityAiData[(int)$row->horse_number] = [
        'grade'         => $row->grade,
        'ability_score' => (int)$row->ability_score,
        'reasons'       => json_decode($row->reasons, true) ?? [],
    ];
}

$firstAiHorses  = $this->_parseAiHorses($firstAiText);
$secondAiHorses = $this->_parseAiHorses($analysisText);
$mergedHorses   = $this->_mergeAiResults(
    $firstAiHorses,
    $secondAiHorses,
    $gapTypeForMerge,
    $horseCount2nd,
    $abilityAiData    // ← 第5引数を追加
);
```

#### レスポンスへの追加（任意）
`merged_horses` の各馬に `ability_grade` / `ability_score` を含めるとFlutter側で表示可能。
```php
// 既存
'merged_horses' => $mergedHorses,
// ↓ _mergeAiResults内で付与した ability_grade を含むため自動的に伝播する
```

---

### 変更⑤ 2nd AIプロンプト整形ロジック（確認のみ）

**現状の除去対象（L.1518〜1530）：**
```php
// ① 厳選穴レース判定ルールブロック除去
// ② 厳選穴レース|1または0 行除去
// ③ 末尾の選出馬指示行除去
// ④ 回収率データの使い方指示行除去
// ⑤ おすすめ度の計算方法ブロック除去
// ⑥ このシステムの目的ブロック除去
// ⑦ 回収率優先・低配当除外ルールブロック除去（既に実装済み L.1529-1530）
```

**新仕様で追加された除去対象：** プロンプト文書 p.386〜391 によると、現状実装（①〜⑦）と一致しており**追加変更なし**。

---

### 変更⑥ `merged_horses` レスポンスの ability 情報追加（Flutter連携用・任意）

Flutterアプリで能力グレード（A/B/C/D）を表示する場合は `_mergeAiResults()` から返す各馬に以下を含める：

```php
[
  'num'           => $num,
  'name'          => ...,
  'score'         => ...,
  'ability_grade' => $ability['grade'] ?? null,   // ← 追加
  'ability_score' => $ability['ability_score'] ?? null, // ← 追加
  ...
]
```

---

## 実装順序（推奨）

```
Step A: DBマイグレーション作成・実行
         → t_horse_odds_finder_ability_ai テーブル新設

Step B: バッチコマンド作成
         → app/Console/Commands/RunAbilityAiBatch.php
         → Kernel.php にスケジュール登録（土日7:30）
         → テスト実行（手動）・JSONパース確認

Step C: AiController.php の _mergeAiResults() 拡張
         → 引数追加（$abilityAiData = []）
         → Step4.5・5.5・7.5 の追加
         → ability_grade / ability_score の付与

Step D: getHorseOddsFinderSecondAiOpinion() の修正
         → t_horse_odds_finder_ability_ai からの読み込み追加
         → _mergeAiResults() への第5引数追加

Step E: 動作確認
         → 能力AIデータなしでも従来どおり動作すること（後方互換）
         → 能力AIデータありで統合結果が変化することを確認

Step F: 検証開始（200〜300レース蓄積まで補強材料扱い）
```

---

## 注意事項

### forecastNums（③注目馬番）の生成元について
- 現状：`t_horse_odds_finder_forecast_from_last_race.forecast_nums` を SELECT するだけ
- 新仕様：能力AIの候補馬番から `forecast_nums` を生成してこのテーブルに INSERT/UPDATE する
- **`_getAiAnalysisPrompt()` 内の読み込み側（L.1152〜1159）は変更不要**
- バッチ処理（Step B）で書き込み側を実装する

### 能力AIが取得できない場合のフォールバック
- 土日バッチ未実行・DB空の場合は `$abilityAiData = []` のまま
- `_mergeAiResults()` の第5引数がデフォルト `[]` なので従来の2系統統合として動作する
- **既存のFlutterパース形式・1st AI / 2nd AI の動作は一切変わらない**

### 検証ルール（プロンプト文書 p.644〜651）
導入後200〜300レース蓄積まで能力適性評価を「補強材料」扱いとし、強制フィルターにしない。  
以下を人気帯別・断層タイプ別に集計できるログ設計を推奨：
- 5着以内率・3着以内率
- 単勝・複勝回収率
- 能力AI一致馬・能力AI単独馬・オッズAI単独馬の成績
- 能力AI追加により拾えた馬・押し出された馬の成績

---

## 影響範囲（変更なし）

以下のメソッド・ロジックは今回の変更で一切触らない：

- `getHorseOddsFinderAiAnalysis()`（1st AI呼び出し・キャッシュ処理）
- `_getAiAnalysisPrompt()`（プロンプト本文生成）
- `_parseAiHorses()`（AIテキストパース）
- `getHorseOddsFinderBaganrikiIndex()`（馬眼力指数）
- 2nd AI のプロンプト整形ロジック（除去パターン）
- 2nd AI のシステムプロンプト文言
- Flutterパース形式（`馬番：X、馬名：XXX、...` 形式）

---

*以上。夜の実装時に Step A → B → C → D → E の順で進めてください。*
