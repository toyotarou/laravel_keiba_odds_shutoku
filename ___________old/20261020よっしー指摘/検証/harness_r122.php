<?php
// 本番コード 10221〜10590 行から抜き出した r12.2 の判定
final class H {
    /** @ANCHOR-20261018-MODECHECK  Sの値から mode を返す */
    public static function modeOfS(int $s): string
    {
        return ($s <= 1) ? 'FIRM' : (($s <= 3) ? 'MIXED' : 'VOLATILE');
    }

    /** @ANCHOR-20261018-MODECHECK  Sの値から星を返す（1〜5） */
    public static function starOfS(int $s): int
    {
        if ($s <= 0) return 1;
        if ($s === 1) return 2;
        if ($s <= 3) return 3;
        if ($s <= 5) return 4;
        return 5;
    }

    // ═════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-R122  r12.2（2026-10-20 受領の変更計画書v1.2・
    //   基本設計書v1.0・詳細設計書v1.0・server_contract_r12.2）の判定。
    //
    //   決まった3点をそのまま実装しています。
    //     ①X6はB案     … N>=7でnull、N<7でfalse。類似統計で代用しない
    //     ②取得猶予     … 6分前+30秒、21/18/15/12/9分前+120秒
    //     ③暫定配分     … 不明がX6だけで S0=1 ならMIXED、S0=3 ならVOLATILE
    //                      正式modeはUNKNOWNのまま、mode_status=PROVISIONAL
    //   根拠：server_contract_r12.2.txt 5章／詳細設計書 5.2・6章
    // ═════════════════════════════════════════════════════════════

    /** 信号の重み（S = 2X1 + X2 + X3 + X4 + X5 + X6 − 2Z） */
    const R122_W = ['X1'=>2,'X2'=>1,'X3'=>1,'X4'=>1,'X5'=>1,'X6'=>1,'Z'=>-2];

    /**
     * 三値の信号から r12.2 の mode 一式を返します。
     * $sig は ['X1'=>true|false|null, … ,'Z'=>…]。X6を省くと null 扱いです。
     */
    public static function r122Decide(array $sig): array
    {
        $keys = array_keys(self::R122_W);
        $unknown = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $sig)) { $sig[$k] = null; }
            if ($sig[$k] === null) { $unknown[] = $k; }
        }

        // 不明を 0/1 の全組合せで評価し、取り得る S を全部出します
        $svals = [];
        $u = count($unknown);
        for ($m = 0; $m < (1 << $u); $m++) {
            $sv = 0;
            foreach ($keys as $k) {
                $v = $sig[$k];
                if ($v === null) {
                    $i = array_search($k, $unknown, true);
                    $v = (bool) (($m >> $i) & 1);
                }
                if ($v) { $sv += self::R122_W[$k]; }
            }
            $svals[$sv] = true;
        }
        $svals = array_keys($svals);
        sort($svals);
        $sMin = $svals[0];
        $sMax = $svals[count($svals) - 1];

        $modes = []; $stars = [];
        foreach ($svals as $sv) {
            $modes[self::modeOfS($sv)] = true;
            $stars[self::starOfS($sv)] = true;
        }
        $modes = array_keys($modes);

        // S は不明が1つでもあれば null（S_min／S_max は残します）
        $S = empty($unknown) ? $sMin : null;

        // S0 = X6 を除いた重み付き合計。X6以外が全部確定しているときだけ出ます
        $s0 = 0; $s0ok = true;
        foreach ($keys as $k) {
            if ($k === 'X6') continue;
            if ($sig[$k] === null) { $s0ok = false; break; }
            if ($sig[$k]) { $s0 += self::R122_W[$k]; }
        }
        $S0 = $s0ok ? $s0 : null;

        $onlyX6 = (count($unknown) === 1 && $unknown[0] === 'X6');

        if (count($modes) === 1) {
            return [
                'mode'              => $modes[0],
                'mode_status'       => 'CONFIRMED',
                'allocation_mode'   => $modes[0],
                'mode_policy_id'    => 'BASE_TRI_STATE_V1',
                'selection_enabled' => true,
                'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
                'display_rating'    => (count($stars) === 1) ? array_keys($stars)[0] : null,
                'unknown_keys'      => $unknown,
            ];
        }
        if ($onlyX6 && ($S0 === 1 || $S0 === 3)) {
            return [
                'mode'              => 'UNKNOWN',
                'mode_status'       => 'PROVISIONAL',
                'allocation_mode'   => ($S0 === 1) ? 'MIXED' : 'VOLATILE',
                'mode_policy_id'    => 'X6_ONLY_UPPER_MODE_V1',
                'selection_enabled' => true,
                'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
                'display_rating'    => null,
                'unknown_keys'      => $unknown,
            ];
        }
        return [
            'mode'              => 'UNKNOWN',
            'mode_status'       => 'UNRESOLVED',
            'allocation_mode'   => null,
            'mode_policy_id'    => 'BASE_TRI_STATE_V1',
            'selection_enabled' => false,
            'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
            'display_rating'    => null,
            'unknown_keys'      => $unknown,
        ];
    }

    /** 目標頭数 C（詳細設計書6章） */
    public static function r122TargetCount(int $n): int
    {
        if ($n <= 0)  return 0;
        if ($n <= 3)  return $n;
        if ($n <= 8)  return 4;
        if ($n <= 13) return 5;
        if ($n <= 15) return 6;
        return 7;
    }

    /** 基本配分 A/B/D（config/r122_contract.json の base_quota と同じ値） */
    public static function r122BaseQuota(int $c, string $mode): ?array
    {
        if ($c <= 0) return [0, 0, 0];
        if ($c <= 3) return [$c, 0, 0];
        $t = [
            4 => ['FIRM'=>[3,1,0], 'MIXED'=>[2,2,0], 'VOLATILE'=>[2,1,1]],
            5 => ['FIRM'=>[4,1,0], 'MIXED'=>[3,2,0], 'VOLATILE'=>[2,2,1]],
            6 => ['FIRM'=>[4,2,0], 'MIXED'=>[3,2,1], 'VOLATILE'=>[3,2,1]],
            7 => ['FIRM'=>[5,2,0], 'MIXED'=>[3,3,1], 'VOLATILE'=>[3,3,1]],
        ];
        return $t[$c][$mode] ?? null;
    }

    /** 帯ごとの実在頭数（A=1〜6人気、B=7〜10人気、D=11人気以下） */
    public static function r122BandCapacity(int $n): array
    {
        return [max(0, min(6, $n)), max(0, min(10, $n) - 6), max(0, $n - 10)];
    }

    /**
     * 構造上の実在馬不足だけを調整します（評価後の適格馬不足は別の規則）。
     * 不足帯を D → B → A の順に処理し、D は B→A、B は D→A、A は B→D へ移します。
     * 移動は quota_adjustments として理由つきで返します（reason は省略しません）。
     */
    public static function r122StructuralQuota(int $n, ?string $allocMode): array
    {
        if ($allocMode === null) { return ['quota'=>null, 'moves'=>[]]; }
        $c = self::r122TargetCount($n);
        $q = self::r122BaseQuota($c, $allocMode);
        if ($q === null) { return ['quota'=>null, 'moves'=>[]]; }
        $cap = self::r122BandCapacity($n);
        $idx = ['A'=>0, 'B'=>1, 'D'=>2];
        $moves = [];
        foreach ([['D', ['B','A']], ['B', ['D','A']], ['A', ['B','D']]] as $pair) {
            [$from, $tos] = $pair;
            $i = $idx[$from];
            while ($q[$i] > $cap[$i]) {
                $moved = false;
                foreach ($tos as $to) {
                    $j = $idx[$to];
                    if ($q[$j] < $cap[$j]) {
                        $q[$i]--; $q[$j]++;
                        $moves[] = ['requested_band'=>$from, 'actual_band'=>$to,
                                    'count'=>1, 'reason'=>'BAND_CAPACITY'];
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) {
                    $q[$i]--;
                    $moves[] = ['requested_band'=>$from, 'actual_band'=>null,
                                'count'=>1, 'reason'=>'BAND_CAPACITY'];
                }
            }
        }
        return ['quota'=>$q, 'moves'=>$moves];
    }

    /** 2ndの配分：C<=5は1stと同じ。C>5は B・D を保ったまま A=5−B−D、cap=5 */
    public static function r122SecondQuota(int $n, array $q1): array
    {
        if (self::r122TargetCount($n) <= 5) { return $q1; }
        $a = 5 - $q1[1] - $q1[2];
        if ($a < 0) { $a = 0; }
        return [$a, $q1[1], $q1[2]];
    }

    /** 配分を "A/B/D" の形に */
    public static function r122Fmt(?array $q): string
    {
        return ($q === null) ? '—' : sprintf('%d/%d/%d', $q[0], $q[1], $q[2]);
    }

    // ── 取得時刻（server_contract_r12.2 4章／詳細設計書 4.3）────────

    /** スロットごとの取得猶予（ミリ秒）。ここに無い時点は予想入力に使いません */
    const R122_GRACE_MS = [21=>120000, 18=>120000, 15=>120000,
                                   12=>120000, 9=>120000, 6=>30000];

    /**
     * RFC3339（秒必須・小数秒0〜6桁・タイムゾーン必須）をマイクロ秒の整数へ。
     * 7桁以上や独自形式は切り捨てず null を返します（契約12章）。
     */
    public static function r122Us(?string $t): ?int
    {
        if ($t === null) { return null; }
        $re = '/^(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}:\d{2})(\.\d{1,6})?([Zz]|[+\-]\d{2}:\d{2})$/';
        if (!preg_match($re, $t, $m)) { return null; }
        $frac = isset($m[3]) ? str_pad(substr($m[3], 1), 6, '0') : '000000';
        $tz   = (strtoupper($m[4]) === 'Z') ? '+00:00' : $m[4];
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $m[1] . ' ' . $m[2] . $tz);
        if ($d === false) { return null; }
        return $d->getTimestamp() * 1000000 + (int) $frac;
    }

    /** 予定時刻（anchor_kind=POST_TIME。発走時刻からスロット分を引く） */
    public static function r122SlotScheduledUs(int $anchorUs, int $slotMin): int
    { return $anchorUs - $slotMin * 60 * 1000000; }

    public static function r122CutoffUs(int $anchorUs): int
    { return $anchorUs - 360 * 1000000; }

    public static function r122InputFreezeUs(int $anchorUs): int
    { return self::r122CutoffUs($anchorUs) + 30 * 1000000; }

    /**
     * 1バッチの採用可否。期限ちょうどは許容、1マイクロ秒でも後なら不採用。
     * 返りは ['ok'=>bool, 'reason'=>string|null]。
     */
    public static function r122AcceptBatch(int $slot, array $b): array
    {
        if (!isset(self::R122_GRACE_MS[$slot])) {
            return ['ok'=>false, 'reason'=>'予想に使わないスロット（21/18/15/12/9/6分前だけ）'];
        }
        $v = [];
        foreach (['slot_scheduled_at','request_started_at','response_received_at',
                  'available_at','input_freeze_at'] as $k) {
            $v[$k] = self::r122Us($b[$k] ?? null);
            if ($v[$k] === null) {
                return ['ok'=>false, 'reason'=>$k . ' が無いか日時として読めない'];
            }
        }
        $deadline = $v['slot_scheduled_at'] + self::R122_GRACE_MS[$slot] * 1000;
        if ($v['request_started_at']   < $v['slot_scheduled_at'])    return ['ok'=>false, 'reason'=>'要求開始が予定時刻より前'];
        if ($v['response_received_at'] < $v['request_started_at'])   return ['ok'=>false, 'reason'=>'受信完了が要求開始より前（時計逆行）'];
        if ($v['available_at']         < $v['response_received_at']) return ['ok'=>false, 'reason'=>'利用可能が受信完了より前（時計逆行）'];
        if ($v['available_at'] > $deadline)                          return ['ok'=>false, 'reason'=>'ACQUISITION_DEADLINE_EXCEEDED（取得猶予を超えた）'];
        if ($v['available_at'] > $v['input_freeze_at'])              return ['ok'=>false, 'reason'=>'情報上限 input_freeze_at を超えた'];
        if (($b['source_updated_at'] ?? null) !== null) {
            $su = self::r122Us($b['source_updated_at']);
            if ($su === null)                      return ['ok'=>false, 'reason'=>'source_updated_at が日時として読めない'];
            if ($su > $v['response_received_at'])  return ['ok'=>false, 'reason'=>'取得元の更新時刻が受信完了より未来'];
        }
        return ['ok'=>true, 'reason'=>null];
    }


}
