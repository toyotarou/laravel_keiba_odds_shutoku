#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v84 ＝ v83 ＋ e2e試験の直し（AiController は v83 と同じものです）
# ══════════════════════════════════════════════════════════════════════
# v83 の結果（2026-10-21 13:42）
#   受入試験 662・キュー 112・型保持 92・Job構成 72・公開 67・応答検証 76
#   本体直通 41・読取 29・固定入力 20・OPI 18・本番入力 9：すべて不合格0
#   落ちたのは<b>新しく足した e2e 試験1本だけ</b>（G-03・G-04）です。
#
# @ANCHOR-20261021-R122-E2EDATE  発走時刻だけ動かしても足りませんでした
#   e2e 試験は、実在のレースの発走時刻を「いまから10分後」へ寄せて
#   走らせる作りにしていました。ところが情報上限・入力凍結・応答期限・
#   公開期限は<b>「日付＋発走時刻」から出します。</b>
#   対象レースの日付は 2026-10-18 のままなので、期限も3日前になり、
#   監査行が自己申告した予定時刻と正本から出した予定時刻が合わず、
#   バッチが1件も採用されず SOURCE_REGISTRY_EMPTY になりました。
#   試験の作り方の誤りです。本番の動きではありません。
#
#   【いま】出馬表とオッズを<b>値を1つも変えずに</b>
#   「今日・いまから10分後・場98」へ写してから凍結します。
#   場98は実在しないので本番の行と混ざりません。
#   ロールバックするので、写した行も残りません
#   （残っていないことも出します）。
#   写しに失敗したらそこで分かるように、件数の判定（G-00a〜G-00c）を
#   先に置きました。
#
#   AiController.php は v83 と<b>同じもの</b>です（md5 も同じ）。
#   直したのは試験の側（Command）だけです。
#
# ══════════════════════════════════════════════════════════════════════
# ここから下は v83 の内容です（そのまま全部入っています）
# ══════════════════════════════════════════════════════════════════════
# <b>v81・v82・v83 は回さずに、これ1本で済みます。</b>
#
# 入っているもの
#   010の2章  Job構成（4本）              J-01〜J-57
#   010の3章  型保持                      T-01〜T-42／P-01〜P-30
#   010の2-5  12レース限定（全入口）      J-47〜J-52
#   010の7章⑤ 実モデルと使用量の記録      J-53〜J-57
#   010の5章末尾・009の③  実凍結から一続き  G-01〜G-24
#   v80 で落ちた4件（E-11・E-19・E-37・E-52）の直し
#
# ══════════════════════════════════════════════════════════════════════
# 010の2-5・7章⑤  12レース限定と、実モデル・使用量の記録
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-TARGET12
#   ご指摘：
#     「通常アクセス・キャッシュ後・巡回・訂正の全入口で対象race_idを
#       確認し、送信許可の直前でも確認します。1レースの最大4回という
#       制約だけでは、対象レース総数12を保証できません。」
#   判定は1つの関数（r122TargetCheck）です。<b>書き写していません。</b>
#   見る場所は5か所
#     r122SendFirst（初回の送信許可の直前）
#     r122SendFix（訂正の送信許可の直前）
#     r122SweepPending（巡回）
#     r122AfterCached（キャッシュ後）
#     R122RunRoleJob（待ち行列から出たとき。積まれてから時間が経つため）
#   <b>BAGANRIKI_R122_TARGETS が空のときは1レースも送りません。</b>
#   「指定が無ければ全部送る」の逆にしています。入れ忘れた日に
#   全レースへ送るほうが、危ないからです。
#   試験 J-47〜J-52（関数の行範囲を取って、呼び出しの有無を見ます）。
#
# @ANCHOR-20261021-R122-USAGE
#   ご指摘：
#     「usageと実モデルの記録、初回24回／最大48回の費用を示すこと。」
#     「2nd自身のトークナイザ、実モデル、finish_reason、usage、
#       JSON検証結果を揃えてください。」
#   <b>こちらで決めた名前ではなく、応答に書かれていたモデル名</b>と
#   使用量をそのまま残します。Anthropic は input_tokens／output_tokens、
#   DeepSeek は prompt_tokens／completion_tokens で、数え方の名前が
#   違うので、両方を読めるようにしています。
#   新しい表は作りません。baganriki_audit_events に kind=R122_USAGE で
#   1行入れます。試験 J-53〜J-57。
#
# ══════════════════════════════════════════════════════════════════════
# 010の5章末尾・009の③  実凍結から一続きの通し試験
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-E2E
#   ご指摘：
#     「通し試験の$mkはsnapshotを直接INSERTし、締切を既定3,600秒後に
#       置く形のままです。出馬表・監査行からの実凍結、キャッシュ入口、
#       実際の期限幅、Jobの実起動を一続きにした試験は別途必要です。」
#   そのとおりでした。
#   【いま】--r122-e2e-test で次を一続きに通します。
#     ① 実在のレース（出馬表・オッズのあるもの）を選びます
#     ② <b>そのレースの発走時刻を「いまから10分後」へ寄せます</b>
#        （実データのまま、時刻だけ動かします。ロールバックします）
#     ③ 監査行を、寄せた発走時刻から出した予定時刻で作ります
#     ④ <b>締切は渡しません。</b>発走時刻から導出させます
#     ⑤ r122ResumeFreeze（キャッシュ後の入口が呼ぶもの）で実凍結します
#     ⑥ 期限の幅を保存された値で1つずつ確かめます
#        −360 情報上限／−330 入力凍結／−150 応答期限
#        −120 公開期限／−60 投票締切
#     ⑦ 送信口を偽応答に差し替えて、初回→検証→訂正→採否→公開まで
#        r122SendFirst（本番の関数）で通します
#   時刻を寄せているのは、過去の開催では残り時間が足りず
#   「応答期限まで○秒しかないので送りません」で正しく止まるためです。
#   <b>期限の幅は1つも決め打ちしていません。</b>
#   発走時刻はロールバックで元に戻ります（戻っていることも出します）。
#   試験 G-01〜G-24。
#
# ══════════════════════════════════════════════════════════════════════
# ここから下は v82 の内容です（そのまま全部入っています）
# ══════════════════════════════════════════════════════════════════════
#
# ══════════════════════════════════════════════════════════════════════
# 010の第3章  型保持を、診断→訂正比較→保存→読戻しまで通しました
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-TYPEMAP ほか
#
# ■ なぜ「全部を値オブジェクトで包む」形にしなかったか
#   設計A（値に型を持たせる）には賛成です。ただし<b>すべての値を
#   包む</b>と、既存の業務の判定（is_int・array_sum・foreach・比較）が
#   全部落ちます。ご指摘にもあるとおり「全部包むがis_intはそのまま」では
#   正しい整数まで落ちます。
#   【採った形】値はこれまでどおりPHPの素の値で持ち、<b>型は経路を
#   キーにした地図（map）で並べて持ちます。</b>
#     map['num'][経路]  = ['raw','decimal','exact','form']
#     map['kind'][経路] = 'obj' | 'arr'
#   業務の判定は素の値でそのまま動き、型が必要な場所（比較・保存・
#   読戻し）だけが地図を見ます。値オブジェクト（BaganrikiDecimal）は
#   1つの値を持ち回す入れ物として残します。地図はその全体版です。
#   <b>同じ値の別インスタンス問題は起きません</b>（値を包まないので）。
#
# ■ 3-1 専用シリアライザ
#   ご指摘：「現行 r122SaveValidation も通常のjson_encodeです。
#            クラスを追加するだけでは009の問題は解消しません。」
#   そのとおりでした。
#   BaganrikiJson::encode($値, $地図) を作り、r122SaveValidation を
#   これに替えました。数値だった経路は<b>原トークンのまま引用符なし</b>で
#   書きます。JSON_NUMERIC_CHECK も float への変換も使っていません。
#
#   <b>監査5,000件制限との分離も直しました。</b>
#   丸まる数値の置き替え（inexact）には上限が無いのに、型の地図を
#   監査と同じ 5,000 で切っていました。5,001個目から先は「文字列に
#   置き替わったのに数値だった印が無い」状態になり、再保存で数値が
#   文字列へ化けます。3-5の「5,001個目の長い数値」がこれです。
#     numbers（監査の一覧）      5,000 件のまま
#     number_paths（型の地図）   200,000 件
#     kind_paths（obj/arr の地図） 200,000 件
#
# ■ 3-2 共通の比較関数（Bを選択）
#   BaganrikiJson::same($a,$地図A,$b,$地図B) が
#     ・JSON上の型（数値／文字列／真偽／null／obj／arr）
#     ・数値は正確な十進値と、表記（int／dec／exp）
#     ・obj はキーの集合（<b>順序は無視</b>）
#     ・arr は長さと<b>順序</b>
#   を見ます。sameHorseRow と、訂正対象外フィールドの比較を
#   これに替えました（地図が無いときは従来どおり === です）。
#
#   PHPの === では見逃していたもの
#     2e0 と 2.0        どちらも float(2.0) なので<b>同じ</b>に見えます
#     {} と []          どちらも空配列なので<b>同じ</b>に見えます
#     1.999…（数値）と "1.999…"（文字列）
#                       どちらもPHP文字列なので<b>同じ</b>に見えます
#   試験では、同じ材料で「地図なし」「地図あり」を並べて出します。
#
#   末尾の0（1.50 と 1.5）は<b>同じ</b>と見ます。十進値も表記（dec）も
#   同じで、値も型も変わっていないためです。保存では原トークンを
#   そのまま書くので、1.50 は 1.50 のまま残ります。
#
# ■ 3-3 整数検査と保存後の読戻し
#   整数は PHP の int のまま残るので is_int がそのまま効きます。
#   1.0 と 1e0 は float なので、整数必須の検査が落とせます。
#   読戻しも専用パーサに統一しました（r122NormalPart）。
#   <b>通常の json_decode は使いません。</b>
#   r122NormalRoundTrip で「保存 → 読戻し」が一致することも見ます。
#
# ■ 3-4 オブジェクト／配列の種類
#   BaganrikiEmptyObject だけでは {\"0\":\"a\",\"1\":\"b\"} と [\"a\",\"b\"] の
#   区別が失われます。<b>経路ごとに obj／arr を控えます。</b>
#   空かどうかに関係なく、数値キーの非空オブジェクトも区別します。
#   並べ替えには mapMove／mapMerge で地図を追従させます。
#   normal は馬番キーのオブジェクトなので、各馬の枝を
#   [\"horses\",3] から [\"7\"] へ付け替えています。
#
# ■ 試験 --r122-type-test（T-01〜T-42／P-01〜P-30）
#   T群 … 3-5 の6例を読み・書き・比べで通します
#   P群 … <b>本番の関数で</b>診断→訂正比較→保存→読戻しまで通します
#          （r122InspectResponse／r122AcceptedHorses／
#            r122SaveValidation／r122NormalPart を実際に呼びます）
#   実AIは1回も呼びません。全部ロールバックします。
#
# ══════════════════════════════════════════════════════════════════════
# ここから下は v81 の内容です（そのまま全部入っています）
# ══════════════════════════════════════════════════════════════════════
# v80 の結果（2026-10-21 13:01）
#   受入試験 662件・キュー 112件・固定入力 20件・応答検証 76件・公開 67件
#   読取 29件・本番入力 9件・本体直通 41件・OPI 18件：すべて不合格0
#   新しく足した試験も全部通りました
#     X-22b〜X-22i（採否確定の印）・X-23b（期限ぴったり）
#     E-58e-0〜E-58e-4（帯の順位の保持を帯ごとに突き合わせ）
#   落ちたのは実経路の4件（E-11・E-19・E-37・E-52）だけです。
#
# ── v80 で落ちた4件 ────────────────────────────────────────────
# いずれも「WAITING が返った」です。<b>直したとおりに動いた結果</b>で、
# 試験の前提が古くなっていました。
#
# @ANCHOR-20261021-R122-SETTLE  E-11・E-19・E-52
#   これまでは 1st だけ接続（r122AfterReceive）を通し、2nd は枠を
#   RECEIVED にしただけで「両roleが終端」と数えられていました。
#   010の2-4 のとおり RECEIVED は「HTTPが返った」だけなので、
#   いまは 2nd の採否確定を待ちます。<b>待つのが正しい動きです。</b>
#   本番は両roleが r122AfterReceive を通るので、試験もそうしました。
#   あわせて「片方だけでは公開しない」ことを先に確かめる判定
#   （E-11-1・E-11-2・E-19-1・E-52-1）を足しました。
#   これが010の2-4の競合そのものの試験です。
#
# @ANCHOR-20261021-R122-NOTHINGTOADOPT  E-37（本物の直しです）
#   初回が FAILED／UNKNOWN_RESULT で終わった役には、診断も訂正も採否も
#   やることが残っていません。採否確定の印は<b>受領に成功したときだけ</b>
#   付くので、待っても永久に付きません。
#   ここで待たせると、失敗した役のぶんだけ公開が応答期限まで遅れます。
#   公開期限は応答期限の30秒後しかありません。
#   【いま】「採るものが無い」ことを確定として数えます。
#   RECEIVED のときは数えません。それが010の2-4です。
#   試験 E-37-0〜E-37-2。
#
# ══════════════════════════════════════════════════════════════════════
# 010の第2章  Job構成（4本）を実装しました
# ══════════════════════════════════════════════════════════════════════
# @ANCHOR-20261021-R122-JOBBASE ほか
#
# ■ この環境に合わせたこと
#   Laravel 8.83.29 ／ PHP 8.0.30 です。なるの引用は Laravel 12.x の
#   worker 仕様なので、8.x に在るものだけを使いました。
#   readonly（8.1）・enum（8.1）・第一級callable（8.1）は使っていません。
#   Bus::batch と ShouldBeUnique は 8.0 から在りますが、
#   ShouldBeUnique は<b>使いません</b>。鍵が cache driver（この環境では
#   file）に乗るので、本物の見張りとしては弱いためです。
#   二重の見張りは、既にある一意制約（枠と凍結入力）に任せます。
#
# ■ 既定の接続は触りません
#   QUEUE_CONNECTION=sync のままです。アプリの他の場所の動きを
#   変えたくないので、このJobだけが `r122` という専用の接続
#   （driver=database・retry_after=300・after_commit=true）を名指しで
#   使います。config/queue.php に1節足すだけです。
#
# ■ 待ち行列を4本に分けました（010の2-1）
#   「AIを待つworkerと、期限判定・統合を行うworkerを分離する」
#     r122-freeze   入力凍結            枠1
#     r122-ai-1st   1st のAI待ち        枠2
#     r122-ai-2nd   2nd のAI待ち        枠2
#     r122-agg      期限判定・統合・公開・実状態  枠1
#   1st が145秒かかっていても、2nd と期限処理は止まりません。
#
# ■ 秒数の整合（010の2-1 末尾）
#   入力凍結（T−330）から応答期限（T−150）までは <b>180秒</b>です。
#   1本の RunRoleJob に初回と訂正の両方を入れます（分けると待ち行列を
#   もう一度通るので、180秒では間に合いません）。
#     AnthropicService の HTTP 上限   120秒（private const を実測）
#     RunRoleJob の上限               175秒（180秒の窓の内側）
#     worker の --timeout             180秒（Jobの上限以上）
#     接続の retry_after              300秒（workerの上限より十分長い）
#   RunRoleJob は <b>$tries = 1</b>。やり直すと外部APIへ二度送るためです。
#   worker が落ちて同じJobがもう一度動いたときは、r122SendFirst が
#   「その role の初回の枠はすでにあります」で何もせず返ります。
#   これが「worker再実行と外部API再送の区別」です（010の2-5）。
#
# ■ 4本の役割（010の2-2 のとおり）
#   R122FreezeRaceJob      既存の凍結処理を通し、保存成功後に
#                          1st と 2nd を独立に投入。期限での集計も予約
#   R122RunRoleJob         r122SendFirst <b>だけ</b>を呼びます。
#                          後処理（検証→訂正→採否）はその中の
#                          r122AfterReceive が1回ずつ行います。
#                          <b>公開はしません。</b>集計Jobへ渡します
#   R122AggregateRaceJob   AIを待ちません。保存済みの結果で公開判定。
#                          受領時と期限時の2つの起動を持ちます
#   R122UpdateRaceLiveJob  実状態（取消・締切の前倒し）を既存の版管理へ。
#                          AIへは1回も送りません
#
# ■ r122AfterReceive 末尾の直接公開を、集計Jobへ渡す形にしました
#   「roleの処理完了を保存してAggregateRaceJobへ渡す形に変更します」
#   Jobの段が上がっているときだけです。下りているときは今までどおり
#   その場で公開判定まで進みます（いまの本番はこちらです）。
#
# ■ 巡回とキャッシュ後は「積むだけ」にしました（010の2-3）
#   「r122SweepPending：…r122SendFirst／r122SendFixを同期実行しません」
#   「terminating内で長いAIのHTTP応答を待つ形を残しません」
#   Jobの段が上がっているときは、どちらも dispatch で終わります。
#
# ■ 経路の固定（010の2-5）
#   レースの処理開始時に JOB／SYNC を監査へ記録し、<b>上書きしません。</b>
#   旧経路で始まったレースにはJobから触りません。
#   Job失敗を理由に旧経路から再送することもしません。
#
# ■ 対象レースの限定（010の2-5・7章⑤）
#   .env の BAGANRIKI_R122_TARGETS に race_id を読点で並べます。
#   <b>空のときは1レースも送りません。</b>「指定が無ければ全部送る」に
#   していません。逆にすると、設定を入れ忘れた日に全レースへ送ります。
#   見る場所は、初回の送信許可の直前・訂正の直前・巡回・キャッシュ後・
#   待ち行列から出たとき（積まれてから時間が経つため）の5か所です。
#   同じ関数（r122TargetCheck）を全部から呼びます。書き写していません。
#
# ■ 実状態の変更（010の2-2 UpdateRaceLiveJob）
#   r122ApplyLive を作りました。
#     取消       足すだけ。一度取消になった馬は戻しません
#     締切       前倒しだけ。延長は受け付けません。正本より後ろも断ります
#     版         実際に変わったときだけ state_revision を1増やします
#     凍結済み本文・anchor_at・cutoff_at・sales_close_at は触りません
#   AIへは1回も送りません。
#
# ■ 常駐は systemd です（supervisord が入っていないため）
#   なるの推奨は supervisord ですが、このサーバには
#   /etc/supervisord.d がなく、サービスも動いていません。
#   CentOS なので systemd は確実に在ります。テンプレート（@）で
#   待ち行列ごとに枠を立てます。--max-time=3600 で1時間ごとに
#   自分から降りて立て直します（PHPの長時間常駐でメモリが膨らむのを
#   避けます）。
#
# ■ 試験 --r122-job-test（J-01〜J-46）
#   <b>worker は立てません。</b>dispatch で jobs 表に入った行を読み、
#   どのJobがどの待ち行列へどの引数で積まれたかを突き合わせます。
#   Jobは1本も実行しないので、実AIへは1回も送りません。
#   秒数の整合・経路の固定・対象レースの限定・実状態の変更まで見ます。
#   最後に必ずロールバックします（jobs 表への行も戻します）。
#
# ══════════════════════════════════════════════════════════════════════
# この手順でやること
#   ① ファイルを置く（AiController・Command・app/Jobs の5本）
#   ② config/queue.php に r122 の接続を1節足す（既存の節は触りません）
#   ③ jobs／job_batches／failed_jobs を DDL で作る
#      <b>php artisan migrate は使いません。</b>このプロジェクトには
#      database/migrations に baganriki 系の migration が1本も無く、
#      migrate を打つと関係のない未適用の migration まで走るためです。
#      r12.2 の表と同じやり方（DDL）でそろえます
#   ④ systemd のテンプレートを置く（<b>まだ起動しません</b>）
#   ⑤ 試験を回す
#
#   旗は動かしません。BAGANRIKI_R122_JOBS は足しますが <b>0</b> です。
#   worker も起動しません。実AIへの通信は0回です。
#   crontab は読み出しだけで、1文字も変更しません。
#
# 置き換えるファイル
#   AiController.php                 2c6460626989c78bf542db65ef1fb3a8
#   SummaryAiAnalysisCompensate.php  b99fb5776db0f6948d30527748e5340c
# 新しく置くファイル
#   app/Jobs/R122Job.php                  b5427782fe211b55866e779b8d200c72
#   app/Jobs/R122FreezeRaceJob.php        f72f0dc95dc30bfd9a2838afeb698e75
#   app/Jobs/R122RunRoleJob.php           e6c61f1a5d316b5b58c2d887af14ba3e
#   app/Jobs/R122AggregateRaceJob.php     319ce857b8c5b938c52d8adf9c3d8582
#   app/Jobs/R122UpdateRaceLiveJob.php    872b4afb67e64f4438ee726156a37dd7
# ══════════════════════════════════════════════════════════════════════
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRV=centos@49.212.166.123
MACAPP="/Users/toyodahideyuki/Desktop/HIDEYUKI/KEIBA/_____laravel"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$HERE/mac_backup_$STAMP" "$MACAPP/app/Jobs"
cp "$MACAPP/app/Http/Controllers/Api/AiController.php"            "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$HERE/mac_backup_$STAMP/"
cp "$MACAPP/config/queue.php"                                     "$HERE/mac_backup_$STAMP/" 2>/dev/null || true
cp "$HERE/code/AiController.php"                "$MACAPP/app/Http/Controllers/Api/AiController.php"
cp "$HERE/code/SummaryAiAnalysisCompensate.php" "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
cp "$HERE"/jobs/*.php                           "$MACAPP/app/Jobs/"
md5 "$MACAPP/app/Http/Controllers/Api/AiController.php" \
    "$MACAPP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
echo "  期待 AiController 2c6460626989c78bf542db65ef1fb3a8"
echo "  期待 Command      b99fb5776db0f6948d30527748e5340c"
md5 "$MACAPP"/app/Jobs/*.php

ssh "$SRV" 'mkdir -p ~/r122_v84/code ~/r122_v84/jobs ~/r122_v84/systemd'
scp -q "$HERE"/code/*.php    "$SRV:~/r122_v84/code/"
scp -q "$HERE"/jobs/*.php    "$SRV:~/r122_v84/jobs/"
scp -q "$HERE"/systemd/*     "$SRV:~/r122_v84/systemd/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v84/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v84_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v84_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v84_$STAMP/"
cp -p "$APP/config/queue.php"                                     "$BK/before_v84_$STAMP/"
cp ~/r122_v84/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v84/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
mkdir -p "$APP/app/Jobs"
cp ~/r122_v84/jobs/*.php "$APP/app/Jobs/"
chown apache:apache "$APP/app/Http/Controllers/Api/AiController.php" 2>/dev/null || true
chown apache:apache "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" 2>/dev/null || true
chown -R apache:apache "$APP/app/Jobs" 2>/dev/null || true
cd "$APP"

echo ""; echo "══ ① 置き換え ══"
md5sum app/Http/Controllers/Api/AiController.php \
       app/Console/Commands/SummaryAiAnalysisCompensate.php
md5sum app/Jobs/*.php
for F in app/Http/Controllers/Api/AiController.php \
         app/Console/Commands/SummaryAiAnalysisCompensate.php \
         app/Jobs/R122Job.php app/Jobs/R122FreezeRaceJob.php \
         app/Jobs/R122RunRoleJob.php app/Jobs/R122AggregateRaceJob.php \
         app/Jobs/R122UpdateRaceLiveJob.php ; do
  php -l "$F"
done

echo ""; echo "══ ② config/queue.php に r122 の接続を足す（既存の節は触りません）══"
# @ANCHOR-20261021-R122-QUEUECONF
#   既定の 'default' は env('QUEUE_CONNECTION','sync') のままです。
#   足すのは connections の中の1節だけです。
#   二度打ってもよいように、すでに在れば何もしません。
php -r '
$p = "/var/www/horse_odds_finder/config/queue.php";
$s = file_get_contents($p);
if (strpos($s, "@ANCHOR-20261021-R122-QUEUECONF") !== false) {
    echo "  ○ r122 の接続はすでにあります（何もしません）" . PHP_EOL; exit(0);
}
$needle = "        \x27sync\x27 => [\n            \x27driver\x27 => \x27sync\x27,\n        ],\n";
if (substr_count($s, $needle) !== 1) {
    echo "  × sync の節が1つ見つかりません（手で足してください）" . PHP_EOL; exit(1);
}
$add = $needle . "
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-R122-QUEUECONF  r12.2 専用の待ち行列（010の2章）
        // ══════════════════════════════════════════════════════════════
        //   既定の QUEUE_CONNECTION は sync のままです。
        //   この接続は app/Jobs/R122*Job が名指しで使うだけなので、
        //   アプリの他の場所の動きは変わりません。
        //
        //   retry_after は <b>worker の上限より十分長く</b>します。
        //     RunRoleJob の上限  175秒
        //     worker の --timeout 180秒
        //     retry_after        300秒
        //   短いと、まだ動いているJobを別のworkerが拾って二重に動きます。
        //
        //   after_commit は true です。トランザクションの中で dispatch
        //   しても、commit まで実際には積まれません。未commitの
        //   snapshot を worker が先に読むことを防ぎます（010の2-2）。
        \x27r122\x27 => [
            \x27driver\x27 => \x27database\x27,
            \x27table\x27 => \x27jobs\x27,
            \x27queue\x27 => \x27r122-agg\x27,
            \x27retry_after\x27 => 300,
            \x27after_commit\x27 => true,
        ],
";
file_put_contents($p, str_replace($needle, $add, $s));
echo "  ＋ r122 の接続を足しました" . PHP_EOL;
'
php -l config/queue.php
php artisan config:clear >/dev/null 2>&1 || true
php artisan tinker --execute='
  $c = config("queue.connections.r122");
  echo "  既定の接続 : " . config("queue.default") . PHP_EOL;
  echo "  r122       : " . json_encode($c, JSON_UNESCAPED_SLASHES) . PHP_EOL;
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/30_queue設定.txt"

echo ""; echo "══ ③ 待ち行列の表を作る（DDL。migrate は打ちません）══"
# @ANCHOR-20261021-R122-QUEUETABLE
#   このプロジェクトには baganriki 系の migration が1本もありません。
#   r12.2 の表も DDL で作りました。同じやり方でそろえます。
#   migrate を打つと、関係のない未適用の migration まで走ります。
#   定義は Laravel 8 の queue:table / queue:batches-table /
#   queue:failed-table が作るものと同じ形にしてあります。
php artisan tinker --execute='
  $ddl = [
    "jobs" => "CREATE TABLE `jobs` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `queue` varchar(255) NOT NULL,
      `payload` longtext NOT NULL,
      `attempts` tinyint unsigned NOT NULL,
      `reserved_at` int unsigned DEFAULT NULL,
      `available_at` int unsigned NOT NULL,
      `created_at` int unsigned NOT NULL,
      PRIMARY KEY (`id`),
      KEY `jobs_queue_index` (`queue`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "job_batches" => "CREATE TABLE `job_batches` (
      `id` varchar(255) NOT NULL,
      `name` varchar(255) NOT NULL,
      `total_jobs` int NOT NULL,
      `pending_jobs` int NOT NULL,
      `failed_jobs` int NOT NULL,
      `failed_job_ids` longtext NOT NULL,
      `options` mediumtext,
      `cancelled_at` int DEFAULT NULL,
      `created_at` int NOT NULL,
      `finished_at` int DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "failed_jobs" => "CREATE TABLE `failed_jobs` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `uuid` varchar(255) NOT NULL,
      `connection` text NOT NULL,
      `queue` text NOT NULL,
      `payload` longtext NOT NULL,
      `exception` longtext NOT NULL,
      `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];
  foreach ($ddl as $t => $sql) {
    if (\Schema::hasTable($t)) {
      echo "  ○ $t はすでにあります" . PHP_EOL;
    } else {
      \DB::statement($sql);
      echo "  ＋ $t を作りました" . PHP_EOL;
    }
    echo "     行数 : " . \DB::table($t)->count() . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/31_待ち行列の表.txt"

echo ""; echo "══ ④ .env に Job の段と対象レースを足す（どちらも効きません）══"
# @ANCHOR-20261021-R122-JOBFLAG
#   BAGANRIKI_R122_JOBS=0 です。<b>0 のあいだ、いまの経路は変わりません。</b>
#   BAGANRIKI_R122_TARGETS は空です。空のときは1レースも送りません。
#   10月24日の12レースが決まったら、ここへ読点区切りで並べます。
for KV in "BAGANRIKI_R122_JOBS=0" "BAGANRIKI_R122_TARGETS=" \
          "BAGANRIKI_R122_TARGETS_ENFORCE=0" ; do
  K="${KV%%=*}"
  if grep -q "^$K=" "$APP/.env" 2>/dev/null; then
    echo "  ○ $K はすでにあります : $(grep "^$K=" "$APP/.env")"
  else
    printf '%s\n' "$KV" >> "$APP/.env"
    echo "  ＋ $KV を足しました"
  fi
done
php artisan config:clear >/dev/null 2>&1 || true
grep -E "^BAGANRIKI_" "$APP/.env" | tee "$OUT/32_env.txt"

echo ""; echo "══ ⑤ systemd のテンプレートを置く（まだ起動しません）══"
cp ~/r122_v84/systemd/r122-worker.sh /usr/local/bin/r122-worker.sh 2>/dev/null \
  && chmod 755 /usr/local/bin/r122-worker.sh 2>/dev/null \
  && echo "  ＋ /usr/local/bin/r122-worker.sh を置きました" \
  || echo "  － /usr/local/bin へ置けませんでした（root 権限が要ります）"
cp ~/r122_v84/systemd/'r122-worker@.service' /etc/systemd/system/ 2>/dev/null \
  && echo "  ＋ /etc/systemd/system/r122-worker@.service を置きました" \
  || echo "  － /etc/systemd/system へ置けませんでした（root 権限が要ります）"
echo "-- ラッパの構文（置けていれば）--"
[ -f /usr/local/bin/r122-worker.sh ] && bash -n /usr/local/bin/r122-worker.sh \
  && echo "  ○ 構文は通ります" || true
systemctl daemon-reload 2>/dev/null || true
echo "-- いまの状態（起動していないことの確認）--"
systemctl list-units 'r122-worker@*' --all --no-legend 2>/dev/null \
  | tee "$OUT/33_worker状態.txt" || true
if [ ! -s "$OUT/33_worker状態.txt" ]; then
  echo "  （1つも起動していません。これが期待どおりです）" | tee "$OUT/33_worker状態.txt"
fi
echo "-- 立てるときのコマンド（いまは打ちません）--"
cat <<'HOWTO' | tee "$OUT/34_workerの立て方.txt"
  systemctl enable --now 'r122-worker@r122-ai-1st:1'
  systemctl enable --now 'r122-worker@r122-ai-1st:2'
  systemctl enable --now 'r122-worker@r122-ai-2nd:1'
  systemctl enable --now 'r122-worker@r122-ai-2nd:2'
  systemctl enable --now 'r122-worker@r122-agg:1'
  systemctl enable --now 'r122-worker@r122-freeze:1'
  # 止めるとき
  systemctl disable --now 'r122-worker@*'
HOWTO

echo ""; echo "══ ⑤-2 実凍結から一続き（G-01〜G-24。010の5章末尾・009の③）══"
php artisan keiba:ai-analysis-compensate --r122-e2e-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/38_実凍結から一続き.txt"
echo ""; echo "-- 期限の幅だけ抜き出し（3,600秒の決め打ちが無いこと）--"
grep -E "G-0[5-9]|G-10|発走|情報上限|決め打ち|元に戻" "$OUT/38_実凍結から一続き.txt" \
  | tee "$OUT/38b_期限の幅.txt"

echo ""; echo "══ ⑥ 型保持の通し試験（T-01〜T-42／P-01〜P-30。010の3章）══"
php artisan keiba:ai-analysis-compensate --r122-type-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/37_型保持.txt"
echo ""; echo "-- 地図なしとの対比だけ抜き出し --"
grep -E "地図なし|地図あり" "$OUT/37_型保持.txt" | tee "$OUT/37b_対比.txt"

echo ""; echo "══ ⑥-2 Job構成の通し試験（J-01〜J-46。worker は立てません）══"
php artisan keiba:ai-analysis-compensate --r122-job-test 2>&1 | tee "$OUT/35_Job構成.txt"

echo ""; echo "══ ⑦ v80 で落ちた4件（E-11・E-19・E-37・E-52）══"
php artisan keiba:ai-analysis-compensate --r122-wire-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/16_実経路.txt"
echo ""; echo "-- 該当箇所だけ抜き出し --"
grep -E "E-11|E-19|E-37|E-52|E-58e" "$OUT/16_実経路.txt" | tee "$OUT/16c_4件.txt"

echo ""; echo "══ ⑧ 壊れていないこと（ぜんぶ回します）══"
php artisan keiba:ai-analysis-compensate --r122-queue-test 2>&1 | tee "$OUT/01_キュー.txt"
php artisan keiba:ai-analysis-compensate --r122-onebuild-test --date=2026-10-18 2>&1 \
  | tee "$OUT/14_固定入力.txt"
php artisan keiba:ai-analysis-compensate --r122-response-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/03_応答検証.txt"
php artisan keiba:ai-analysis-compensate --r122-publish-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/04_公開.txt"
php artisan keiba:ai-analysis-compensate --r122-read-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/05_読取API.txt"
php artisan keiba:ai-analysis-compensate --r122-prod-input="$HOME/r122_v23/mat" --date=2026-10-18 2>&1 \
  | tee "$OUT/07_本番入力.txt"
php artisan keiba:ai-analysis-compensate --r122-native-test="$HOME/r122_v23/mat" 2>&1 \
  | tee "$OUT/09_本体直通.txt"
php artisan keiba:ai-analysis-compensate --r122-market-test="$HOME/r122_v23/mat" \
  --date=2026-10-18 2>&1 | tee "$OUT/08_OPI.txt"
php artisan keiba:ai-analysis-compensate --r122-acq-test --date=2026-10-18 2>&1 \
  | tee "$OUT/02_凍結.txt" > /dev/null
php artisan keiba:ai-analysis-compensate --v04-test 2>&1 \
  | tee "$OUT/19_受入試験_全文.txt" | grep -E "判定件数|合格 |不合格"

echo ""; echo "══ ⑨ 巡回とキャッシュ後が「積むだけ」になっているか ══"
# Jobの段は 0 なので、ここでは<b>今までどおり同期の経路</b>が走ります。
# 段が下りているあいだ動きが変わっていないことの確認です。
php artisan keiba:ai-analysis-compensate --r122-sweep 2>&1 | tee "$OUT/13_やり直し.txt"
echo "-- 段が下りているので JOB_DISPATCHED は出ないはずです --"
grep -cE "JOB_DISPATCHED|JOB_REDISPATCHED" "$OUT/13_やり直し.txt" \
  | sed 's/^/  出た回数 : /'

echo ""; echo "══ ⑩ jobs 表に1行も残っていないこと（試験はロールバック）══"
php artisan tinker --execute='
  foreach (["jobs","job_batches","failed_jobs"] as $t) {
    echo sprintf("  %-12s %d 行", $t, \DB::table($t)->count()) . PHP_EOL;
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/36_jobs表の行数.txt"

echo ""; echo "══ ⑩-2 集計2表の全行明細（試験の前後。009の④）══"
# @ANCHOR-20261021-R122-AVGDETAIL  009のご指摘④
#   「過去平均2表の前後明細ログ」。指紋だけでは「どの行が変わっていないか」
#   が読めないので、18行ぜんぶを前後で並べます。
php artisan tinker --execute='
  foreach ([["popavg","t_horse_odds_finder_popularity_rank_average"],
            ["fukuavg","t_horse_odds_finder_fuku_popularity_rank_average"]] as $p) {
    [$n,$t] = $p;
    echo "── $n ──" . PHP_EOL;
    echo sprintf("  %4s %12s %8s %12s %12s %26s %26s",
        "順位","平均","件数","期間始","期間終","算出時刻","確認時刻") . PHP_EOL;
    foreach (\DB::table($t)->orderBy("popularity_rank")->get() as $r) {
      echo sprintf("  %4s %12s %8s %12s %12s %26s %26s",
          (string) $r->popularity_rank, (string) $r->odds_average,
          (string) $r->count, substr((string) $r->start_date,0,10),
          substr((string) $r->end_date,0,10),
          (string) ($r->computed_at ?? "（なし）"),
          (string) ($r->verified_at ?? "（なし）")) . PHP_EOL;
    }
  }
' 2>&1 | grep -vE "^Psy|^$" | tee "$OUT/39_集計2表_全行.txt"

echo ""; echo "══ ⑪ crontab は1文字も触っていないこと ══"
crontab -l 2>&1 | tee "$OUT/22_crontab.txt" | tail -3
echo "  （上は読み出しだけです）"

echo ""; echo "══ 不合格の総まとめ ══"
grep -hE "不合格" "$OUT"/*.txt | sort | uniq -c | sed 's/^/  /'
echo "-- × が出た行（あれば）--"
grep -hE "^\s*×" "$OUT"/*.txt | sort -u | head -40 || echo "  （× はありません）"

tar czf ~/r122_v84_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v84_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v84_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v84_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v84_logs.tgz があります）"
