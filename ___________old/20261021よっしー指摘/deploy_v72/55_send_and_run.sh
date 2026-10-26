#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v72：10月21日008（なる）のご指摘。本物の不具合4件を直しました
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-METASPLIT  3章（最優先）救済した馬の正常な訂正が拒否される
#   救済の明細（r122_salvage）を<b>馬の行の中に</b>足していました。
#   これは内部の監査項目で、AIの業務項目ではありません。
#   ところがこの行がそのまま acceptCorrection の比較対象になり、
#     初回 = 配列 ／ 正しい訂正 = 無い
#   で「範囲外を変えている」と判定され、<b>正しい訂正が FIX_REJECTED</b>に
#   なっていました。「採点の救済」と「全頭訂正」を別々に試していたので
#   気づけませんでした。なるの追跡どおりです。
#   【いま】馬の行には入れず、別の入れ物（salvage_detail）に出します。
#   あわせて、訂正が内部の監査項目を返してきたら断ります
#   （AIに内部項目を書かせる解決はしません）。
#   試験 R-58〜R-75。
#
# @ANCHOR-20261021-R122-BANDSCOPE  3章（後半）別の帯の順位が守られていない
#   acceptCorrection は<b>馬の行しか比べていません</b>。band_ranks のような
#   top の項目は比べていないので、「別の馬を直す訂正」が、初回では正常
#   だった帯の並びを黙って変えても通り、その並びが使われていました。
#   【いま】訂正で直してよい帯（初回の repair_scope.rank_bands）だけを
#   訂正の並びで上書きできます。それ以外は初回の並びを保持し、
#   訂正が変えようとしていたことを記録に残します。
#   試験 P-58〜P-67。
#
# @ANCHOR-20261021-R122-PERCAND  4章 取消の採用単位
#   commit済み・時刻適合の候補<b>全部</b>の取消を合算した1つの集合を、
#   各バッチの6分前全頭検査に使っていました。「採用され得る」は
#   「全頭検査を通った」ではありません。
#   なるの例（出馬表1・2・3／Aは3番の単勝も取消も無い／Bは3番の正式取消）
#   では、Bの取消でAが「全頭そろった」として採用できていました。
#   【いま】合算しません。全頭検査は<b>その候補自身の取消</b>だけで行います。
#   試験 N-30〜N-41（なるの例をそのまま本体 pick へ通します）。
#
# @ANCHOR-20261021-R122-NUMTYPE / UNESCAPE  6章 厳密JSON
#   (1) 丸まる数値を文字列に置き替えたので、JSON上の<b>型が消えて</b>いました。
#       {"a":1.99…} と {"a":"1.99…"} が同じ値になり、再保存で数値が
#       文字列に化けます。
#       【いま】<b>どの経路がJSON上の数値だったか</b>を控えます。
#       reserialize() がその印を見て、数値の位置は引用符を付けずに
#       書き戻します。AIが最初から引用符付きで返した文字列は印に
#       載らないので、勝手に数値へ変えることはありません。
#   (2) \uXXXX を<b>復号せずに</b>キーを比べていたので、
#       {"a":1,"\u0061":2} が重複キーの拒否を<b>すり抜け</b>ていました。
#       【いま】復号してから比べます（代理対も組み立てます）。
#   試験 R122-J05（22項目。値・型・再保存・重複キーを明細で出します）。
#
# @ANCHOR-20261021-R122-SENDFIRST  5章 初回を送る者が誰もいなかった
#   r122AfterCached は凍結と公開判定を呼ぶだけ、巡回も初回を送らない設計
#   でした。画面からの呼び出しがキャッシュで終わる限り、そのレースは
#   <b>永久に送られません。</b>
#   【いま】r122SendFirst を作りました。固定入力から
#     予約 → 送信許可 → 送信 → 受領 → 検証・訂正・統合・公開
#   まで進めます。巡回とキャッシュ後の後始末の両方から呼びます。
#   枠は一意なので、画面側と同時に動いても二重には送りません。
#   試験 E-53〜E-68。
#
# @ANCHOR-20261021-R122-WATCHEND  5章 見張りが窓を10秒空けていた
#   終了条件が「今＋間隔 >= 終わり」だったので、55秒・5秒間隔でも
#   50秒で終わり、次の毎分起動まで約10秒空いていました。
#   【いま】終わりまで回します。最後の1回は残り時間だけ眠ります。
#
# @ANCHOR-20261021-R122-VERIFIEDAT  8章 算出時刻の意味
#   「もう一度集計して同じ値を確認した」は<b>誤った説明</b>でした。
#   実際は end_date より後の新規データが無いことを確かめているだけです。
#   さらに、新規結果が無いだけで computed_at を毎回上書きすると、
#   <b>いつ実際に算出したかという証拠が消えます。</b>
#   【いま】意味の違う3つを分けます。
#     computed_at  実際に値を算出した時刻。もう上書きしません
#     verified_at  その値がこの時刻に使えることを確かめた時刻
#     end_date     集計期間の終わり。触りません
#   列 verified_at を足します（NULL可）。
# ══════════════════════════════════════════════════════════════════════
# v71 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v71 ＝ v70 ＋ ログの文言1か所（v70 は640件すべて合格しています）
# ══════════════════════════════════════════════════════════════════════
# v70 の実行（2026-10-21 06:21）で確かめられたこと
#   ・受入試験 640件すべて合格（不合格0）
#   ・実経路 52件・キュー 103件・応答検証 57件・公開 57件・読取 29件
#     本体直通 29件・固定入力 20件・OPI 18件：すべて不合格0
#   ・算出時刻が入りました（popavg／fukuavg とも 2026-10-21 06:21）
#     値は変えていません（期間の終わり 2026-10-18／平均の最大 9.2 のまま）
#   ・<b>OPI が初めて載りました</b>（出典95件・registry 162件）。
#     これまで一度も通っていなかった「載る側」の経路（M-10・M-11・M-14）が、
#     SQLから取り直した値と一致しました（0.9／1.08／0.91）
#   ・元値の指紋は試験の前後で完全一致（4表とも変わっていません）
#   ・007のG の「変更前」の現物が見つかりました
#     （2026-08-14 の版。算出時刻を1か所も持っていません）
#
# @ANCHOR-20261021-R122-OPILINE  直したのはこの1行だけです
#   OPI が載ったのに「（OPIが載らないぶん、件数は少なくなります）」と
#   書き続けていました。算出時刻が入る前の文面のままでした。
#   読む人に<b>逆の意味</b>で伝わるので、載らなかったときだけ出します。
#   この行は、なるへ出すログ（08_OPI.txt）に入ります。
# ══════════════════════════════════════════════════════════════════════
# v70 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v70 ＝ v69 ＋ 空回しの打ち切り（v69 は未実行なので、これ1本で済みます）
# ══════════════════════════════════════════════════════════════════════
# v69 で入れるつもりだった中身はそのまま全部入っています。
# 足したのは次の1点だけです。
#
# @ANCHOR-20261020-R122-IDLECAP  旗が下りているときの空回しは10秒で打ち切る
#   --r122-sweep --r122-sweep-watch=55 を毎分の cron に入れると、
#   枠の段（BAGANRIKI_R122_QUEUE）が 0 の間は
#   <b>55秒眠るだけのPHPが、1分のうち55秒ずっと立ち続けます。</b>
#   仕事は何もしません。ただ常駐するだけです。
#   刻みを見せるのが目的なので、2回まわれば足ります。
#   旗が下りているときは10秒で打ち切るようにしました。
#   旗が shadow か 1 のときは本当に拾いに行くので、打ち切りません。
#   → これで、先に cron へ入れてしまっても常駐しません。
# ══════════════════════════════════════════════════════════════════════
# v69 の中身（本物の不具合1件。ここが今回の主目的です）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v69：v68 は全件合格。そのログから見つかった<b>本物の不具合1件</b>を直します
# ══════════════════════════════════════════════════════════════════════
# AiController と SummaryAiAnalysisCompensate は v68 と同じものです。
# 直したのは集計コマンド2本だけです。
#
# @ANCHOR-20261020-R122-COMPUTEDAT  算出時刻が永久に入らない作りでした
#   【v68 のログが示していたこと】
#     ・popavg / fukuavg の算出時刻（computed_at）は、列を19:20に足したのに
#       21:10 の集計のあとも 0行 のままでした。
#     ・DBを見ると、この2表が最後に書かれたのは<b>10月18日</b>でした
#       （popavg 10/18 21:10・fukuavg 10/18 22:00）。
#       10月19日・20日の集計は、どちらも1行も書いていません。
#   【原因】両コマンドの【ブロック4】です。
#     「新しいレース結果が無い日は、DBに1行も書かずに終わる」作りでした。
#     平日は必ずこちらを通るので、<b>算出時刻が永久に入りません。</b>
#     r12.2 は「情報上限より前に利用可能だった証拠」の無い集計を使わないので、
#     このままだと OPI は永久に載りません。
#     私が前に書いた「今夜の集計が1回まわれば入る／翌日から載る」は
#     <b>間違いでした。</b>入るのは次に新しいレース結果が増えた日です。
#   【いま】値は1つも変えません。<b>算出時刻だけ</b>を入れ直します。
#     意味は「この時刻にもう一度集計して、同じ値であることを確認した」です。
#     集計期間の終わり（end_date）は触りません。なので
#       ・期間は 2026-10-18 まで
#       ・算出は（この実行の時刻）
#     の2つが別々に読めます。どちらも事実です。
#     平均の値は元データだけで決まるので、同じ元データならいつ計算しても
#     同じ値になります。
#
#   【なぜ今夜やるか】このまま10月24日を迎えると、OPI は
#     10月26日より前には載りません。内訳は次のとおりです。
#       popavg  の cron は `10 21 * * 0-5`（日〜金。<b>土曜は動きません</b>）
#       fukuavg の cron は `0 22 * * *`（毎日）
#     10/24(土)のレース結果は20:30に入り、22:00に fukuavg だけが更新。
#     popavg が更新されるのは 10/25(日) 21:10 です。
#     OPI は popavg と fukuavg の<b>両方</b>に算出時刻が必要なので、
#     10/24・10/25 の検証中はどちらも載りません。
#     今夜この1行を入れておけば、10/24 の時点で両方に算出時刻があり、
#     OPI が載った状態で検証できます。
#
#   この手順の中で、deploy のあとに2本を1回だけ手で回します。
#   書き込むのは computed_at の18行×2表だけです（値は変えません）。
#
# @ANCHOR-20261020-R122-FPPAIR2  007のG 変更前の現物を正しく探します
#   v68 では差分が0行になりました。比較先に選んだ退避フォルダが、
#   すでに新しい版を持っていたためです（この2本は v55 より前に
#   置き換えていました）。<b>算出時刻を持たない版</b>を退避の中から
#   探して、それと比べます。
# ══════════════════════════════════════════════════════════════════════
# v68 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v68：v67 で落ちた8件を直しました（原因は2つだけです）
# ══════════════════════════════════════════════════════════════════════
# 本番のコードは1行も変えていません。AiController は v66 と同じものです。
# 直したのは<b>新しい試験の作り方</b>と<b>期待値1つ</b>です。
#
# 【原因1】偽の送信口を、場面ごとに登録し直していました（6件）
#   Laravel の Http::fake() は呼ぶたびに<b>積み重なります</b>。
#   置き換えではありません。登録した順に全部呼ばれて、
#   <b>最初に応答を返したものが使われます。</b>
#   そのため2回目以降に登録した偽応答は一度も使われず、
#     ・1回目に登録した「正しい応答（1st用）」がずっと返り続け、
#     ・片側失敗（HTTP 500）も通信不能も再現できていませんでした。
#   v67 の出力がそれをそのまま示しています。
#     E-23 自動で再送していない … 得=4（登録した偽応答の数と同じ）
#     E-16 明確な失敗として扱う … 得='OK'（500 ではなく1回目の応答が返った）
#     E-50 2nd も全16頭そろう  … 得=15（2nd の枠に 1st の本文が入った）
#   【いま】登録は1回だけにして、振る舞いは変数で切り替えます。
#   これで直るのは E-16・E-17・E-18・E-23・E-24・E-50・E-51 です。
#
# 【原因2】期待値が違っていました（1件）
#   E-13 訂正の2回目を断る理由を 'ALREADY' と書いていましたが、
#   本番は 'SLOT_TAKEN'（訂正の枠はすでにあります）を返します。
#   断っている動きは正しいので、期待値のほうを直しました。
#
# なお、落ちた8件はどれも<b>本番の動きの誤りではありません。</b>
# 本番側で確認できたこと（v67 で合格した44件）はそのまま有効です。
#   ・初回→検証→訂正→解決→公開が本番の関数で通る（1st・2nd とも）
#   ・訂正は最大1回。送信口も増えない
#   ・通信できないときは結果不明にして再送しない
#   ・応答期限／公開期限で止まる。空振りの枠も作らない
#   ・プロセス停止後は送信済みを結果不明にし、初回は再送せず公開まで進む
#   ・上限前の呼出しは固定しない（TOO_EARLY）／上限後は断らない
# ══════════════════════════════════════════════════════════════════════
# v67 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v67：007の最小セット E と G に手を付けました
# ══════════════════════════════════════════════════════════════════════
# v66 は640件すべて合格しました。残っていたのは、
# 「実AIを呼ばずに確認できるのに、まだ確認していないもの」です。
#
# @ANCHOR-20261020-R122-WIRETEST  007のE（実起動経路）
#   なるの指示どおり、<b>送信口を試験用に置き換えて接続を通します。</b>
#     「まず送信口を試験用に置き換えて接続を通し、
#       その後で実AIの時間と出力品質を測ってください。」（007の9章）
#   Laravel の Http::fake() は HTTP クライアントの下に入るので、
#   <b>本番のコードを1行も変えずに</b>
#     ・AnthropicService::sendWithRetry（1st）
#     ・Http::post('https://api.deepseek.com/...')（2nd）
#   の両方が偽の応答を受け取ります。通るのは本番の関数です。
#     r122AfterReceive → r122InspectResponse → r122SendFix
#     → r122CallAi → r122AcceptedHorses → r122PublishWhenReady
#   実AIへの通信は0回です（偽の送信口が何回呼ばれたかも数えます）。
#
#   新しい試験 --r122-wire-test（E-01〜E-52）で通すもの
#     ① 初回→検証→訂正→解決→公開（1st・Anthropic へ送る）
#     ①-2 2nd 側も同じ（DeepSeek へ送る）
#     ② 訂正は最大1回（2回目は送らない・送信口も増えない）
#     ③ 片側失敗（HTTP 500）→ FAILED。初回の正常部分15頭は残り、公開は進む
#     ④ 通信できない → CONNECTION・結果不明（UNKNOWN）・再送しない
#     ⑤ 途中で切れた応答 → 保存はするが選出には初回を使う
#     ⑥ 応答期限まで時間が無ければ訂正を送らない・空振りの枠も作らない
#     ⑦ 公開期限を過ぎたら公開しない
#     ⑧ プロセス停止後の復旧（送信済み→結果不明・初回は再送しない・公開は進む）
#     ⑨ 巡回は初回の送信を持たない（関数の中身で確認）
#     ⑩ 上限前の呼出しは固定しない（TOO_EARLY）／上限後は断らない
#
# @ANCHOR-20261020-R122-FPPAIR  007のG（元値の指紋の対・スケジュール・現物）
#   ・試験の<b>前と後</b>で元値の指紋を取り、同じであることを出します
#     （= この一連の試験は元のデータを1バイトも変えていない、の証拠）
#   ・crontab と Laravel のスケジュール設定を、そのまま結果に残します
#   ・変更した集計コマンド2本の現物と差分を結果に入れます
#   ※ --r122-fingerprint は v65 で値なしでも効くように直してあります
# ══════════════════════════════════════════════════════════════════════
# v66 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v66：007の7（数値の扱い）を直しました。これ1点だけです。
# ══════════════════════════════════════════════════════════════════════
# v65 で 624件中 623件が合格し、残った1件はこれでした。
#
#   R122-J02 / 有効桁128はちょうど通る    …… 不合格
#
# 【何を間違えたか】
#   v65 で入れた「PHPで正確に持てない数値は受け取らない」という検査が、
#   <b>契約で許されている長い数値まで拒否していました。</b>
#   契約9章は有効桁128桁まで許しています。128桁の数値は倍精度では
#   必ず丸まるので、v65 の検査では必ず落ちます。
#   つまり、正しい応答を落とす側の誤りでした。
#
# 【どう直したか】@ANCHOR-20261020-R122-NUMEXACT（方針を差し替え）
#   走査では落としません。<b>PHPの数値では丸まるものだけ、
#   原表現の十進数文字列のまま持ちます。</b>
#   007のご指摘7は「許容された有限数を INF や丸めた別値へ変えないこと」
#   「数値トークンの原表現と型を保持すること」でしたので、
#   <b>拒否ではなく保持</b>が筋です。入力側で同じことを
#   @ANCHOR-20261020-R122-DIGITS2 でやったのと同じ扱いに揃えました。
#
#     {"a":1.99999999999999999999}  → 'a' => '1.99999999999999999999'（文字列）
#     {"a":12345678901234567890}    → 'a' => '12345678901234567890'（文字列）
#     {"a":2e308}                   → 'a' => '2000…0'（309桁。INFにしません）
#     {"a":11}  {"a":2.5}           → 11 / 2.5（そのまま数値。変えません）
#     129桁                          → これまでどおり JSON_TOO_MANY_DIGITS
#
#   丸まった数が「数として」使われないことは、使う側で見ます。
#   採点は整数と決まっているので、文字列で来れば SCORES_RANGE で落ちます。
#   置き替えるのは数値トークンの位置だけなので、他は1バイトも動きません。
#   試験 R122-J04（19項目）に差し替え、R122-J02 の128桁の試験はそのままです。
#
# 【もう1点】@ANCHOR-20261020-R122-SWEEPIDLE
#   v65 の「期限での起動」は、枠の段が off のため一度も回っていませんでした
#   （出力が通常の巡回と同じでした）。off のときでも
#   <b>刻みだけは実際に回して、実測の間隔を出す</b>ようにしました。
#   「応答期限から公開期限までの30秒を取りこぼさない」が、
#   off の環境でも数字で見えます。DBには触りません。
# ══════════════════════════════════════════════════════════════════════
# v65 までの内容（そのまま入っています）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# 10月20日006への是正（本物の不具合8件）
# ══════════════════════════════════════════════════════════════════════
# ══════════════════════════════════════════════════════════════════════
# v65：007の残りのうち、実AIなしで直せるものを全部入れました
# @ANCHOR-20261020-R122-RESUME     007の6-1 上限到達後にやり直せるようにする
#   ・TOO_EARLY で断ったときは空回答をキャッシュしません
#     （残すと次のアクセスがキャッシュ返却で終わり、やり直せません）
#   ・キャッシュを返すときも、固定入力が無ければ応答を返したあとに
#     1回だけ作り直します（画面は遅くなりません）。公開の起動もします
#   ・試験 X-31〜X-36
# @ANCHOR-20261020-R122-SWEEPWATCH 007の6-2 応答期限から公開期限の30秒を取りこぼさない
#   ・--r122-sweep に --r122-sweep-watch=55 --r122-sweep-interval=5 を足しました
#   ・1分おきのcronでも、巡回の間隔は最大5秒になります
#   ・コマンドの最後に cron の1行を出します
# @ANCHOR-20261020-R122-NUMEXACT   007の7 応答側の数値を丸めて通さない
#   ・数値トークンごとに原表現とPHPで持てる値を突き合わせます
#   ・1.99999999999999999999 や 20桁整数、2e308 は受け取りません
#   ・「別値へ変えない」ので、丸めた値を黙って通すことはしません
#   ・試験 R122-J03（14項目）
# ══════════════════════════════════════════════════════════════════════
# v64 で直したのは、v63 で落ちた4件の<b>期待値の側</b>です
#   R-18  対象外の馬を「初回と同じまま再掲する」訂正を拒否する動きを
#         合格にしていました。再掲は通すのが契約なので、試験のほうを
#         「本当に中身を変える訂正」に差し替えました。
#   P-39  この資料の正常出力はもともと採点が全部 null です。
#         「null の数」では見られないので、「応答にあった採点と
#         1項目も変わっていないこと」を見る形にしました。
#   P-46・P-47  予備根拠順位（evidence_band_ranks）が生きているときは、
#         明示縮退の<b>前に</b>そちらへ切り替わります（設計書10.2の順）。
#         期待値を EVIDENCE_RANK に直し、予備根拠順位も壊した場合に
#         明示縮退まで落ちることを P-53〜P-57 で別に見ます。
#   あわせて、訂正の中身が空のときは row_valid を要求しないようにしました
#   （時刻だけを見る呼び方のためです）。
# ══════════════════════════════════════════════════════════════════════
# 10月20日007への是正（なるの最小セットA〜F。実AIは呼びません）
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-ONEBUILD  007の2（最優先）送信用と保存用の二重組み立て
#   送信用と保存用で入力を2回組み立てていたので、派生の計算時刻が変わり
#   snapshot_hash が一致せず、自分で自分の送信を拒否していました。
#   組み立ては1回だけにし、保存した本文をそのまま送ります（設計書7.2）。
#   作り直す側は r122AssembleFresh に分けました（送信には使いません）。
#   新しい通し試験 --r122-onebuild-test（Y-01〜Y-20）。
# @ANCHOR-20261020-R122-BANDKEEP   007の3-1 needs_fix ひとつで全帯の順位を捨てない
#   帯ごとに見て、指摘の無い帯はそのまま使い、使えない帯だけを
#   旧順位 → 予備根拠順位 → 明示縮退（独立根拠群数→馬番）の順に落とします。
#   P-34〜P-52。前回の P-34〜P-38 は「全帯を捨てる」動きを合格にしていたので
#   期待値のほうを直しました。
# @ANCHOR-20261020-R122-SCOREKEY   007の3-2 採点を全部 null にしない
#   おかしい項目だけ null にし、合計は残った項目から計算し直します。
#   observed_sum だけの誤りなら採点は1項目も消えません。
# @ANCHOR-20261020-R122-RESTATE    007の4 全頭返す正常な訂正を拒否しない
#   未変更の正常行の再掲と、対象外の書き換えを分けます（R122-V05）。
# @ANCHOR-20261020-R122-RESCUESCOPE 007の4 救済馬も horses の対象か明示検査
# @ANCHOR-20261020-R122-ROWVALIDREQ 007の4 row_valid を渡さない呼び方を断る
# @ANCHOR-20261020-R122-COMMITFIRST 007の5 未commitバッチの取消を活動集合に混ぜない
# @ANCHOR-20261020-R122-PAYSET      007の5 原ペイロードの重複・未知馬・取消併存を検出
# @ANCHOR-20261020-R122-WIRE2       007の6-4 設定検査を2ndの送信入口にも入れる
# @ANCHOR-20261020-R122-FPFLAG      --r122-fingerprint が値なしで素通りしていた
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261020-R122-USEDORDER 2-3 未検証順位の試験を足す
#   なるが出した2つの例を候補生成まで通します（P-34〜P-40）。
#     ①1頭の採点集計だけを壊し、同時に対象外のB帯の並びを入れ替える
#     ②A帯に馬番999を混ぜる（順位集合不正）
#   コードは v57 までに直っていましたが、試験が無かったので足しました。
# @ANCHOR-20261020-R122-DIGITS2 6-1 桁を落とす最後の2か所を直す
#   ① PHPの数値で正確に持てない値を source_registry から<b>落としていた</b>のを、
#      元の十進数の文字列のまま登録する形へ。Schemaのvalueは string も可。
#      信号の計算は文字列をそのまま多倍長のたすき掛けで比べるので桁が落ちません。
#   ② numToDecimalString が number_format(12桁) で13桁目から下を落としていたのを、
#      json_encode（元へ戻せる最短表記）＋指数表記の自前展開へ。
#   ③ scale()（小数4桁制限）が判定経路から消えていることを試験で見張る。
#   試験 R122-E08（12項目）。
# v60 の変更点（v59の出力で見つけた注記の食い違いを1か所だけ直しました）
#   取消の表の脚注に「999=最初の取得」と書いていましたが、この比較は
#   999 を読んでいません（読むのは 21・18・15・12・9・6・3・確定 の8つ）。
#   表のcancel_slotsを直接数えた値と並びが違う理由も脚注に書きました。
# @ANCHOR-20261020-R122-33CANCEL 6-3 取消の説明をコードと実データに揃える
#   ・京都9R7番の取消は確定(-999)の行にしかありません。6分前は実オッズ39.8です。
#   ・よって「6分前だから残す」ではなく「期限までに正式取消が無いから残る」です。
#   ・取消があった3件（10/17東京5R5番・10/18新潟5R3番・10/18京都9R7番）の
#     cancel_slots・6分前の生値・分類・頭数を実データで出します。
#   ・この表には取得時刻の列が無いことも明記します（時刻では示せません）。
# @ANCHOR-20261020-R122-REALTIME 「列が無い」と「まだ値が無い」を区別（新規）
#   v58 の出力で computed_at の列を足したのに「列なし」と出ていたのを直します。
# @ANCHOR-20261020-R122-WIRE    6-4 実送信の後へ検証・訂正・公開を接続する（新規）
#   ・1st …応答を返したあと（app()->terminating()）に 検証→訂正→解決→公開
#   ・2nd …その場で 検証→訂正→解決→公開（両roleが終端になるので公開まで）
#   ・公開は両roleが終端になってから。応答期限を過ぎたら待たない
#   ・PROMPT=1 で QUEUE=0 という組み合わせを設定で作れないようにした
#   ・--r122-sweep で、止まったレースの訂正判定と公開だけやり直せる
#   ・呼出経路そのものを Reflection で見る試験を入れた（X-24〜X-30）
# @ANCHOR-20261020-R122-33ELIG   6-2 33レース比較の資格を「1系統以上」だけにする
# @ANCHOR-20261020-R122-33FILL   6-2 適格馬が足りないときの枠移動を本体の規則で行う
# @ANCHOR-20261020-R122-33LIMIT  6-2 この比較で言えること・言えないことを明記する
# @ANCHOR-20261020-R122-REALTIME  集計2表に computed_at を足します（DBを変えます）
#   ・列は NULL 可。<b>既存行には時刻を入れません</b>（無い時刻は作らないため）。
#   ・今夜の集計（21:10 / 22:00）が1回まわれば本物の時刻が入り、
#     翌日からOPIが載ります。10月24日には間に合います。
#   ・列を足すだけなので、いまの商品の動きは変わりません。
# @ANCHOR-20261020-R122-CANCELSRC   3-1 取消は採用バッチだけから決める
# @ANCHOR-20261020-R122-CANDCANCEL  3-2 不採用バッチの取消を全頭確認に効かせない
# @ANCHOR-20261020-R122-EARLY       3-3 情報上限に達する前の凍結を断る
# @ANCHOR-20261020-R122-REALTIME    4-1 算出時刻の無い集計は使わない
# @ANCHOR-20261020-R122-DERIVEDAT   4-2 派生の時刻を親より過去へ戻さない
# @ANCHOR-20261020-R122-FPFAIL      6-5 指紋の欠け・失敗を合格にしない
# @ANCHOR-20261020-R122-COMPLETE   2-1 未完了応答を選出・順位点から外す
# @ANCHOR-20261020-R122-USEDORDER  2-3 未検証の順位を統合に使わない
# @ANCHOR-20261020-R122-ELIG       2-2 NO_EVIDENCE/UNVERIFIED を統合に入れない
# @ANCHOR-20261020-R122-FROZENK    3-4 取消で凍結K・帯内順位rを変えない
# @ANCHOR-20261020-R122-MICRO      4-3 追加材料の日時をマイクロ秒まで保つ
# @ANCHOR-20261020-R122-DEC        6-1 凍結順位の小数4桁制限を撤去
# @ANCHOR-20261020-R122-CORRKEY    5-1 訂正の保護を正規キー(score_status)で見る
# @ANCHOR-20261020-R122-SCOPEFIELD 5-2 summary_horses も範囲内で訂正できる
# @ANCHOR-20261020-R122-ROWVALID   5-3 row_valid=false の救済を適用しない
# @ANCHOR-20261020-R122-SALVAGE    5-2 採点欄だけの誤りで正常根拠を落とさない
# @ANCHOR-20261020-R122-SCOREEV  採点と出典の結びつき（005の5章末・10-1）
#   採点の算数（満点・合計・observed_sum/cap・total_score）は前から見ていました。
#   見ていなかったのは「その点数が何を根拠に付いたか」です。
#   点が付いているのに出典が空／採点していないのに理由が空／出典が実在しない
#   ・この馬のものでない、を見ます。
# @ANCHOR-20261020-R122-REFCHECK  参照根拠の意味検証（005の5章末）
#   ※ v51 で R-34・R-35 が × だったのは配線漏れです。ref_stats を外へ
#     渡していませんでした（判定そのものは R-26〜R-33 で通っていました）。
#   これまで各馬の事実出典hashは「記録」するだけで、その参照IDが
#   固定入力に実在するかを見ていませんでした。AIが参照IDを作り話で
#   書いても気づきません。実在・所有馬・自分のSIGNALの3つを見ます。
#   ①②に汚れがあっても、自分のSIGNALが1系統あれば行は落としません。
# @ANCHOR-20261020-R122-STRICTJSON  応答JSONの厳密読込（005のご指摘7-3）
#   extract は通常の json_decode でした。重複キーは後勝ちで黙って片方が消え、
#   128桁超は float へ丸まり、1e400 は INF になります。
#   走査（BaganrikiJson::strict）と値の組み立て（json_decode）を分けました。
#   送る前の自分の本文も同じ検査を通します（selfCheck）。
# @ANCHOR-20261020-R122-NATIVE / ANCHORMATCH / ROLLOVER
#   005の指摘のうち4件が直っていなかったので直しました。
#     ・合成729件＋境界4件を本体へ直接通す（これまでCommand側の検証用関数でした）
#     ・取消で目標数Cが変わる例（16→15・14→13・9→8・全頭取消）の本体試験
#     ・日付・時刻の繰上がり検査（2026-02-30 が3月2日として通っていました）
#     ・申告された予定時刻・情報上限が正本の発走時刻と合うかの検査
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"            "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
# @ANCHOR-20261020-R122-REALTIME  集計側に算出時刻を記録する2本
cp "$HERE/code/SummaryPopularityRankAverage.php"     "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp "$HERE/code/SummaryFukuPopularityRankAverage.php" "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" \
    "$MACAPP/app/Console/Commands/SummaryPopularityRankAverage.php" \
    "$MACAPP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
echo "  期待 AiController 36a7ff17beac619ce4a2d5ffa50de91e"
echo "  期待 Command      98c1ffb4c401e7d53167bef4655051ae"
echo "  期待 集計(単勝)   7d863d582bbe12de74f30a08e301b3c2"
echo "  期待 集計(複勝)   74089f5bc77fb81cecf6fc031088cb13"
ssh "$SRV" 'mkdir -p ~/r122_v72/code'
scp -q "$HERE"/code/*.php "$SRV:~/r122_v72/code/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v72/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v72_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v72_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v72_$STAMP/"
# @ANCHOR-20261020-R122-REALTIME  集計2本も置換前を退避します（切戻し用）
cp -p "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"     "$BK/before_v72_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php" "$BK/before_v72_$STAMP/"
cp ~/r122_v72/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v72/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp ~/r122_v72/code/SummaryPopularityRankAverage.php     "$APP/app/Console/Commands/SummaryPopularityRankAverage.php"
cp ~/r122_v72/code/SummaryFukuPopularityRankAverage.php "$APP/app/Console/Commands/SummaryFukuPopularityRankAverage.php"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
cd "$APP"
echo ""; echo "══ 置き換え ══"
wc -l app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Http/Controllers/Api/AiController.php app/Console/Commands/SummaryAiAnalysisCompensate.php \
       app/Console/Commands/SummaryPopularityRankAverage.php \
       app/Console/Commands/SummaryFukuPopularityRankAverage.php
php -l app/Http/Controllers/Api/AiController.php
php -l app/Console/Commands/SummaryAiAnalysisCompensate.php
php -l app/Console/Commands/SummaryPopularityRankAverage.php
php -l app/Console/Commands/SummaryFukuPopularityRankAverage.php
php artisan config:clear >/dev/null 2>&1 || true

echo ""; echo "══ 集計2表に算出時刻の列を足す（NULL可・既存行は触りません）══"
# @ANCHOR-20261020-R122-REALTIME
#   無い時刻は作らないので、既存行は NULL のままにします。
#   今夜の集計（21:10 と 22:00）が1回まわれば、そこから本物の時刻が入ります。
php artisan tinker --execute='
  foreach ([
    "t_horse_odds_finder_popularity_rank_average",
    "t_horse_odds_finder_fuku_popularity_rank_average",
  ] as $t) {
    if (!\Schema::hasTable($t)) { echo "  － $t がありません" . PHP_EOL; continue; }
    if (\Schema::hasColumn($t, "computed_at")) {
      echo "  ○ $t には computed_at がすでにあります" . PHP_EOL;
    } else {
      \DB::statement("ALTER TABLE `$t` ADD COLUMN `computed_at` DATETIME(6) NULL COMMENT \"集計した実時刻（r12.2 時刻A案）\"");
      echo "  ＋ $t に computed_at を足しました（既存行は NULL）" . PHP_EOL;
    }
    // @ANCHOR-20261021-R122-VERIFIEDAT  10月21日008のご指摘8
    //   computed_at（実際に算出した時刻）を毎晩上書きすると証拠が消えます。
    //   「この時刻に使えることを確かめた」は別の列にします。
    if (\Schema::hasColumn($t, "verified_at")) {
      echo "  ○ $t には verified_at がすでにあります" . PHP_EOL;
    } else {
      \DB::statement("ALTER TABLE `$t` ADD COLUMN `verified_at` DATETIME(6) NULL COMMENT \"この時刻にこの値が使えることを確かめた（r12.2）\"");
      echo "  ＋ $t に verified_at を足しました（既存行は NULL）" . PHP_EOL;
    }
    $n = \DB::table($t)->whereNotNull("computed_at")->count();
    echo "     算出時刻の入っている行 : $n 行" . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$"

# @ANCHOR-20261021-R122-FPORDER  008のご指摘8
#   前回は、指紋の前測定（06:24:41）が集計の更新（06:24:38/39）より
#   <b>後</b>でした。それでは「集計変更の前後」を示したことになりません。
#   指紋を先に取ってから、集計を回します。
echo ""; echo "══ 元値の指紋（試験の前）══"
# @ANCHOR-20261020-R122-FPPAIR  007のG
#   このあとの試験で元のデータが変わっていないことを示すため、
#   先に指紋を取ります（読むだけ・書き込み0件）。
php artisan keiba:ai-analysis-compensate --r122-fingerprint 2>&1 \
  | tee "$OUT/20_指紋_前.txt"
FP_BEFORE="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
if [ -n "${FP_BEFORE:-}" ]; then cp "$FP_BEFORE" "$OUT/20_指紋_前.json"; fi

echo ""; echo "══ 集計2本を1回だけ回して、算出時刻を入れます（値は変えません）══"
# @ANCHOR-20261020-R122-COMPUTEDAT
#   書き込むのは computed_at の18行×2表だけです。
#   odds_average・count・start_date・end_date は変わりません。
# @ANCHOR-20261021-R122-VERIFIEDAT  008のご指摘8
#   指紋の4表にこの2表は入っていません。集計値が変わっていないことは、
#   この2表の平均・件数・期間を<b>前後で</b>並べて示します。
echo "-- 入れる前 --"
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    $r = \DB::table($t)->selectRaw("COUNT(*) c, SUM(computed_at IS NOT NULL) h,
            MAX(computed_at) mx, MIN(start_date) sd, MAX(end_date) ed,
            SUM(`count`) sc, MD5(GROUP_CONCAT(CONCAT(popularity_rank,\":\",odds_average,\":\",`count`) ORDER BY popularity_rank SEPARATOR \"|\")) sig")->first();
    echo sprintf("  %-8s %2d行 算出時刻あり %s 行／期間 %s〜%s／件数合計 %s",
        $n, $r->c, $r->h, substr((string) $r->sd, 0, 10),
        substr((string) $r->ed, 0, 10), (string) $r->sc) . PHP_EOL;
    echo sprintf("           平均と件数の指紋 %s", (string) $r->sig) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$"

php artisan keiba:summaryPopularityRankAverage     2>&1 | tail -6
php artisan keiba:summaryFukuPopularityRankAverage 2>&1 | tail -6

echo "-- 入れた後 --"
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    $r = \DB::table($t)->selectRaw("COUNT(*) c, SUM(computed_at IS NOT NULL) h,
            SUM(verified_at IS NOT NULL) v, MAX(computed_at) mx, MAX(verified_at) vx,
            MIN(start_date) sd, MAX(end_date) ed, SUM(`count`) sc,
            MD5(GROUP_CONCAT(CONCAT(popularity_rank,\":\",odds_average,\":\",`count`) ORDER BY popularity_rank SEPARATOR \"|\")) sig")->first();
    echo sprintf("  %-8s %2d行 算出時刻 %s 行（最新 %s）／確認時刻 %s 行（最新 %s）",
        $n, $r->c, $r->h, (string) $r->mx, $r->v, (string) $r->vx) . PHP_EOL;
    echo sprintf("           期間 %s〜%s／件数合計 %s（どれも変えていません）",
        substr((string) $r->sd, 0, 10), substr((string) $r->ed, 0, 10),
        (string) $r->sc) . PHP_EOL;
    echo sprintf("           平均と件数の指紋 %s", (string) $r->sig) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$"

echo ""; echo "══ キューと永続予約＋送信口＋旗の段＋実送信の後への接続（67＋30件）══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"

echo ""; echo "══ 実送信の後への接続だけ抜き出し（006の6-4。X-01〜X-30）══"
grep -E "X-[0-9]|⑬" "$OUT/01_キュー.txt" | tee "$OUT/12_接続.txt"

echo ""; echo "══ 固定入力をそのまま送る（007の2。Y-01〜Y-20）══"
php artisan keiba:ai-analysis-compensate --r122-onebuild-test --date=2026-10-18 2>&1 \
  | tee "$OUT/14_固定入力.txt"

echo ""; echo "══ 送信口を偽応答に差し替えて実経路を通す（007のE。E-01〜E-52）══"
# 実AIへの通信は0回です。偽の送信口が何回呼ばれたかも数えます。
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/16_実経路.txt"

echo ""; echo "══ 止まったレースのやり直し（初回の送信はしません）══"
php artisan keiba:ai-analysis-compensate --r122-sweep 2>&1 | tee "$OUT/13_やり直し.txt"

echo ""; echo "══ 期限での起動（1分cronでも最大5秒おきに回せること）══"
# 試験としては短く10秒だけ回します。本番の cron は 55秒／5秒です。
php artisan keiba:ai-analysis-compensate --r122-sweep \
  --r122-sweep-watch=10 --r122-sweep-interval=5 2>&1 | tee "$OUT/15_期限起動.txt"

echo ""; echo "══ 応答検証と局所回復（正常14本＋局所回復＋訂正＋全頭再掲）══"
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt"

echo ""; echo "══ 統合・公開・intent・revision（40＋17件）══"
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt"

echo ""; echo "══ 読取API ══"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt"

echo ""; echo "══ 本番の経路で作った入力（Schema＋coverage＋過去走）══"
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 \
  | tee "$OUT/07_本番入力.txt"

echo ""; echo "══ 合成729件＋境界4件を本体へ直接通す／取消でCが変わる4例／繰上がり／正本一致 ══"
php artisan keiba:ai-analysis-compensate --r122-native-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/09_本体直通.txt"

echo ""; echo "══ 検証用関数の側（これまでどおり。本体の試験とは分けて出します）══"
php artisan keiba:ai-analysis-compensate --r122-cases="$HOME/r122_v23/mat/validation" 2>&1 \
  | grep -E "mode_cases|acquisition_boundary|合格|失敗" | head -6

echo ""; echo "══ 回収率の理由が「当日を含む集計」に変わったか ══"
grep -E "recovery_statistics|similar_statistics" "$OUT/07_本番入力.txt" | head -6

echo ""; echo "══ OPI と推定確定オッズの通し試験（載らない側と載る側の両方）══"
php artisan keiba:ai-analysis-compensate --r122-market-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/08_OPI.txt"

echo ""; echo "══ 33レースの候補比較（資格と枠移動を契約どおりに直しました）══"
php artisan keiba:ai-analysis-compensate --mode-check --r122-33races \
  --date=2026-10-17,2026-10-18 2>&1 \
  | tee "$OUT/11_33レース.txt"
# ※ ログには全文を残します（grepで行が欠けると、なるが生の表と突き合わせた
#   ときに「説明が足りない」と見えるためです）。端末には要約だけ出します。
grep -E "読んだレース|計算不能|確定|対象|候補が|書き出しました|取消があったレース|cancelPre|cancelPost|cancelLate|言えること|言えないこと" \
  "$OUT/11_33レース.txt"

echo ""; echo "══ 投票締切の正本（発走時刻から出した期限の並び）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "R122-CL0" \
  | tee "$OUT/06_締切.txt"

echo ""; echo "══ 凍結が壊れていないこと（監査行→凍結保存→読戻し）══"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" | grep -E "○|×|ロールバック後|通りました|ありません"

echo ""; echo "══ JSONの厳密読込・数値の正確な保持・桁落ちの是正 ══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | grep -E "R122-J02|R122-J03|R122-J04|R122-J05|R122-E08" \
  | tee "$OUT/10_厳密JSONと桁.txt" | head -60

echo ""; echo "══ 受入試験（壊れていないこと）══"
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 | grep -E "判定件数|合格 |不合格"

echo ""; echo "══ 元値の指紋（試験の後）＝ 前と突き合わせます ══"
# @ANCHOR-20261020-R122-FPPAIR  007のG
#   コマンド自身に突き合わせの機能があるので、それを使います。
#   表ごとに「行数・行の指紋・主キー集合の指紋」を比べて ○／× を出します。
if [ -n "${FP_BEFORE:-}" ]; then
  php artisan keiba:ai-analysis-compensate --r122-fingerprint="$FP_BEFORE" 2>&1 \
    | tee "$OUT/21_指紋_後_突き合わせ.txt"
  FP_AFTER="$(ls -t storage/logs/r122_fingerprint_*.json 2>/dev/null | head -1)"
  if [ -n "${FP_AFTER:-}" ]; then cp "$FP_AFTER" "$OUT/21_指紋_後.json"; fi
else
  echo "  試験の前の指紋が取れていないので、突き合わせはできません"
fi

echo ""; echo "══ 実際のスケジュール設定（007のG）══"
echo "-- crontab -l --"
crontab -l 2>&1 | tee "$OUT/22_crontab.txt"
echo ""
echo "-- /etc/crontab と /etc/cron.d --"
{ cat /etc/crontab 2>/dev/null; echo "--- /etc/cron.d ---";
  ls -1 /etc/cron.d 2>/dev/null; for F in /etc/cron.d/*; do
    [ -f "$F" ] && { echo "=== $F ==="; cat "$F"; }; done; } 2>&1 \
  | tee "$OUT/22_crontab_system.txt" || true
echo ""
echo "-- Laravel のスケジュール --"
php artisan schedule:list 2>&1 | tee "$OUT/23_schedule.txt" || true

echo ""; echo "══ 変更した集計コマンド2本の現物と差分（007のG）══"
mkdir -p "$OUT/code" "$OUT/diff"
cp app/Console/Commands/SummaryPopularityRankAverage.php     "$OUT/code/"
cp app/Console/Commands/SummaryFukuPopularityRankAverage.php "$OUT/code/"
# @ANCHOR-20261020-R122-FPPAIR2  算出時刻を持たない版（＝変更前）を探します
#   v68 では、比較先に選んだ退避がすでに新しい版だったので差分0行でした。
#   computed_at を含まない写しを、退避フォルダ全体から探します。
for F in SummaryPopularityRankAverage.php SummaryFukuPopularityRankAverage.php; do
  ORIG=""
  for C in $(find "$BK" ~/r122_v* -name "$F" 2>/dev/null | sort); do
    if ! grep -q "computed_at" "$C" 2>/dev/null; then ORIG="$C"; break; fi
  done
  if [ -n "$ORIG" ]; then
    cp "$ORIG" "$OUT/code/変更前_$F"
    diff -u "$ORIG" "app/Console/Commands/$F" > "$OUT/diff/$F.diff" || true
    echo "  変更前の写し : $ORIG"
    echo "  差分 $F : $(wc -l < "$OUT/diff/$F.diff") 行"
  else
    echo "  変更前の写し（算出時刻を持たない版）が退避の中に見つかりません: $F"
    echo "    → 退避にある写しはすべて算出時刻を持っています。"
    echo "      この2本は v55 より前に置き換えたためです。" \
         > "$OUT/diff/$F.見つからない理由.txt"
  fi
done
md5sum "$OUT/code/"*.php

echo ""; echo "══ いまの .env と、そこから決まる段 ══"
grep -E "^BAGANRIKI_" "$APP/.env" 2>/dev/null || echo "  （BAGANRIKI_ の行はまだありません）"
echo ""
php artisan tinker --execute='
  // @ANCHOR-20261020-R122-SHADOW  これらのクラスは AiController.php の中にあるので、
  //   先に親ファイルを読ませないと autoload が見つけられません。
  class_exists(\App\Http\Controllers\Api\AiController::class);
  $P = \App\Http\Controllers\Api\BaganrikiPrompt::mode();
  $Q = \App\Http\Controllers\Api\BaganrikiWorker::mode();
  $D = var_export(\App\Http\Controllers\Api\BaganrikiTime::salesCloseDerive(), true);
  $O = \App\Http\Controllers\Api\BaganrikiTime::salesCloseOffsetSec();
  $M = \App\Http\Controllers\Api\AiController::r122PastRunsMax();
  echo "  本文の段            $P" . PHP_EOL;
  echo "  キューの段          $Q" . PHP_EOL;
  echo "  締切を導出するか    $D" . PHP_EOL;
  echo "  締切の前倒し(秒)    $O" . PHP_EOL;
  echo "  過去走の上限(走)    $M" . PHP_EOL;
' 2>&1 | grep -vE "^Psy|^$"

echo ""; echo "結果: $OUT"

# ── 提出用にログとCSVを1つにまとめます ────────────────────────────
#   33レースのCSVは storage/logs に出るので、結果フォルダへ入れてから固めます。
CSV="$(ls -t /var/www/horse_odds_finder/storage/logs/r122_33races_*.csv 2>/dev/null | head -1)"
if [ -n "$CSV" ]; then cp "$CSV" "$OUT/05_33レース候補比較.csv"; fi
tar czf ~/r122_v72_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo "まとめました: ~/r122_v72_logs.tgz"
REMOTE

# ── ログを手元へ持ってきます（提出物の組み上げに使います）────────────
scp -q "$SRV:~/r122_v72_logs.tgz" "$HERE/" \
  && echo "" \
  && echo "ログを取ってきました: $HERE/r122_v72_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v72_logs.tgz があります）"
