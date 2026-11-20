<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/** Commit these per-role digests with the authoritative receipt/adoption rows and response_revision. */
final class BaganrikiRoleJournal
{
    private static function digest(array $row):string
    {
        try { return hash('sha256',BaganrikiFrozenInput::nativeJson($row)); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('EXPECTED_JOURNAL_DATA',0,$error); }
    }

    private static function receipt(mixed $row):string
    {
        if (!is_array($row)) return self::digest(['malformed',$row]);
        foreach (['complete','original_approved'] as $field) {
            $v=$row[$field]??null;
            if (in_array($v,[false,0,'0'],true)) $row[$field]=false;
            elseif (in_array($v,[true,1,'1'],true)) $row[$field]=true;
        }
        if (in_array($row['attempt_no']??null,['0','1'],true)) $row['attempt_no']=(int)$row['attempt_no'];
        foreach (['sent_at','received_at'] as $key) if (is_string($row[$key]??null)) {
            try { $row[$key]=BaganrikiExpectedPacket::utc(BaganrikiExpectedPacket::instant($row[$key])); }
            catch (\InvalidArgumentException $invalid) { /* Keep the erroneous value as its own journal state. */ }
        }
        if (is_array($row['repair_scope']??null)) foreach (['horses','rank_bands','presentation_horses','summary_horses'] as $field) {
            $v=$row['repair_scope'][$field]??null;
            if (is_array($v) && BaganrikiExpectedPacket::isList($v) && !array_filter($v,static fn($n)=>!is_int($n) && !is_string($n))) {
                sort($v);$row['repair_scope'][$field]=$v;
            }
        }
        if (is_string($row['raw']??null)) $row['raw']=hash('sha256',$row['raw']);
        if (is_array($row['sent_request']??null)) foreach (['system_text','user_json'] as $k)
            if (is_string($row['sent_request'][$k]??null)) $row['sent_request'][$k]=hash('sha256',$row['sent_request'][$k]);
        return self::digest($row);
    }

    public static function witness(BaganrikiFrozenInput $snapshot,array $records,array $proof):array
    {
        $in=BaganrikiFrozenInput::checked($snapshot);$out=[];
        foreach (['1st','2nd'] as $role) {
            $record=$records[$role]??null;
            if ($record===null) { $out[$role]=null;continue; }
            if (!is_array($record)) { $out[$role]=self::digest(['invalid',$role,$record]);continue; }
            $f=$record['input']??null;
            if ($f instanceof BaganrikiFrozenInput) $record['input']=hash('sha256',$f->raw());
            if (is_string($record['recovery_json']??null)) $record['recovery_json']=hash('sha256',$record['recovery_json']);
            if (is_array($record['receipts']??null)) {
                $rows=array_map([self::class,'receipt'],$record['receipts']);sort($rows,SORT_STRING);$record['receipts']=$rows;
            }
            $votes=[];
            foreach ($in['runners'] as $runner) {
                $n=$runner['horse_no'];
                $a=is_array($proof[$role]??null)?($proof[$role][$n]??null):null;
                $b=is_array($proof[$n]??null)?($proof[$n][$role]??null):null;
                $v=$a??$b;
                if (($a===null||$b===null||$a===$b) && is_string($v) && preg_match('/^[a-f0-9]{64}$/D',$v)===1) $votes[$n]=$v;
            }
            ksort($votes,SORT_NUMERIC);
            $out[$role]=self::digest(['EXPECTED_ROLE_JOURNAL_V1',$in['race_id'],$in['snapshot_hash'],$role,$record,$votes]);
        }
        return $out;
    }

    public static function read(mixed $saved):array
    {
        if (!is_array($saved) || count($saved)!==2) throw new \InvalidArgumentException('EXPECTED_JOURNAL_REQUIRED');
        $out=[];
        foreach (['1st','2nd'] as $role) {
            if (!array_key_exists($role,$saved) || ($saved[$role]!==null && (!is_string($saved[$role]) ||
                preg_match('/^[a-f0-9]{64}$/D',$saved[$role])!==1))) throw new \InvalidArgumentException('EXPECTED_JOURNAL_REQUIRED');
            $out[$role]=$saved[$role];
        }
        return $out;
    }
}
