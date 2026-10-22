
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-TIME  r12.2 の時刻契約
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 4章・8章／詳細設計書 4.3・11.2
 *
 * 基準は発走時刻 T（anchor_kind=POST_TIME）。
 *   slot_scheduled_at = T − スロット分
 *   slot_deadline     = slot_scheduled_at + 取得猶予（6分前30秒／他120秒）
 *   cutoff_at         = T − 360秒
 *   input_freeze_at   = cutoff_at + 30秒  … 利用できる情報の上限
 *   frozen_at         … 実際に保存した時刻。input_freeze_at とは別物
 *
 * 公開まわりは投票締切 sales_close_at が基準。発走時刻と同一視しません。
 *   publish_deadline           = sales_close_at − 60秒
 *   reply_deadline             = publish_deadline − 30秒
 *   effective_publish_deadline = min(publish_deadline, live_close − 60秒)
 *   effective_reply_deadline   = min(reply_deadline, effective_publish_deadline − 30秒)
 *
 * 日時は「秒必須・小数秒0〜6桁・タイムゾーン必須」のRFC3339だけを受け取り、
 * マイクロ秒の整数で比較します。7桁以上や独自形式は切り捨てず拒否します。
 */
final class BaganrikiTime
{
    /** スロットごとの取得猶予（ミリ秒）。ここに無い時点は予想入力に使いません */
    public const GRACE_MS = [21 => 120000, 18 => 120000, 15 => 120000,
                             12 => 120000,  9 => 120000,  6 =>  30000];

    /** 予想入力に使うスロット（30分前・3分前・発走時は入りません） */
    public const INPUT_SLOTS = [21, 18, 15, 12, 9, 6];

    /** 取消の監視だけに読むスロット（数値は信号へ入れません） */
    public const WATCH_SLOTS = [3, 0];

    public const CUTOFF_OFFSET_SEC       = -360;   // T − 360秒
    public const INPUT_FREEZE_OFFSET_SEC = -330;   // T − 330秒
    public const SAFETY_MARGIN_SEC       = 60;     // safety_margin_ms = 60,000
    public const REPLY_BEFORE_PUBLISH_SEC = 30;    // reply_before_publish_ms = 30,000

    private const US = 1000000;

    /**
     * RFC3339 をマイクロ秒の整数へ。読めなければ null。
     * 小数秒は0〜6桁だけ。7桁以上は切り捨てずに拒否します（契約12章）。
     */
    public static function us(?string $t): ?int
    {
        if ($t === null) { return null; }
        $re = '/^(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}:\d{2})(\.\d{1,6})?([Zz]|[+\-]\d{2}:\d{2})$/';
        if (!preg_match($re, $t, $m)) { return null; }
        $frac = isset($m[3]) ? str_pad(substr($m[3], 1), 6, '0') : '000000';
        $tz   = (strtoupper($m[4]) === 'Z') ? '+00:00' : $m[4];
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $m[1] . ' ' . $m[2] . $tz);
        if ($d === false) { return null; }
        return $d->getTimestamp() * self::US + (int) $frac;
    }

    /** マイクロ秒の整数を日本時間のRFC3339へ（表示・監査用） */
    public static function toJst(int $us): string
    {
        $d = (new \DateTimeImmutable('@' . intdiv($us, self::US)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $f = $us % self::US;
        if ($f < 0) { $f += self::US; }
        return $d->format('Y-m-d\TH:i:s') . sprintf('.%06d', $f) . $d->format('P');
    }

    public static function slotScheduled(int $anchorUs, int $slotMin): int
    { return $anchorUs - $slotMin * 60 * self::US; }

    public static function slotDeadline(int $anchorUs, int $slotMin): ?int
    {
        if (!isset(self::GRACE_MS[$slotMin])) { return null; }
        return self::slotScheduled($anchorUs, $slotMin) + self::GRACE_MS[$slotMin] * 1000;
    }

    public static function cutoff(int $anchorUs): int
    { return $anchorUs + self::CUTOFF_OFFSET_SEC * self::US; }

    public static function inputFreeze(int $anchorUs): int
    { return $anchorUs + self::INPUT_FREEZE_OFFSET_SEC * self::US; }

    public static function publishDeadline(int $salesCloseUs): int
    { return $salesCloseUs - self::SAFETY_MARGIN_SEC * self::US; }

    public static function replyDeadline(int $salesCloseUs): int
    { return self::publishDeadline($salesCloseUs) - self::REPLY_BEFORE_PUBLISH_SEC * self::US; }

    /**
     * 締切の前倒しを反映した実効期限。延長では猶予を増やしません。
     * $liveCloseUs が null なら前倒しなし。
     */
    public static function effectiveDeadlines(int $salesCloseUs, ?int $liveCloseUs): array
    {
        $pub = self::publishDeadline($salesCloseUs);
        $rep = self::replyDeadline($salesCloseUs);
        if ($liveCloseUs !== null) {
            $pub = min($pub, $liveCloseUs - self::SAFETY_MARGIN_SEC * self::US);
        }
        $rep = min($rep, $pub - self::REPLY_BEFORE_PUBLISH_SEC * self::US);
        return ['publish' => $pub, 'reply' => $rep];
    }

    /**
     * 1バッチを採用してよいか。期限ちょうどは可、1マイクロ秒でも後なら不可。
     * @return array ['ok'=>bool, 'reason'=>string|null, 'code'=>string|null]
     */
    public static function acceptBatch(int $slot, array $b, ?int $anchorUs = null): array
    {
        if (!isset(self::GRACE_MS[$slot])) {
            return ['ok' => false, 'code' => 'SLOT_NOT_INPUT',
                    'reason' => '予想に使わないスロット（21/18/15/12/9/6分前だけ）'];
        }
        $need = ['request_started_at', 'response_received_at', 'available_at', 'input_freeze_at'];
        $v = [];
        foreach ($need as $k) {
            $v[$k] = self::us($b[$k] ?? null);
            if ($v[$k] === null) {
                return ['ok' => false, 'code' => 'TIME_UNREADABLE',
                        'reason' => $k . ' が無いか日時として読めない'];
            }
        }
        // 予定時刻は、与えられていれば使い、無ければ発走時刻から作ります
        $sch = self::us($b['slot_scheduled_at'] ?? null);
        if ($sch === null) {
            if ($anchorUs === null) {
                return ['ok' => false, 'code' => 'TIME_UNREADABLE',
                        'reason' => 'slot_scheduled_at も発走時刻も無い'];
            }
            $sch = self::slotScheduled($anchorUs, $slot);
        }
        $deadline = $sch + self::GRACE_MS[$slot] * 1000;

        if ($v['request_started_at']   < $sch)
            return ['ok' => false, 'code' => 'REQUEST_BEFORE_SCHEDULE', 'reason' => '要求開始が予定時刻より前'];
        if ($v['response_received_at'] < $v['request_started_at'])
            return ['ok' => false, 'code' => 'CLOCK_REVERSED', 'reason' => '受信完了が要求開始より前（時計逆行）'];
        if ($v['available_at']         < $v['response_received_at'])
            return ['ok' => false, 'code' => 'CLOCK_REVERSED', 'reason' => '利用可能が受信完了より前（時計逆行）'];
        if ($v['available_at'] > $deadline)
            return ['ok' => false, 'code' => 'ACQUISITION_DEADLINE_EXCEEDED', 'reason' => '取得猶予を超えた'];
        if ($v['available_at'] > $v['input_freeze_at'])
            return ['ok' => false, 'code' => 'ACQUISITION_DEADLINE_EXCEEDED', 'reason' => '情報上限 input_freeze_at を超えた'];

        if (($b['source_updated_at'] ?? null) !== null) {
            $su = self::us($b['source_updated_at']);
            if ($su === null)
                return ['ok' => false, 'code' => 'TIME_UNREADABLE', 'reason' => 'source_updated_at が日時として読めない'];
            if ($su > $v['response_received_at'])
                return ['ok' => false, 'code' => 'SOURCE_TIME_FUTURE', 'reason' => '取得元の更新時刻が受信完了より未来'];
        }
        return ['ok' => true, 'code' => null, 'reason' => null];
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-ACQ  取得バッチの採用（SLOT_FIRST_VALID_COMMITTED_V1）
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 8章／詳細設計書 4.2・4.3
 *
 *   ・採用できるバッチを available_at → response_received_at → batch_id の
 *     昇順に並べ、最初の1件を採用します。batch_id は固定のバイト順で比べます。
 *   ・6分前だけは「同じバッチの中に全活動馬の有効な単勝がある」ことが追加条件です。
 *     別のバッチの馬を混ぜて揃えません。揃わなければ計算不能にします。
 *   ・それ以外の時点の部分欠損は欠損のまま信号へ伝えます。
 *     「全スロット完備」という新しい足切りを足しません。
 *   ・未確定の監査行は採用しません。available_at を予定時刻で埋めません。
 */
final class BaganrikiAcquisition
{
    /**
     * スロット1つぶんの採用バッチを選びます。
     *
     * @param int        $slot      スロット分数（21/18/15/12/9/6）
     * @param array      $batches   監査行の配列。各要素に batch_id・各実時刻・
     *                              values[馬番]=['win'=>文字列|null,'place_min'=>…] を持つ
     * @param array      $activeNums 活動馬の馬番（6分前の全頭確認に使う）
     * @param int|null   $anchorUs  発走時刻（予定時刻が無い行のため）
     * @return array ['status'=>'ADOPTED'|'UNAVAILABLE', 'batch'=>array|null,
     *                'reason'=>string|null, 'code'=>string|null, 'rejected'=>array]
     */
    public static function pick(int $slot, array $batches, array $activeNums,
                                ?int $anchorUs = null): array
    {
        $ok = []; $rejected = [];
        foreach ($batches as $b) {
            if (($b['committed'] ?? true) !== true) {
                $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                               'code' => 'NOT_COMMITTED',
                               'reason' => '監査行が未確定（保存commit前）'];
                continue;
            }
            $r = BaganrikiTime::acceptBatch($slot, $b, $anchorUs);
            if (!$r['ok']) {
                $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                               'code' => $r['code'], 'reason' => $r['reason']];
                continue;
            }
            if ($slot === 6) {
                $missing = self::missingWin($b, $activeNums);
                if (!empty($missing)) {
                    $rejected[] = ['batch_id' => $b['batch_id'] ?? null,
                                   'code' => 'FREEZE_ODDS_MISSING',
                                   'reason' => '6分前の単勝が同じバッチに揃っていない（馬番 '
                                             . implode('・', $missing) . '）'];
                    continue;
                }
            }
            $ok[] = $b;
        }
        if (empty($ok)) {
            return ['status' => 'UNAVAILABLE', 'batch' => null, 'rejected' => $rejected,
                    'code' => ($slot === 6) ? 'FREEZE_ODDS_MISSING' : 'ACQUISITION_DEADLINE_EXCEEDED',
                    'reason' => '採用できるバッチがありません'];
        }
        usort($ok, static function (array $x, array $y): int {
            $a = BaganrikiTime::us($x['available_at']);
            $b = BaganrikiTime::us($y['available_at']);
            if ($a !== $b) { return $a <=> $b; }
            $a = BaganrikiTime::us($x['response_received_at']);
            $b = BaganrikiTime::us($y['response_received_at']);
            if ($a !== $b) { return $a <=> $b; }
            // batch_id は固定のバイト順
            return strcmp((string) ($x['batch_id'] ?? ''), (string) ($y['batch_id'] ?? ''));
        });
        return ['status' => 'ADOPTED', 'batch' => $ok[0], 'rejected' => $rejected,
                'code' => null, 'reason' => null];
    }

    /** 6分前に単勝が無い活動馬の馬番 */
    private static function missingWin(array $b, array $activeNums): array
    {
        $vals = $b['values'] ?? [];
        $miss = [];
        foreach ($activeNums as $n) {
            $w = $vals[$n]['win'] ?? null;
            if (!self::usableDecimal($w)) { $miss[] = (int) $n; }
        }
        sort($miss);
        return $miss;
    }

    /** 使える十進数の文字列か（0・負・空・「取消」は不可） */
    public static function usableDecimal($v): bool
    {
        if ($v === null || is_bool($v)) { return false; }
        $s = trim((string) $v);
        if ($s === '' || $s === '取消' || $s === '除外') { return false; }
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $s)) { return false; }
        return (ltrim($s, '0.') !== '');   // 0 や 0.0000 は不可
    }

    /**
     * collection_audit を組み立てます（入力JSONの最上位に入る項目）。
     * 採用時は ADOPTED と各時刻、欠損時は UNAVAILABLE と空でない理由。
     * 時刻を作り話で埋めません。
     */
    public static function buildAudit(array $picked): array
    {
        $out = [];
        foreach (BaganrikiTime::INPUT_SLOTS as $slot) {
            $p = $picked[$slot] ?? null;
            if ($p === null || ($p['status'] ?? '') !== 'ADOPTED') {
                $out[(string) $slot] = [
                    'status' => 'UNAVAILABLE',
                    'reason' => ($p['reason'] ?? null) ?: 'このスロットの採用バッチがありません',
                ];
                continue;
            }
            $b = $p['batch'];
            $out[(string) $slot] = [
                'status'               => 'ADOPTED',
                'batch_id'             => $b['batch_id'] ?? null,
                'payload_hash'         => $b['payload_hash'] ?? null,
                'slot_scheduled_at'    => $b['slot_scheduled_at'] ?? null,
                'source_updated_at'    => $b['source_updated_at'] ?? null,
                'request_started_at'   => $b['request_started_at'] ?? null,
                'response_received_at' => $b['response_received_at'] ?? null,
                'available_at'         => $b['available_at'] ?? null,
            ];
        }
        return $out;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-SNAPSHOT  凍結集合・頭数・共通JSON・hash
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 2章・3章・5章／詳細設計書 4.4・7.2
 *
 * 頭数の4つを別物として扱います（混ぜると意味が壊れます）。
 *   entry_count    正本出馬表の頭数。欠損なら null
 *   acquired_count 読み込めた対象馬の一意件数。正常出走頭数の代用にしない
 *   frozen_count   情報上限までの正式取消を除いた凍結出走集合の頭数（＝N）
 *   active_count   凍結集合から最新の累積取消を除いた頭数。集合矛盾時は null
 */
final class BaganrikiSnapshot
{
    /**
     * 入力の照合。止めるべきときは理由コードを返します。
     *
     * @param array|null $entryNums   出馬表の馬番。null は「出馬表が読めなかった」
     * @param int|null   $declared    レース情報の出走頭数
     * @param array      $oddsNums    オッズが取れた馬番
     * @param array      $cancelPre   情報上限までに利用可能だった正式取消の馬番
     * @param array      $cancelPost  情報上限より後の取消（凍結集合には残す）
     * @param array      $conflict    取消の時点より後に実オッズがある馬番（入力不整合）
     */
    public static function checkSets(?array $entryNums, ?int $declared, array $oddsNums,
                                     array $cancelPre = [], array $cancelPost = [],
                                     array $conflict = []): array
    {
        $entryCount    = ($entryNums === null) ? null : count(array_unique($entryNums));
        $acquiredCount = count(array_unique($oddsNums));

        $base = [
            'entry_count'    => $entryCount,
            'acquired_count' => $acquiredCount,
            'frozen_count'   => null,
            'active_count'   => null,
            'frozen_nums'    => [],
            'cancel_pre'     => array_values(array_unique($cancelPre)),
            'cancel_post'    => array_values(array_unique($cancelPost)),
        ];

        // 出馬表は必須。オッズの頭数で代用しません。
        if ($entryNums === null || $entryCount === 0) {
            return $base + ['ok' => false, 'code' => 'ENTRY_MISSING',
                'reason' => sprintf('出馬表が欠損している（出馬表 %d頭・レース情報の出走頭数 %s頭・オッズが取れた馬 %d頭）',
                    (int) $entryCount, $declared === null ? '不明' : (string) $declared, $acquiredCount)];
        }
        // 宣言頭数との照合
        if ($declared !== null && $declared !== $entryCount) {
            return $base + ['ok' => false, 'code' => 'ENTRY_SET_MISMATCH',
                'reason' => sprintf('レース情報の出走頭数（%d頭）と出馬表の頭数（%d頭）が合わない',
                    $declared, $entryCount)];
        }
        // 出馬表に無い馬のオッズ
        $extra = array_values(array_diff(array_unique($oddsNums), $entryNums));
        if (!empty($extra)) {
            sort($extra);
            return $base + ['ok' => false, 'code' => 'ENTRY_SET_MISMATCH',
                'reason' => '出馬表に無い馬番のオッズがある（馬番 ' . implode('・', $extra) . '）'];
        }
        // 取消の時点より後に実オッズ（入力不整合）
        if (!empty($conflict)) {
            sort($conflict);
            $r = $base + ['ok' => false, 'code' => 'CANCEL_CONFLICT',
                'reason' => sprintf('取消の時点より後に実オッズがある（入力不整合・馬番 %s）／出馬表 %d頭・オッズが取れた馬 %d頭',
                    implode('・', $conflict), $entryCount, $acquiredCount)];
            $r['active_count'] = null;   // 実出走頭数を確定できない
            return $r;
        }

        // 凍結集合＝出馬表 − 情報上限までの正式取消
        $frozen = array_values(array_diff($entryNums, $cancelPre));
        sort($frozen);
        $base['frozen_nums']  = $frozen;
        $base['frozen_count'] = count($frozen);
        // 凍結後の取消は凍結集合に残し、active_count からだけ引きます
        $base['active_count'] = count(array_diff($frozen, $cancelPost));

        return $base + ['ok' => true, 'code' => null, 'reason' => null];
    }

    /**
     * 凍結順位（6分前単勝の昇順、同値は馬番の小さい順）。
     * 非減少であることを確認し、矛盾は入力不正として拒否します。
     * 値は十進数文字列のまま受け取り、1万倍の整数で比べます（丸めを入れません）。
     */
    public static function frozenRank(array $win6): array
    {
        $scaled = [];
        foreach ($win6 as $num => $s) {
            $v = self::scale($s);
            if ($v === null) {
                return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                        'reason' => '6分前の単勝が読めない馬がいる（馬番 ' . $num . '）',
                        'rank' => []];
            }
            $scaled[(int) $num] = $v;
        }
        if (empty($scaled)) {
            return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                    'reason' => '6分前の単勝が1頭も無い', 'rank' => []];
        }
        $nums = array_keys($scaled);
        usort($nums, static function ($a, $b) use ($scaled) {
            if ($scaled[$a] !== $scaled[$b]) { return $scaled[$a] <=> $scaled[$b]; }
            return $a <=> $b;
        });
        $rank = []; $k = 1; $prev = null;
        foreach ($nums as $n) {
            if ($prev !== null && $scaled[$n] < $prev) {
                return ['ok' => false, 'code' => 'FREEZE_ODDS_MISSING',
                        'reason' => '凍結順位が非減少になっていない（馬番 ' . $n . '）', 'rank' => []];
            }
            $prev = $scaled[$n];
            $rank[$n] = $k++;
        }
        return ['ok' => true, 'code' => null, 'reason' => null, 'rank' => $rank];
    }

    /** 十進数文字列を1万倍の整数へ。PHP float を通しません */
    public static function scale($v): ?int
    {
        if ($v === null || is_bool($v)) { return null; }
        $s = trim((string) $v);
        if (!preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $s, $m)) { return null; }
        $frac = str_pad($m[2] ?? '', 4, '0');
        $n = (int) $m[1] * 10000 + (int) $frac;
        return ($n > 0) ? $n : null;
    }

    /**
     * 共通JSONとそのSHA-256。
     * ai_role / role_cap / role_quota / snapshot_hash 自身を除いたものを
     * 一度だけ作り、その本文を両roleへ渡します。
     * request_at とライブ取消は混ぜません。
     */
    public static function commonJson(array $input): array
    {
        foreach (['ai_role', 'role_cap', 'role_quota', 'snapshot_hash',
                  'request_at', 'live_cancellations'] as $k) {
            unset($input[$k]);
        }
        $json = json_encode($input,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            return ['ok' => false, 'reason' => '共通JSONを作れませんでした', 'json' => null, 'hash' => null];
        }
        return ['ok' => true, 'reason' => null, 'json' => $json,
                'hash' => hash('sha256', $json),
                'hash_method' => 'exact-common-payload-sha256-r8'];
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-RANK  順位・統合・公開候補
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 7章／詳細設計書 11.1・6章
 *
 *   ・帯の中の全適格馬が complete なら SCORE、partial を含むなら EVIDENCE。
 *     AIの申告で complete 帯を EVIDENCE に変えさせません。
 *     EVIDENCE では点数を順位キーに使いません。
 *   ・順位点 rank_score = 100 × (K − r + 1) ÷ K。K は凍結帯の頭数。
 *     統合点は両roleの最大値。
 *   ・両AIの検証済み原公開候補だと証明できる場合だけ +5。
 *   ・同点は「両AIの原公開一致あり」→「独立根拠群数の多い順」→「馬番の昇順」。
 *   ・統合後にライブ取消を除き、残った馬の凍結帯と active_count から
 *     構造配分を調整します。人気は詰め直しません。
 *   ・評価後の適格馬不足は D は B のみ、B は D のみ、A は B→D。
 *     B・D の不足を A で埋めません。
 */
final class BaganrikiRanking
{
    /** 帯の採点方式。全頭 complete なら SCORE、partial を含むなら EVIDENCE */
    public static function bandBasis(array $horses): string
    {
        foreach ($horses as $h) {
            if (($h['score_state'] ?? 'partial') !== 'complete') { return 'EVIDENCE'; }
        }
        return empty($horses) ? 'EVIDENCE' : 'SCORE';
    }

    /**
     * 帯の中の順位。SCORE は点数→独立根拠群数→馬番、
     * EVIDENCE は独立根拠群数→馬番（点数を使いません）。
     * @return array 馬番 => 順位r（1始まり）
     */
    public static function rankWithin(array $horses, string $basis): array
    {
        $rows = array_values($horses);
        usort($rows, static function (array $a, array $b) use ($basis): int {
            if ($basis === 'SCORE') {
                $x = (int) ($a['score'] ?? 0); $y = (int) ($b['score'] ?? 0);
                if ($x !== $y) { return $y <=> $x; }
            }
            $x = (int) ($a['independent_groups'] ?? 0);
            $y = (int) ($b['independent_groups'] ?? 0);
            if ($x !== $y) { return $y <=> $x; }
            return ((int) $a['num']) <=> ((int) $b['num']);
        });
        $out = []; $r = 1;
        foreach ($rows as $h) { $out[(int) $h['num']] = $r++; }
        return $out;
    }

    /** 順位点。K は凍結帯の頭数 */
    public static function rankScore(int $K, int $r): float
    {
        if ($K <= 0) { return 0.0; }
        return 100.0 * ($K - $r + 1) / $K;
    }

    /**
     * 両roleの順位から統合点を出します。
     * @param array $per  role => [馬番 => ['r'=>順位, 'K'=>帯頭数,
     *                              'public'=>bool（検証済み原公開候補か）,
     *                              'groups'=>独立根拠群数]]
     * @param array $proofHash role => 加点を証明する応答hash（無ければ null）
     */
    public static function merge(array $per, array $proofHash = []): array
    {
        $acc = [];
        foreach ($per as $role => $rows) {
            foreach ($rows as $num => $v) {
                $num = (int) $num;
                $s = self::rankScore((int) $v['K'], (int) $v['r']);
                if (!isset($acc[$num])) {
                    $acc[$num] = ['num' => $num, 'score' => $s, 'public_roles' => [],
                                  'groups' => (int) ($v['groups'] ?? 0), 'bonus' => 0.0,
                                  'public_proof_hash' => null];
                } else {
                    $acc[$num]['score'] = max($acc[$num]['score'], $s);
                    $acc[$num]['groups'] = max($acc[$num]['groups'], (int) ($v['groups'] ?? 0));
                }
                if (!empty($v['public'])) { $acc[$num]['public_roles'][] = $role; }
            }
        }
        $roles = array_keys($per);
        foreach ($acc as $num => &$a) {
            // 両AIの検証済み原公開候補だと証明できたときだけ +5
            $both = true;
            foreach ($roles as $r) {
                if (!in_array($r, $a['public_roles'], true)) { $both = false; break; }
            }
            if ($both && count($roles) >= 2) {
                $a['bonus'] = 5.0;
                $a['score'] += 5.0;
                $a['public_proof_hash'] = $proofHash;
            }
            $a['both_public'] = $both && count($roles) >= 2;
        }
        unset($a);
        $rows = array_values($acc);
        usort($rows, static function (array $x, array $y): int {
            if ($x['score'] !== $y['score']) { return $y['score'] <=> $x['score']; }
            if ($x['both_public'] !== $y['both_public']) { return $y['both_public'] <=> $x['both_public']; }
            if ($x['groups'] !== $y['groups']) { return $y['groups'] <=> $x['groups']; }
            return $x['num'] <=> $y['num'];
        });
        return $rows;
    }

    /**
     * 評価後の適格馬不足を埋めます。
     *   D の不足は B からだけ、B の不足は D からだけ、A の不足は B→D。
     *   B・D の不足を A で埋めません。
     * @param array $quota   ['A'=>n,'B'=>n,'D'=>n]
     * @param array $avail   ['A'=>適格馬数,'B'=>…,'D'=>…]
     * @return array ['quota'=>調整後, 'shortfalls'=>不足の記録]
     */
    public static function fillShortfall(array $quota, array $avail): array
    {
        $q = ['A' => (int) $quota['A'], 'B' => (int) $quota['B'], 'D' => (int) $quota['D']];
        $short = [];
        $from = ['D' => ['B'], 'B' => ['D'], 'A' => ['B', 'D']];
        foreach (['D', 'B', 'A'] as $band) {
            $need = $q[$band] - (int) ($avail[$band] ?? 0);
            if ($need <= 0) { continue; }
            $moved = 0;
            foreach ($from[$band] as $to) {
                while ($moved < $need) {
                    $used = $q[$to];
                    if ($used >= (int) ($avail[$to] ?? 0)) { break; }
                    $q[$to]++; $q[$band]--; $moved++;
                }
            }
            $short[] = ['band' => $band, 'requested' => (int) $quota[$band],
                        'eligible' => (int) ($avail[$band] ?? 0),
                        'shortfall' => $need - $moved,
                        'moved_to' => $from[$band], 'moved' => $moved];
        }
        return ['quota' => $q, 'shortfalls' => $short];
    }

    /**
     * 公開期限前の取消を反映して、公開quotaを作り直します。
     * 人気は詰め直しません（凍結帯のまま）。
     *
     * @param array $frozenBand 馬番 => 'A'|'B'|'D'（凍結時の帯）
     * @param array $cancelled  取消の馬番
     * @param array $quota1st   凍結時の1st配分
     */
    public static function afterCancellation(array $frozenBand, array $cancelled,
                                             array $quota1st): array
    {
        $alive = [];
        foreach ($frozenBand as $num => $band) {
            if (in_array((int) $num, array_map('intval', $cancelled), true)) { continue; }
            $alive[$band] = ($alive[$band] ?? 0) + 1;
        }
        $avail = ['A' => $alive['A'] ?? 0, 'B' => $alive['B'] ?? 0, 'D' => $alive['D'] ?? 0];
        $r = self::fillShortfall($quota1st, $avail);
        return ['active_count' => array_sum($avail), 'band_alive' => $avail,
                'live_quota' => $r['quota'], 'shortfalls' => $r['shortfalls'],
                'note' => '取消で人気を詰め直していません（凍結帯のまま）'];
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-RESP  原応答の検証と局所回復
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 6章・11章／詳細設計書 10.2
 *
 *   ・識別・版・role・hash・quota・mode_status・allocation_mode・mode_policy_id の
 *     不一致は応答全体を不採用にします。
 *   ・馬番の欠落・重複・未知馬・局所フィールド不正は、その範囲だけ隔離して
 *     正常な他馬を残します。片側のroleが失敗しても、もう片側は残します。
 *   ・未完了や長さ打切りは complete=false のまま保持し、選出へ使いません。
 *   ・訂正は各role最大1回。正常な根拠を消す訂正、complete を partial へ
 *     悪化させる訂正は採用せず、初回を保持します。
 */
final class BaganrikiResponse
{
    public const BEGIN = '<<<BAGANRIKI_V041_R8_BEGIN>>>';
    public const END   = '<<<BAGANRIKI_V041_R8_END>>>';

    /** BEGIN/END に挟まれたJSONを1つだけ厳密に取り出します */
    public static function extract(string $raw): array
    {
        $i = strpos($raw, self::BEGIN);
        $j = strpos($raw, self::END);
        if ($i === false || $j === false || $j <= $i) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'BEGIN/END マーカーが揃っていない', 'json' => null];
        }
        if (strpos($raw, self::BEGIN, $i + 1) !== false) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'BEGIN が複数ある', 'json' => null];
        }
        $body = substr($raw, $i + strlen(self::BEGIN), $j - $i - strlen(self::BEGIN));
        $data = json_decode(trim($body), true, 64);
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => 'JSONとして読めない（' . json_last_error_msg() . '）', 'json' => null];
        }
        return ['ok' => true, 'code' => null, 'reason' => null, 'json' => $data];
    }

    /**
     * 応答全体の照合。ここが合わなければ応答ごと不採用にします。
     * @param array $expect ['prompt_version','schema_version','race_id','ai_role',
     *                       'snapshot_hash','role_cap','role_quota',
     *                       'mode','mode_status','allocation_mode','mode_policy_id']
     */
    public static function checkEnvelope(array $res, array $expect): array
    {
        $bad = [];
        foreach (['prompt_version', 'schema_version', 'race_id', 'ai_role',
                  'snapshot_hash', 'mode', 'mode_status', 'allocation_mode',
                  'mode_policy_id'] as $k) {
            if (!array_key_exists($k, $expect)) { continue; }
            if (($res[$k] ?? null) !== $expect[$k]) {
                $bad[] = sprintf('%s（期待 %s / 実際 %s）', $k,
                    var_export($expect[$k], true), var_export($res[$k] ?? null, true));
            }
        }
        if (array_key_exists('role_cap', $expect)
            && (int) ($res['role_cap'] ?? -1) !== (int) $expect['role_cap']) {
            $bad[] = 'role_cap';
        }
        if (array_key_exists('role_quota', $expect)) {
            $rq = $res['role_quota'] ?? null;
            foreach (['A', 'B', 'D'] as $b) {
                if ((int) ($rq[$b] ?? -1) !== (int) ($expect['role_quota'][$b] ?? -2)) {
                    $bad[] = 'role_quota.' . $b;
                }
            }
        }
        if (!empty($bad)) {
            return ['ok' => false, 'code' => 'AI_FAILURE',
                    'reason' => '応答の識別・版・mode・配分が合わない: ' . implode('／', $bad)];
        }
        return ['ok' => true, 'code' => null, 'reason' => null];
    }

    /**
     * 馬ごとの隔離。凍結集合に無い馬・重複・局所不正はその馬だけ外します。
     * @return array ['kept'=>正常な馬の配列, 'isolated'=>[馬番=>理由]]
     */
    public static function isolateHorses(array $horses, array $frozenNums): array
    {
        $kept = []; $iso = []; $seen = [];
        foreach ($horses as $h) {
            $num = $h['num'] ?? null;
            if (!is_int($num) && !(is_string($num) && ctype_digit($num))) {
                $iso['?' . count($iso)] = '馬番が数値でない';
                continue;
            }
            $num = (int) $num;
            if (isset($seen[$num])) { $iso[$num] = '同じ馬番が重複している'; continue; }
            $seen[$num] = true;
            if (!in_array($num, $frozenNums, true)) { $iso[$num] = '凍結集合に無い馬番'; continue; }
            $kept[$num] = $h;
        }
        // 欠落している馬は「不足」として返します（他馬は残します）
        $missing = array_values(array_diff($frozenNums, array_keys($kept)));
        sort($missing);
        return ['kept' => $kept, 'isolated' => $iso, 'missing' => $missing];
    }

    /**
     * 訂正を採用してよいか。
     *   ・初回の正常な根拠を全部失う → 採用しない
     *   ・complete を partial へ悪化させる → 採用しない
     *   ・凍結集合に無い馬・対象外の変更 → 採用しない
     *   ・受領時刻が初回より前 → 採用しない（同一時刻は可）
     */
    public static function acceptCorrection(array $first, array $fix,
                                            ?string $firstAt = null, ?string $fixAt = null): array
    {
        if ($firstAt !== null && $fixAt !== null) {
            $a = BaganrikiTime::us($firstAt); $b = BaganrikiTime::us($fixAt);
            if ($a === null || $b === null) {
                return ['ok' => false, 'reason' => '受領時刻が日時として読めない'];
            }
            if ($b < $a) { return ['ok' => false, 'reason' => '訂正の受領時刻が初回より前'];
            }
        }
        foreach ($first as $num => $h0) {
            if (!array_key_exists($num, $fix)) { continue; }   // 変更なしは初回を保持
            $h1 = $fix[$num];
            $g0 = (int) ($h0['independent_groups'] ?? 0);
            $g1 = (int) ($h1['independent_groups'] ?? 0);
            if ($g0 > 0 && $g1 === 0) {
                return ['ok' => false, 'reason' => sprintf('馬番 %d の正常な根拠を全部失う訂正', $num)];
            }
            $s0 = $h0['score_state'] ?? 'partial';
            $s1 = $h1['score_state'] ?? 'partial';
            if ($s0 === 'complete' && $s1 !== 'complete') {
                return ['ok' => false, 'reason' => sprintf('馬番 %d の採点を complete から悪化させる訂正', $num)];
            }
        }
        $unknown = array_values(array_diff(array_keys($fix), array_keys($first)));
        if (!empty($unknown)) {
            sort($unknown);
            return ['ok' => false, 'reason' => '初回に無い馬番を足している（馬番 '
                                             . implode('・', $unknown) . '）'];
        }
        return ['ok' => true, 'reason' => null];
    }

    /** 訂正を反映した結果。対象外の正常行は初回のままにします */
    public static function applyCorrection(array $first, array $fix): array
    {
        $out = $first;
        foreach ($fix as $num => $h) { $out[$num] = $h; }
        return $out;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261020-R122-VIEW  読取API用のDTOと表示状態
 * ═══════════════════════════════════════════════════════════════════════════
 * 根拠：server_contract_r12.2.txt 8章・11章／詳細設計書 12.2
 *
 *   state は公開状態で、mode_status とは別物です。
 *     PENDING     処理中で期限内
 *     PUBLISHED   有効な正式公開がある
 *     UNAVAILABLE 未公開のまま実効公開期限を過ぎた／固定入力が計算不能
 *     CANCELLED   全頭取消
 *   正式公開の履歴と現在のライブ状態を分けて返します。
 *   原AI応答・予約token・内部認証情報は返しません。
 */
final class BaganrikiPublicView
{
    /** 表示状態を決めます */
    public static function state(bool $hasPublication, bool $allCancelled,
                                 bool $inputInvalid, bool $pastDeadline): string
    {
        if ($allCancelled)   { return 'CANCELLED'; }
        if ($hasPublication) { return 'PUBLISHED'; }
        if ($inputInvalid)   { return 'UNAVAILABLE'; }
        return $pastDeadline ? 'UNAVAILABLE' : 'PENDING';
    }

    /** 暫定のときの表示文。確定した波乱度や星としては出しません */
    public static function provisionalLabel(?string $allocationMode): ?string
    {
        if ($allocationMode === 'MIXED')    { return '混戦として暫定選出'; }
        if ($allocationMode === 'VOLATILE') { return '波乱として暫定選出'; }
        return null;
    }

    public const PROVISIONAL_NOTE = '過去類似判定が未確定のため';

    /**
     * 読取APIが返す形を組み立てます。
     * 数値は十進数文字列のまま渡し、一律 double にしません。
     */
    public static function build(array $a): array
    {
        $modeStatus = $a['mode_status'] ?? null;
        $alloc      = $a['allocation_mode'] ?? null;
        return [
            'schema_version'  => 'baganriki-v041-r12-2',
            'race_id'         => $a['race_id'] ?? null,
            'state'           => $a['state'] ?? null,
            'mode'            => $a['mode'] ?? null,
            'mode_status'     => $modeStatus,
            'allocation_mode' => $alloc,
            'mode_policy_id'  => $a['mode_policy_id'] ?? null,
            'display_rating'  => ($modeStatus === 'PROVISIONAL') ? null : ($a['display_rating'] ?? null),
            'provisional'     => ($modeStatus === 'PROVISIONAL') ? [
                'label' => self::provisionalLabel($alloc),
                'note'  => self::PROVISIONAL_NOTE,
            ] : null,
            'publication'     => $a['publication'] ?? null,   // 未公開は null
            'live_state'      => [
                'state_revision'    => $a['state_revision'] ?? null,
                'cancellations'     => array_values($a['cancellations'] ?? []),
                'active_count'      => $a['active_count'] ?? null,
                'live_target_count' => $a['live_target_count'] ?? null,
                'live_quota'        => $a['live_quota'] ?? null,
                'effective_publish_deadline' => $a['effective_publish_deadline'] ?? null,
            ],
            'input_audit'     => [
                'collection_audit'      => $a['collection_audit'] ?? null,
                'input_freeze_at'       => $a['input_freeze_at'] ?? null,
                'coverage'              => $a['coverage'] ?? null,
                'time_contract_version' => 'baganriki-post-slot-grace-v1',
                'anchor_kind'           => 'POST_TIME',
                'sampling_policy_id'    => 'SLOT_FIRST_VALID_COMMITTED_V1',
            ],
            'diagnostics'     => array_values($a['diagnostics'] ?? []),
            'shortfalls'      => array_values($a['shortfalls'] ?? []),
            'buy_recommended' => false,
            'purchase_policy_status' => 'PENDING_VALIDATION',
            'learning_stage'  => 'COLLECT_ONLY',
            'model_version'   => null,
        ];
    }

    /** 診断コード（詳細設計書12.2の表） */
    public const CODES = [
        'ENTRY_MISSING'                    => '出馬表が欠損。計算停止（対象レースは集計に残す）',
        'ENTRY_SET_MISMATCH'               => '出馬表・宣言頭数・オッズの集合が合わない',
        'FREEZE_ODDS_MISSING'              => '6分前単勝が揃わない。計算停止',
        'CANCEL_CONFLICT'                  => '取消の時点より後に実オッズ。active_count は null',
        'ACQUISITION_DEADLINE_EXCEEDED'    => '取得猶予または情報上限を超えたバッチは不採用',
        'MODE_UNRESOLVED'                  => '選出停止。X6以外への暫定例外は広げない',
        'AI_FAILURE'                       => '応答全体が不採用。別role・別馬は残す',
        'PARTIAL_AI_VALIDATION'            => '一部だけ検証できた',
        'RANK_RECOVERY_FALLBACK'           => '縮退順位を使った',
        'PUBLICATION_DEADLINE_EXCEEDED'    => '新規公開の保存を拒否',
        'STALE_INTENT'                     => '読んだ版が古い。保存しない',
        'PUBLIC_CANDIDATE_VALIDATION_FAILED' => '公開候補の整合が取れない',
    ];
}
