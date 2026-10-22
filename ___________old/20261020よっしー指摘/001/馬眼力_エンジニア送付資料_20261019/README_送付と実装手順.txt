馬眼力 r12.2 プロンプト・入出力定義の整合版
対象リリース：baganriki-v041-r12-2-20261020
変更計画書v1.2、基本設計書v1.0、詳細設計書v1.0に、両AIの完全なsystem本文と入出力Schemaをそろえた一式です。
先ほどの設計資料3点だけのZIPを、この一式へ差し替えてください。

使用するファイル
・prompts/prompt_1st.txt、prompt_2nd.txt：それぞれ単独で完全なsystem本文。共通本文の追加連結は不要。
・prompts/common_prompt.txt：両role共通部分の管理用。単独では役割指示がないため送信しない。
・schemas/input_schema.json、output_schema.json、source_registry_schema.json：JSON Schema Draft 2020-12。
・config/r122_contract.json：版、配分、取得猶予、学習段階等の指定値。
・contracts/server_contract_r12.2.txt：今回のLaravel向け契約全文。Schemaで表せない意味検証も必須。
・documents：変更計画書v1.2、基本・詳細設計書v1.0の原本。
・validation：合成試験入力と期待値、正常／異常例、実行結果、本文差分。
・日本語名の改訂プロンプト.txt：両AI本文と実装契約を一つにした閲覧・引継ぎ用。

変更と維持
①X6はB案。N≧7でnullを維持し、X6だけ不明でS0=1ならMIXED、3ならVOLATILEの暫定配分。正式modeはUNKNOWN。
②6分前＋30秒、他の予想入力スロット＋120秒。情報上限は発走5分30秒前。結果や後続スロットを混ぜない。
③人気帯別配分、個別SIGNAL1系統での資格保持、穴枠の人気側補充禁止、全頭独立評価、採点、局所回復を維持。
④過去走・能力・利用可能な類似統計の個別評価は維持。X6暫定化を全類似情報の廃止と取り違えない。
⑤断層学習はCOLLECT_ONLY、購入推奨falseを維持。

実装時の必須事項
旧本文への追記ではなく両role全文を置換し、Schema・Laravelの判定／時刻検査・版・API／Flutterを同時に更新する。
旧版snapshot／応答は旧版のまま保持。進行中データの新版への付替えはしない。
旧Python実装を新版として同梱していない。Laravel本体の移植・実DB／Queue／実AI／Flutter接続は開発担当者の受入対象。
本資料作成に使用した検証環境は本番の追加実行言語要件ではない。合成JSONの期待値をLaravel側試験へ取り込む。
試験合格の範囲はvalidationの検証結果に限定する。本番稼働や予測精度向上の確認済みという意味ではない。
