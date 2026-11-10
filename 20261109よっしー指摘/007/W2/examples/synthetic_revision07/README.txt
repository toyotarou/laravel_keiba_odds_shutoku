本文07 / SOURCE_TABLE_V2 の合成送信例

実装のprepareSendで生成し、送信記録と送信本文の一致を検査した合成データです。実AIへの送信や実予想の結果ではありません。
*_frozen_input.json：凍結正本、*_user.json：送信本文、*_system.txt：指示文とV2付録、*_send_record.json：送信照合記録。
元の304件の参照名を維持。通常例のuser本文は両roleとも158,621→29,423バイト。
連番の参照名や指数表記などの追加条件はvalidation/r1524_probe.phpがその場で生成して検証します。
バイト数はトークン数・費用・速度とは異なります。実AIの読解や料金は未測定です。
