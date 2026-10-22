馬眼力 r12.1 配分修正版・組込用実装パッケージ

7頭選出の混戦（C7 MIXED）を、人気側4頭＋穴側3頭から、人気側3頭＋穴側4頭へ修正しました。
基本配分はA/B/D=3/3/1。Aは凍結6分前単勝1～6番人気、Bは7～10番人気、Dは11番人気以下です。
構造調整のない場合、1stの公開配分は3/3/1、2ndは最大5頭の中で1/3/1。両AIとも全N頭を独立評価します。
C7 VOLATILEは3/3/1、C6 MIXED/VOLATILEは3/2/1を維持します。その他の基本配分も維持します。

変更したもの
config.json・reference.pyのC7 MIXED、両AIのsystem本文、版識別、入出力Schemaのprompt_version定数、導入手順・実装契約・仕様書・試験を更新しました。
prompt_versionとimplementation_versionはbaganriki-v041-r12-1-quota-20261017。
schema_version=baganriki-v041-r8-1、marker、hash方式、PHP互換クラス名BaganrikiR12Bridgeは維持します。
旧r12の凍結snapshotや原応答を新版へ付け替えず、新規レースから一式で切り替えてください。

穴枠の扱い
個別の確認済みSIGNALが1系統あれば候補資格を維持します。低人気、旧資格、回収率、低配当、部分欠損だけで一律除外しません。
評価後の穴枠不足はB/D間で補い、Aの人気上位へ移しません。根拠が不足する場合は不足を明示します。
実在頭数が足りない場合の構造調整と、評価後の根拠不足は区別します。

実行
  python3 -m venv .venv
  .venv/bin/python -m pip install -r requirements-test.lock.txt
  .venv/bin/python verify.py
  .venv/bin/python cli.py < demo_replay_request.json
最後は架空データの再生です。実AI呼出し・本番保存を行いません。
Python3.10以上、PHP8.0以上、proc_open、単一ホスト上の共有永続SQLiteが必要です。
PHPはINSTALL.txtに従い、同梱7本を導入先の実PHPで実行してください。

同梱物
prompt_1st.txt / prompt_2nd.txt：各AIへ送るsystem本文。完全版.txt全体をAIへ送らないでください。
config.json / reference.py / engine.py / adapter.py：入力、配分、検証、採点、順位、統合、取消。
store.py / journal.py：保存、公開履歴、独立したAI呼出し枠。
php/BaganrikiR12Bridge.php / RoleWorker.php / PublicView.php等：PHP接続と公開表示。
server_contract.txt / INSTALL.txt：実装契約と導入手順。
verify.py：既存試験に配分回帰試験12件を加えた一括検証。
test_quota_revision.py / quota_revision_results.json：C7混戦、両AI、最終統合、不足、取消、旧版拒否の確認。
IMPLEMENTATION_REPORT.txt：今回の変更と実施した検証範囲。
runtime_contract.template.json：本番受入記録。未確認欄はfalseのままです。
audit/：旧r12以前の検証記録と今回の差分。旧配分の記載は歴史資料で、現行設定ではありません。
SHA256SUMS.txt：配布ファイルの照合用。

検証と未実施範囲
今回の結果はverification_results.jsonとIMPLEMENTATION_REPORT.txtを参照してください。
php_bridge_results.jsonは旧r12のPHP-WASMによる7系列の記録です。r12.1での再実行結果ではありません。
学習はCOLLECT_ONLY、未検証X6はUNKNOWN、購入推奨はfalseを維持します。
本番配置・実DB・実AI・本番キュー・既存画面への接続、および高配当捕捉率・回収率の改善確認は未実施です。
