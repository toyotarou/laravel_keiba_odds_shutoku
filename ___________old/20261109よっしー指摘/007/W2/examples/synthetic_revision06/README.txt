本文06 / SOURCE_TABLE_V2 の合成送信例

実装のprepareSendから生成し、元の凍結入力への厳密復元、送信記録とメッセージの一致を検査しました。
実AIに送ったデータや実予想の検証結果ではありません。
各roleの *_frozen_input.json が凍結正本、*_user.json が送信本文、*_system.txt が本文とV2付録、*_send_record.json が検証用の送信記録です。
元の304件の出典名を保持。各user本文158,621→29,423バイト、systemは1st 23,717バイト・2nd 23,962バイト。
この例は索引がすべて復元可能な場合です。追加の名前付き索引があればその索引全体を明示して送るため、量はこの例と異なります。
指数表記や小数末尾の表記が変わっても、数値・JSON型が同じかを厳密比較します。料金・トークン・速度は未測定です。
再生成にはvalidation/export_r1523_example.phpと、verify_release.reference_from_submissionで固定参照原本から抽出したbootstrapを使用します。
