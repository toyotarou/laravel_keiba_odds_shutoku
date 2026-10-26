馬眼力 r12.2 Laravel/PHP 修正案011 再精査10版
2026年10月21日／豊田様向け

今回の依頼①Job構成と②数値の型保持について、同梱のAiController.phpを基に
具体的な差し替え候補を作りました。設計回答010をコードにした提案です。
本番反映・モデル切替・有料API実行は行っていません。

今回の差替理由（10回目）
取得原JSONで長い小数が丸められる問題、重複キー・不正馬番・不正行を採用する問題、
boolを単勝1倍と扱い配列等で処理が止まる問題を修正しました。正常な別バッチや途中時点の
部分欠損は保持します。原JSONにも共通パーサーの4 MiB・深さ64・数値制限を適用します。
「再精査10回目_20261021.txt」と「確認範囲と未確認項目_20261021.txt」が最新の判断です。
実施した1,291検査は verification/全検査項目一覧.txt に全件記載しています。

前回の差替理由（9回目・履歴）
正しいPAIR／適用COHORT根拠の除外、入力JSONの誤拒否・容量検査漏れ、出馬表と取消の
不整合の見逃し、統計原値の桁落ち、統計時刻の行間流用、DB障害の重複誤認を修正しました。
「再精査9回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（8回目・履歴）
日時の境界を再精査し、不正なタイムゾーン・末尾改行の受入、取得監査の不正日時の
補完／消失、読めないlive締切を無視する問題を修正しました。取得日時の型不正は
該当バッチだけを除外し、正常な別バッチは採用します。同じ予定時刻の小数秒表記違いも
正しく照合します。「再精査8回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（7回目・履歴）
出典がない0点を採点完了と扱ってしまう問題と、DB形式日時の変換時に不正な日付・時刻や
長すぎる小数秒を読み替えてしまう問題を修正しました。正常な0点と日時は保持します。
「再精査7回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（6回目・履歴）
必須項目の欠落をnullと同一視する問題、role_quotaの余分なキーを見逃す問題、
同じ配分でもJSONの記載順によって正常な応答の加点証明を失う問題を修正しました。
不正応答の順位点混入と、正常応答の両AI一致加点が消える例を再現して確認しています。
「再精査6回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（5回目・履歴）
採点出典の不正を検出しても点数が残る問題、採点不正の組合せで正常な個別根拠を持つ
馬まで落ちる問題、不正な訂正行が他馬の正しい訂正を妨げる問題、初回と訂正を
組み合わせた後の評価に合わない順位を使う問題を修正しました。
「再精査5回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（4回目・履歴）
再精査3版に残っていた、不正な馬番・重複馬番・horsesのobject型の隔離不足、
根拠台帳が空の場合の資格検査漏れ、非適格馬まで含める順位方式の判定、
締切前倒し後の受領時刻の再照合漏れを修正しました。
以前届いた詳細設計書v1.3との照合で、前回保留した順位方式の対象も確認できました。
「再精査4回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（3回目・履歴）
前回の再精査2版に、原応答と実効順位が一致しない加点証明、採点完了状態と
内訳の不整合、採点根拠が壊れた場合の点数保持、採点救済後の順位方式、
順位方式の正しい訂正を拒否する問題が残っていました。今回この5点を修正しました。
「再精査3回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（2回目・履歴）
前回の再精査版にも、訂正予約の競合後にrole完了としてしまう問題と、
送信許可でロック取得時の最新live行を捨てて古い読取結果を使う問題が残っていました。
今回はこの2箇所を修正しました。前回の説明書にあったAI終了理由の対応逆記も訂正。
「再精査2回目_20261021.txt」はその時点の履歴資料です。

前回の差替理由（履歴）
前回の011には未送信処理の復旧、Jobの投入抑制、送信直前の期限／全頭取消確認、
未完了応答判定、数値と訂正範囲の保持などに不足がありました。再精査で修正しています。
前回の記録は「再精査結果_20261021.txt」です。最新の判断は今回の結果と実行記録を使います。
前回の011をそのまま適用せず、この差替版を組込み候補にしてください。

最初にお読みください
・これは「隔離した非公開検証環境へ組み込むためのレビュー用コード」です。
・PHP 8.3.33で35ファイルの構文確認、既存41項目、再精査29項目、
  Controller内の自己試験662項目、再精査2版の6項目、再精査3版の28項目、
  再精査4版の26項目、再精査5版の42項目、再精査6版の40項目、再精査7版の67項目、
  再精査8版の100項目、再精査9版の111項目と今回追加139項目が通過。
  機能試験は計1,291成功／0失敗です。
  今回の139項目は直前の再精査9版では56成功／83失敗、今回版では139成功／0失敗です。
  再精査9版の既存試験ファイルと自己試験は変更せず、追加試験を別ファイルにしました。
  別途、1頭の検証用応答の型を置き換えた1,053ケースで検証処理の異常終了がないことを確認。
  これは全ての不正入力を網羅した証明ではありません。
  DB／Queue／ロックは試験用代替実装です。PHP自体もWASM版です。
  実Laravel、MySQL、Supervisorの並列動作・負荷・障害復旧試験は未実施です。
  「不具合ゼロ」「本番適用可能」を証明する結果ではありません。
・Laravelの版、composer.lock、既存migration、AnthropicService、実際のルート、
  正式取消の取得処理、VPSのメモリ容量が未提供のため、接続確認が必要です。
・帯のSCORE／EVIDENCE判定は、詳細設計書v1.3第6章の「帯内の全適格馬」に合わせました。
  非適格馬や隔離された不正行は判定に含めず、採点救済後の適格馬は含めます。
  照合元と該当仕様はreferences/今回照合した設計仕様.txtに記録しています。
・17／18頭の入力容量問題はこのパッチでは解決しません。モデル・過去走数・
  採点辞書・帯・配分・しきい値は変更していません。モデル移行は回答010の別件です。

1. 設計の選択

Queueはdatabase、常駐はSupervisorを選びます。Redisは追加しません。
databaseでも処理するworkerは必要です。cronは巡回の起動に使い、HTTP待ちの
workerと期限処理のworkerを分けます。cronの--onceだけにはしません。

旧同期経路はコード上残しますが、Job対象の失敗時には自動で戻しません。
送信後の同期再実行は二重課金につながるためです。ジョブの所有記録は停止後も保持し、
旗をoffにしても対象レースを旧同期送信へ戻さない構成です。
有効化中は通常のAIアクセス入口を全件Job受付へ切り替え、対象外を送信しません。
したがって既存商品の稼働環境へそのまま有効化する用途ではありません。

数値は値オブジェクト、比較は型を意識する関数、保存は専用serializerを選びます。
PHPに収まる整数はint、それ以外のJSON数値はDecimalです。数値と文字列は別物です。
ご提案のjsonSerialize(): stringは、通常のjson_encodeで引用符付き文字列に
なってしまいます。本案では誤使用を例外にし、ExactJson::encodeで数値として保存します。

2. 同梱ファイル

app/Http/Controllers/Api/AiController.php
  修正済みの全文。新規Supportクラスと一緒に組み込んでください。
integration/AiController.patch
  提供原本に対する差分。全文差し替えとパッチ適用のどちらか一方を使います。
integration/011_to_rechecked_AiController.patch
  最初に提出した011のControllerに対する差分。
integration/reaudit1_to_reaudit10_AiController.patch
  1回目の再精査版から今回への差分。
integration/reaudit2_to_reaudit10_AiController.patch
  再精査2版から今回への差分。
integration/reaudit3_to_reaudit10_AiController.patch
  再精査3版から今回への差分。
integration/reaudit4_to_reaudit10_AiController.patch
  再精査4版から今回への差分。
integration/reaudit5_to_reaudit10_AiController.patch
  再精査5版から今回への差分。
integration/reaudit6_to_reaudit10_AiController.patch
  再精査6版から今回への差分。
integration/reaudit7_to_reaudit10_AiController.patch
  再精査7版から今回への差分。
integration/reaudit8_to_reaudit10_AiController.patch
  再精査8版から今回への差分。
integration/reaudit9_to_reaudit10_AiController.patch
  直前の再精査9版から今回への差分。Controller内の2メソッドのみ変更しています。
  変更箇所は再精査10回目の結果とverification/patch_integrity.txtに記載しています。
  11種類の差分は適合する基準の一つだけを使い、二重適用しないでください。
  全てControllerのみの差分です。組込み全体は同梱のSupport・Job・migration等を使います。
integration/BASE_SHA256.txt、MODIFIED_METHODS.txt
  原本照合値と変更メソッド一覧。他のSummaryコマンド3本は変更していません。
app/Jobs/Baganriki/
  FreezeRaceJob、RunRoleJob、AggregateRaceJob、UpdateRaceLiveJobと共通基底。
app/Support/Baganriki/
  R122Jobs、Decimal、ExactJson、ExactStorage、JobPolicy。
app/Console/Commands/R122Tick.php
  独立した巡回起動。HTTP応答を待ちません。
config/baganriki_jobs.php
  既定off、非公開、対象race_idの完全一致リスト。
database/migrations/
  000010: database queue・所有記録・role完了状態・enqueue抑制・APIメタデータの5表。
  000011: enqueue抑制表へのjob_id追加。前回011導入済みの場合も追加適用が必要です。
integration/
  queue接続、scheduler、Supervisor、live更新、DB確認SQLの例。
tests/
  外部APIを使わず、修正後の実Controllerを読み込む試験。
verification/
  今回の実行結果と構文確認結果。過去版の失敗再現ログも履歴として含みます。
  verification/README_実行記録.txtで今回の結果と履歴を区別してください。

3. 実際の処理のつながり

(1) 通常アクセス、r122AfterCached、r122SweepPending、R122Tick
    → R122Jobs::request → FreezeRaceJob。
    snapshotが無いレースも巡回します。過去時刻や取得監査を捏造しません。
(2) FreezeRaceJob → 既存r122Freeze。実際の凍結可否をそのまま使います。
    期限用AggregateRaceJobを予約してから1st・2ndを別queueへdispatchします。
(3) RunRoleJob → r122SendFirst → 原応答保存 → r122AfterReceive。
    検証、必要な訂正1回、訂正の解決まで終えた後にroleをsettledにします。
    RECEIVEDだけでは完了扱いにしません。初回からAfterReceiveを二重呼出ししません。
    訂正予約のSTATE_CHANGEDは、予約が成立していなければ未完了のまま次の巡回へ回します。
    初回はRECEIVEDから検証を再開し、初回APIを呼び直しません。
(4) AggregateRaceJob → publicationGate → r122PublishWhenReady → r122Publish。
    期限前は両roleのsettledとジャーナルfingerprint一致が必要です。
    応答期限を厳密に過ぎたら片側が未完でも起動し、既存の期限・revision照合へ渡します。
    AI待ちとは別queue／workerです。既存の保存直前チェックも残します。
(5) UpdateRaceLiveJob → 正式取消・前倒し締切の反映 → state_revision更新 → 再起動。
    同じ更新は無変更。古いrevisionは更新関数内で拒否し、Jobが現revisionを再取得して
    確認済み取消の追加／締切前倒しだけを再試行します。取消解除・締切延長はしません。
    既存の取消後配分関数を呼び、人気順位・凍結帯を変更しません。
    呼出元の正式取得処理は未提供です。live_update.example.phpをそこへ接続してください。

送信の再実行について
・実HTTPの送信権は既存のreserve／allowSend／SENTジャーナルで一度だけ取得します。
・RESERVEDで停止した処理は同じtokenを再利用します。SENTやUNKNOWNを再送しません。
・RECEIVEDで停止した処理は検証から再開し、初回APIは呼び直しません。
・処理の排他はMySQL GET_LOCKです。投入抑制はqueueのjob_idを記録し、
  待機中のJobがある間は再投入しません。2秒の起動猶予後も処理ロックがある間は抑制し、
  worker切断後はジャーナル状態を見て復旧します。SENTを再送するための復旧ではありません。
  Job投入とjob_id記録は同じwriter接続の一つのトランザクションで確定します。
  GET_LOCKは接続に属し、workerの切断時に解放されます。HTTP待ち中に行ロックの
  トランザクションを持ち続けません。単一MySQL writerへの同一接続が前提です。
・allowSendはFOR UPDATEで取得したlive行をそのまま使います。
  REPEATABLE READでは後続の通常SELECTが古い読取像を見る場合があるため、読み直しません。
・期限予約後にDB／workerが停止すれば期限を逃し得ます。停止時間を取り戻すために
  締切を延ばしたり、SENTを再送したりはしません。

4. 数値の実経路

BaganrikiResponse::extract
 → BaganrikiJson::strict（従来の制限検査＋ExactJson::decode）
 → isolateHorsesで馬番キーへ組替え
 → BaganrikiDiagnose::run
 → sameHorseRow／acceptCorrection／r122ResponseUsedの型付き比較
 → r122SaveValidation／r122PublishのExactJson::encode

number_pathsは監査専用です。5,000件の上限が値の保持を制限しません。
空の{}と数字キーのobjectはstdClass、[]は配列として残します。
このstdClassを途中で(array)へ変換してから保存しないでください。
構造自体を編集して通常の連想配列を空にする場合は、objectであることを明示してください。
採点整数・馬番・配分をDecimalから(int)へ強制変換しないよう検査も補いました。
スコアの上限・加点・選抜式を変える修正ではありません。
採点10項目とscore_status／total_scoreの整合を検査し、不正な採点根拠は該当項目を
nullにして再計算します。根拠欄全体が壊れている場合は全採点項目を対象にします。
検出済みの不正採点項目は、馬行の資格が正常でも必ず無効化します。根拠参照と合計値の
不正が重なっても、正常な個別SIGNALと残りの正常な点数を保持します。
不正な資格・馬番・必須項目などは、この採点救済で正常扱いにしません。
救済で再計算した訂正行はrow_valid=falseとし、元の正常行を上書きさせません。
不正な訂正行を先に除き、他馬の正しい訂正を独立して照合します。
順位方式は検証後に使える採点に基づいて検査します。訂正対象の帯では、その帯の
既存行のrank_basis／rank_reasonも訂正できます。他の帯や業務項目の変更は許可しません。
順位を使う前に、初回と訂正を統合した実際の評価でも方式の整合を再検査します。
訂正順位・保持する初回順位とも対象です。不整合時は既存の検証済み予備根拠順位等へ
縮退し、理由を残します。訂正を許可する帯の範囲を広げる修正ではありません。
馬番は整数のhorse_noを必要とし、文字列の数値やnumへの読み替えはしません。
同一馬番の重複はその馬の全出現を隔離し、他の正常馬と正しい訂正の救済を維持します。
horsesはJSON配列を必要とし、objectを配列に読み替えて正常扱いしません。
順位方式の対象は検証後の適格馬です。根拠台帳が空でも個別SIGNALの資格検査を省略しません。
加点証明は採用した馬の行だけでなく、実際に使う帯順位も原応答と一致する場合に限ります。
保存済み応答を選出へ使う時にも、現在の実効応答期限とreceived_atを照合します。
期限と同時刻の受領は採用できますが、1マイクロ秒でも後なら採用しません。
遅着応答、受領時刻不明、正本締切不明は選出へ使わず、原文等を監査用に保持します。
再読込はExactJson::decode($row->normal_part)を使います。通常のjson_decodeや
Eloquentのarray/json castに戻すと、小数の精度と空objectの型を失う可能性があります。
既存の訂正解決はraw_responseを再度strictに読み込む経路を維持しています。
訂正要求のinputには検査済みの凍結input_json本文をそのまま埋め込みます。
小数・指数表記・空objectを再decode/reencodeで変えないようにしています。

DBの保存形式は必須確認です。
既存DDLは同梱されていません。raw_response、normal_part、数値を持つJSON本文の
保存列はMEDIUMTEXT／LONGTEXT等で原JSONテキストを保持し、MySQL JSON列・DOUBLE列・
JSON関数への変換で丸めないことを確認してください。integration/preflight.sqlで
列の型と既存の一意制約を確認し、実MySQLで長い小数の往復を試験してください。
この条件が未確認の間は「DB保存まで精度保証済み」としないでください。
ExactStorageはraw_responseとnormal_partの2列がMEDIUMTEXT／LONGTEXTであることを
送信前／検証保存前に検査し、不適合・表欠落なら停止します。既存列を自動変更しません。
他の本文列や既存アプリのcast、DBトリガーまで検査するものではありません。

5. 組込み順序（隔離環境）

1) composer show laravel/frameworkとcomposer.lockでLaravel版を確認します。
   通常のApp名前空間のPSR-4が前提です。APIはLaravel 8後期以降を想定していますが、
   本件の実Laravel版への適合は未確認です。PHP CLI 8.3、mbstring、pdo_mysql、pcntl、
   HTTPタイムアウト設定、Asia/Tokyoの日時設定、共有cacheを確認してください。
2) 現在のAiControllerとBASE_SHA256を照合します。原本と違う場合は全文上書きせずマージします。
   原本なら git apply --check integration/AiController.patch で差分を確認します。
   最初の011なら integration/011_to_rechecked_AiController.patch、1回目の再精査版なら
   integration/reaudit1_to_reaudit10_AiController.patch、再精査2版なら
   integration/reaudit2_to_reaudit10_AiController.patch、再精査3版なら
   integration/reaudit3_to_reaudit10_AiController.patch、再精査4版なら
   integration/reaudit4_to_reaudit10_AiController.patch、再精査5版なら
   integration/reaudit5_to_reaudit10_AiController.patch、再精査6版なら
   integration/reaudit6_to_reaudit10_AiController.patch、再精査7版なら
   integration/reaudit7_to_reaudit10_AiController.patch、再精査8版なら
   integration/reaudit8_to_reaudit10_AiController.patch、再精査9版なら
   integration/reaudit9_to_reaudit10_AiController.patch を選びます。
   各基準のSHA256はverification/patch_integrity.txtに記録しました。
   新規app/configファイルとmigrationを配置し、Composer autoloadを更新します。
3) queue_connection.phpの戻り値をconfig/queue.phpの
   connections['baganriki_database']へ追加します。既存queue.php全体は置き換えません。
   同じdefault DB接続／単一MySQL writerと、対象表のInnoDBが必要です。
   別DB接続への振分けは拒否します。InnoDBはpreflight.sqlで確認してください。
   この設定例はafter_commit=trueですが、投入抑制を行う3種のJobはbeforeCommit()を
   明示し、queue行とjob_id記録を同一トランザクションに参加させます。
   UpdateRaceLiveJobの入口は、正式取得側の確定後にafterCommit()で投入します。
4) 専用migration 000010と000011を順に実行します。既存r12.2の表・一意制約も必要です。
   前回011の000010実行済みなら000011を追加します。既存列の型は別途確認します。
   failed_jobs保存先は既存Laravelの設定を確認します。
5) Artisanのコマンド検出を確認します。旧構成はConsole/Kernel.phpのcommands読み込み、
   新構成はbootstrap/app.phpなど、実アプリの登録方式に合わせます。
6) schedule例を既存schedulerに追加します。サーバのschedule:runは毎分です。
   55秒間の巡回と次の毎分起動の間には数秒の空きがあり、無負荷で連続2秒間隔という
   保証ではありません。凍結後の期限起動は別のdelay Jobです。
7) supervisor.example.confのプレースホルダを現物に置換し、専用queueのworkerを起動。
   一般queueだけを見るworkerや、sync接続へ流さないでください。
8) まずAI通信をfakeにした実機試験を実施します。付属tests/bootstrap.phpは
   スタンドアロン試験用で、Laravelアプリへロードしてはいけません。
   同梱のローカル試験はプロジェクトのLaravel PHPUnit試験を代替しません。
   php tests/run.php ／ php tests/audit.php ／ php tests/legacy.php に加え、
   php tests/audit2.php ／ php tests/audit3.php ／ php tests/audit4.php ／ php tests/audit5.php ／
   php tests/audit6.php ／ php tests/audit7.php ／ php tests/audit8.php ／ php tests/audit9.php ／
   php tests/audit10.php ／
   php tests/mutations.php を個別実行できます。
   各実行の末尾のfailed=0相当の表示とエラー出力を確認してください。
9) 非公開検証の対象race_idを確定した後に限り、次の設定で起動します。

   BAGANRIKI_R122_JOBS=true
   BAGANRIKI_R122_PRIVATE=true
   BAGANRIKI_R122_RACE_IDS=確定済みのrace_idをカンマ区切り
   BAGANRIKI_R122_PROMPT=1
   BAGANRIKI_R122_QUEUE=1

   race_idはYYYYMMDD-回-場-日-Rです。実際の12件は推測して埋めていません。
   1〜12件の完全一致のみ許可し、空・13件以上は送信しません。24日の本試験は
   確定した同じ12件を使い、失敗時に別レースへ差し替えないでください。
   既存コードはenvを直接読む箇所があります。config:cache後のCLI／FPMで
   PROMPT／QUEUE／モデル設定が実際に読めるか必ず確認してください。
   旗変更はworkerにも反映するため再起動が必要です。

PRIVATE=trueでは既存r12.2公開読取を404にし、通常AI入口は空のanalysisと受付状態を
返します。Flutterの商品画面との統合は本案に含めません。既存route一覧と別の読取口も
検査し、非公開環境のアクセス制限を設けてください。この旗だけで全ルートの隔離を
保証するものではありません。

6. 実機で通す受入試験

・1stを145秒保留しても2ndがその終了を待たずSENTになる。
・同じrace／roleを複数workerから同時起動して初回1回、訂正最大1回だけ送信される。
・workerを塞いでも同一pending Jobが増殖しない。queue投入とmarker更新の間に
  例外／DB切断を入れても両者が一緒にrollbackする。処理ロック消失時に送信しない。
・RECEIVED直後で処理を止めても、期限前に訂正判断を飛ばして公開しない。
・RESERVED直後、SENT直後、RECEIVED直後でworkerをkillし、再起動結果を区別する。
・SENT時のworker死亡では再送せず、応答期限後にUNKNOWNとして片側集計する。
・AI workerを全部塞いでも期限workerが動く。publish期限を過ぎた保存は拒否する。
・正式取消／締切前倒し、競合revision、同一イベント再配信、全頭取消を確認する。
  取消イベントを同時投入して両方が残ること、2月30日等の不正日付を拒否すること、
  REPLAY世代を変更／公開しないことを確認する。
・REPEATABLE READの実MySQLで、allowSendの事前SELECT後からliveのロック取得までに
  別接続で全頭取消／締切前倒しをcommitし、最新liveで送信を拒否することを確認する。
・訂正本文組立中のstate_revision更新で予約が競合しても、roleは未完了のままにし、
  次の巡回で初回を再送せず訂正を再判断する。訂正送信済みなら再送しない。
・旗off／対象除外後は新規送信しない。ジョブ所有済みレースは同期経路へ戻らない。
・不正な数値型、別馬の根拠、範囲外訂正、未完了応答を従来契約どおり処理する。
  終了理由欠落、total_score=null、不正な入れ子構造も含める。
・null／欠落した採点をcompleteと申告する応答、partialなのに数値のtotal_score、
  採点根拠欄全体の破損を検出し、正常な個別根拠を保ちながら不正な採点だけを外す。
  採点がpartialへ縮退した帯は、不適切なSCORE順位をそのまま採用しない。
・順位方式の訂正は当該帯のrank_basis／rank_reasonへ反映し、他項目は変更しない。
  初回順位を保持した結果、訂正原応答と順位が異なる場合は、その応答の加点証明を出さない。
・文字列の馬番、numだけの行、重複馬番、object型のhorsesを正常候補として採用しない。
  他の正常馬を維持し、正しい訂正で対象馬を救済できることを公開保存まで確認する。
・根拠台帳が空／欠落した入力で、確認できない個別SIGNALからELIGIBLEを成立させない。
・非適格馬のpartialはSCORE判定を変えない。適格馬が採点救済でpartialになれば
  EVIDENCEを必要とする。隔離済みの不正な資格行にも順位方式を変更させない。
・受領後に締切が前倒しされた場合、新しい応答期限の直前／同時刻／直後を確認する。
  遅着した訂正が期限内の初回を上書きしない。received_at不明も選出に使わない。
・H1〜V5それぞれで不存在／他馬の出典を入れ、その項目だけnullになることを確認する。
  合計不正・別項目の範囲外点数・採点欄破損を重ねても正常な個別SIGNALを残す。
・一方の訂正行が採点不正でも、他馬の正しい修正を反映し、不正行は初回を維持する。
  正常な行の範囲外変更は引き続き訂正全体を拒否する。
・訂正で欠落／重複／不正となった馬を初回から残すとき、混在後のpartialを無視して
  SCORE順位を採用しない。保持する初回順位も再検査し、公開結果まで照合する。
・公開保存の各段階へDBエラーを入れ、intent／publication／最新公開ポインタが
  一緒にrollbackすること、再実行で同じ公開IDとなることを実MySQLでも確認する。
・送信ゲートのロック待ちで時刻を進め、待ちの前の時刻で送信権が出ないことを確認する。
・実MySQLに0.12345678901234567890123456789を保存して同じ数値として復元する。
・不正な予定時刻／取得元更新時刻を持つ監査行を採用せず、正常な別バッチを採用する。
  不採用バッチに取消があっても凍結集合へ混入させない。予定時刻の小数秒ゼロの表記違いは
  同時刻として扱い、1マイクロ秒ずれた予定は拒否する。
・live_closeが非nullなのに読めない場合、凍結時の期限へ戻して予約・送信・公開しない。
  既存の正常な公開履歴や監査本文は保持する。DB列型とSQLモードも照合する。
・PAIR／適用COHORTの正常SIGNALを残し、他馬向け／RACEだけ／CONTEXTだけは資格にしない。
・出馬表の宣言頭数、馬番型、矛盾する重複、採用バッチの取消後の実オッズを検査する。
  num_horses列の実際の意味と、取消後に出馬表行を削除していないことも確認する。
・統計5表の原値と時刻を照合し、時刻不正行だけを外して正常な別行を保持する。
  派生式の既存の丸め仕様と、原値の桁保持を分けて確認する。
・NOT NULL／外部キー制約違反等を重複枠と取り違えず、実例外が記録されることを確認する。
・12レースの実時刻でqueue滞留を測る。Supervisor例の台数は性能保証値ではない。
  最大同時進行レース数、初回＋訂正の占有時間、CPU／RAMに合わせて調整する。
・実APIではモデルID、usage、stop理由、完全応答、入力・出力枠と費用を確認する。
  初回24回／訂正込み最大48回。未送信・失敗があれば24回に届かない場合もある。

call_metricsには実モデル／response ID／request ID／usage／stop理由／経過msを保存します。
原応答を先に処理し、その後でメタデータを記録します。elapsed_msはその時点までの
経過時間であり、原応答保存などを含み得るため純粋なネットワーク遅延ではありません。
通信例外でJSON応答がない場合やメタデータ保存失敗は全項目を取得できません。
メタデータ保存失敗で正常なAI本文まで破棄しないようにし、エラーをログに残します。
検証結果本体の保存失敗は例外にし、roleを正常完了にしません。

7. 停止と差し戻し

停止は旗offの反映、worker再起動／停止、未完ジャーナルの確認をセットで行います。
所有記録やSENT行を削除して送信回数をリセットしないでください。
migrationのdownは自動削除を拒否します。削除が必要な場合は、対象run終了・全worker停止・
退避を確認した個別のmigrationで対応します。旧同期の商品へ戻す操作と、検証で送信済みの
同一レースを再送する操作を分けて確認してください。

8. 参照（2026-10-21確認、採用バージョンの資料も照合してください）

Laravel Queue: 接続・after_commit・timeout/retry_after・Supervisor
https://laravel.com/docs/12.x/queues
MySQL 8.4: 接続に属するGET_LOCK／RELEASE_LOCK
https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html
MySQL 8.4: JSON型と内部数値表現
https://dev.mysql.com/doc/refman/8.4/en/json.html
MySQL 8.4: 通常読取とロック読取の差
https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html
https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html

本案は設計と組込み候補の提示です。実機受入を通る前の本番稼働保証ではありません。
