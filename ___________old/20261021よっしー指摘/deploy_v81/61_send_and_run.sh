#!/bin/bash
# ══════════════════════════════════════════════════════════════════════
# v81 ＝ v80 の残り4件 ＋ Job構成の実装（010の2章ぜんぶ）
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
#   AiController.php                 cb9660926320505cd11b90f8bd4cda35
#   SummaryAiAnalysisCompensate.php  b34e87d897ce6ae591d3b0550b1cddaf
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
echo "  期待 AiController cb9660926320505cd11b90f8bd4cda35"
echo "  期待 Command      b34e87d897ce6ae591d3b0550b1cddaf"
md5 "$MACAPP"/app/Jobs/*.php

ssh "$SRV" 'mkdir -p ~/r122_v81/code ~/r122_v81/jobs ~/r122_v81/systemd'
scp -q "$HERE"/code/*.php    "$SRV:~/r122_v81/code/"
scp -q "$HERE"/jobs/*.php    "$SRV:~/r122_v81/jobs/"
scp -q "$HERE"/systemd/*     "$SRV:~/r122_v81/systemd/"
ssh "$SRV" 'bash -s' <<'REMOTE'
set -u
APP=/var/www/horse_odds_finder
BK=~/baganriki_20261018
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT=~/r122_v81/result_$STAMP; mkdir -p "$OUT"
mkdir -p "$BK/before_v81_$STAMP"
cp -p "$APP/app/Http/Controllers/Api/AiController.php"            "$BK/before_v81_$STAMP/"
cp -p "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php" "$BK/before_v81_$STAMP/"
cp -p "$APP/config/queue.php"                                     "$BK/before_v81_$STAMP/"
cp ~/r122_v81/code/AiController.php                "$APP/app/Http/Controllers/Api/AiController.php"
cp ~/r122_v81/code/SummaryAiAnalysisCompensate.php "$APP/app/Console/Commands/SummaryAiAnalysisCompensate.php"
mkdir -p "$APP/app/Jobs"
cp ~/r122_v81/jobs/*.php "$APP/app/Jobs/"
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
cp ~/r122_v81/systemd/r122-worker.sh /usr/local/bin/r122-worker.sh 2>/dev/null \
  && chmod 755 /usr/local/bin/r122-worker.sh 2>/dev/null \
  && echo "  ＋ /usr/local/bin/r122-worker.sh を置きました" \
  || echo "  － /usr/local/bin へ置けませんでした（root 権限が要ります）"
cp ~/r122_v81/systemd/'r122-worker@.service' /etc/systemd/system/ 2>/dev/null \
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

echo ""; echo "══ ⑥ Job構成の通し試験（J-01〜J-46。worker は立てません）══"
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

echo ""; echo "══ ⑪ crontab は1文字も触っていないこと ══"
crontab -l 2>&1 | tee "$OUT/22_crontab.txt" | tail -3
echo "  （上は読み出しだけです）"

echo ""; echo "══ 不合格の総まとめ ══"
grep -hE "不合格" "$OUT"/*.txt | sort | uniq -c | sed 's/^/  /'
echo "-- × が出た行（あれば）--"
grep -hE "^\s*×" "$OUT"/*.txt | sort -u | head -40 || echo "  （× はありません）"

tar czf ~/r122_v81_logs.tgz -C "$(dirname "$OUT")" "$(basename "$OUT")"
echo ""; echo "まとめました: ~/r122_v81_logs.tgz"
echo "結果: $OUT"
REMOTE

scp -q "$SRV:~/r122_v81_logs.tgz" "$HERE/" \
  && echo "" && echo "ログを取ってきました: $HERE/r122_v81_logs.tgz" \
  || echo "ログの取得に失敗しました（サーバ側には ~/r122_v81_logs.tgz があります）"
