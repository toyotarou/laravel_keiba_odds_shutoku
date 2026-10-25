今回（再精査10版）の実行結果

audit10_tests.txt                追加139項目: 139成功／0失敗
audit9_tests.txt                 再精査9版の111項目: 111成功／0失敗
audit8_tests.txt                 再精査8版の100項目: 100成功／0失敗
unit_tests.txt                   既存41項目: 41成功／0失敗
audit_tests.txt                  再精査1版の29項目: 29成功／0失敗
legacy_selftests.txt             Controller自己試験: 662成功／0失敗
audit2_tests.txt                 再精査2版の6項目: 6成功／0失敗
audit3_tests.txt                 再精査3版の28項目: 28成功／0失敗
audit4_tests.txt                 再精査4版の26項目: 26成功／0失敗
audit5_tests.txt                 再精査5版の42項目: 42成功／0失敗
audit6_tests.txt                 再精査6版の40項目: 40成功／0失敗
audit7_tests.txt                 再精査7版の67項目: 67成功／0失敗
audit10_mutations.txt            型置換: 1,053成功／0失敗（異常終了しないことの試験）
php_lint.txt                     今回同梱PHP35ファイル: 構文エラーなし
patch_integrity.txt              全105定数、変更メソッド、11基準の差分適用と完全一致
全検査項目一覧.txt               機能試験1,291件の実行項目と結果

機能試験合計は1,291成功／0失敗です。型置換1,053件は別集計です。
再精査9版の既存試験PHPとController自己試験はバイト単位で同一です。
再精査10版の追加試験はtests/audit10.phpです。

問題が修正前に存在したことを示す再現記録

audit10_baseline_reproduction.txt 今回追加139項目を再精査9版で実行: 56成功／83失敗
audit9_baseline_reproduction.txt 前回追加111項目を再精査8版で実行: 34成功／77失敗（履歴）
audit8_baseline_reproduction.txt 前回追加100項目を再精査7版で実行: 52成功／48失敗（履歴）
audit7_baseline_reproduction.txt 前回追加67項目を再精査6版で実行: 33成功／34失敗（履歴）
audit6_baseline_reproduction.txt 前回追加40項目を再精査5版で実行: 25成功／15失敗（履歴）
audit5_baseline_reproduction.txt 前回追加42項目を再精査4版で実行: 8成功／34失敗（履歴）
audit4_baseline_reproduction.txt 前回追加26項目を再精査3版で実行: 6成功／20失敗（履歴）
audit3_baseline_reproduction.txt 前回追加28項目を再精査2版で実行: 15成功／13失敗（履歴）
audit2_baseline_reproduction.txt 前回追加6項目を再精査1版で実行: 2成功／4失敗（履歴）
baseline011_reproduction.txt     再精査1版の29項目を初回011で実行: 6成功／23失敗（履歴）
audit2_mutations*.txt            再精査2回目時点の型置換結果（履歴）
audit3_mutations.txt             再精査3回目時点の型置換結果（履歴）
audit4_mutations.txt             再精査4回目時点の型置換結果（履歴）
audit5_mutations.txt             再精査5回目時点の型置換結果（履歴）
audit6_mutations.txt             再精査6回目時点の型置換結果（履歴）
audit7_mutations.txt             再精査7回目時点の型置換結果（履歴）
audit8_mutations.txt             再精査8回目時点の型置換結果（履歴）
audit9_mutations.txt             再精査9回目時点の型置換結果（履歴）

baselineのFAILは旧版の問題の再現であり、今回版の未修正テスト失敗を意味しません。
試験はスタンドアロンPHP 8.3.33 WASMで行い、実Laravel／MySQL／外部AIは使用していません。
