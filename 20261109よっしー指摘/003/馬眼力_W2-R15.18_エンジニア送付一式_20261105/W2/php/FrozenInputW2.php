<?php
declare(strict_types=1);
namespace App\Services\Baganriki;

use App\Http\Controllers\Api\BaganrikiJson;
use App\Http\Controllers\Api\BaganrikiDecimal;
use InvalidArgumentException;
require_once __DIR__.'/MarketStateW2.php';
require_once __DIR__.'/SourceEvidenceW2.php';

/** Verified role input. Only fromStored() can build a snapshot from persisted exact JSON. */
final class FrozenInputW2
{
    private const ROLE_FIELDS=['snapshot_hash','ai_role','role_cap','role_quota'];

    private function __construct(private array $tree, private string $roleJson, private array $typeMap, private \DateTimeImmutable $readyAt, private bool $hasPreciseNumbers) {}

    /** Decode the saved role row for preliminary inspection. Final acceptance still requires fromStored and its independent proof. */
    public static function decodeForInspection(string $raw,?array &$typeMap=null):?array
    {
        $typeMap=null;
        $native=json_decode($raw,true);
        if (!is_array($native)) return null;
        if (!ScoreMaterialsW2::usesR14Rules($native['prompt_version']??null)) return $native;
        $exact=BaganrikiJson::decode($raw);
        if (!$exact['ok'] || !is_array($exact['value'])) return null;
        $typeMap=$exact['map'];
        return self::typedValues($exact);
    }

    /** The submitted strict decoder retains an inexact JSON number as text plus its original token in the type map. */
    public static function typedValues(array $decoded):array
    {
        $tree=$decoded['value'];
        if (!($decoded['stats']['inexact']??0)) return $tree;
        foreach ($decoded['map']['num'] as $encodedPath=>$token) {
            if ($token['exact']) continue;
            $value=&$tree;
            // The path was created by the strict decoder. Numeric object keys retain their identity.
            foreach (json_decode($encodedPath,true,512,JSON_THROW_ON_ERROR) as $part) $value=&$value[$part];
            $value=new BaganrikiDecimal($token['raw'],$token['decimal']);
            unset($value);
        }
        return $tree;
    }

    /** Retain original number tokens when projecting a typed snapshot into the compressed transport. */
    public function transportJson(array $tree):string
    {
        return $this->hasPreciseNumbers ? BaganrikiJson::encode(self::sorted($tree),[]) : self::nativeJson($tree);
    }

    public function readyAt(): \DateTimeImmutable { return $this->readyAt; }
    public function raw(): string { return $this->roleJson; }

    public function sourceValue(string $ref):array
    {
        return SchemaW2::scalar($this->tree['source_registry'][$ref]['value'],$this->typeMap,['source_registry',$ref,'value']);
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields=(array)$value; ksort($fields,SORT_STRING);
            return (object) array_map([self::class,'sorted'],$fields);
        }
        if (!is_array($value)) return $value;
        if ($value!==[] && array_keys($value)!==range(0,count($value)-1)) ksort($value,SORT_STRING);
        foreach ($value as $key=>$child) $value[$key]=self::sorted($child);
        return $value;
    }

    /** Canonical bytes for native, lossless PHP scalar trees only. Use fromStored() for original JSON. */
    public static function nativeJson(array $tree): string
    {
        if (ini_get('serialize_precision')!=='-1') throw new InvalidArgumentException('W2_NATIVE_PRECISION');
        return json_encode(self::sorted($tree),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private static function common(array $tree): array
    {
        foreach (self::ROLE_FIELDS as $key) unset($tree[$key]);
        return $tree;
    }

    public static function checked(self|array $input): array
    {
        if ($input instanceof self) return $input->tree;
        $hash=$input['snapshot_hash']??null;
        if (!is_string($hash) || ($input['snapshot_hash_version']??null)!=='exact-common-payload-sha256-r8' ||
            !hash_equals(hash('sha256',self::nativeJson(self::common($input))),$hash))
            throw new InvalidArgumentException('W2_SNAPSHOT_HASH');
        // Bound the cache to one already-rehashed common payload in this worker.
        static $verifiedCommon=null;
        if ($verifiedCommon!==$hash) {
            MarketStateW2::verify($input);
            $verifiedCommon=$hash;
        }
        return $input;
    }

    /** Compare the separately saved calculator result by horse identity, retaining exact JSON types and decimals. */
    private static function verifyExpected(array $wire,array $wireMap,mixed $proof):array
    {
        if (!is_array($proof) || !is_string($proof['json']??null) || !is_string($proof['sha256']??null))
            throw new InvalidArgumentException('W6_E_PROOF_REQUIRED');
        $raw=$proof['json'];
        if (strlen($raw)>4194304 || !hash_equals(hash('sha256',$raw),$proof['sha256'])) throw new InvalidArgumentException('W6_E_PROOF_HASH');
        $decoded=BaganrikiJson::decode($raw);
        if (!$decoded['ok'] || !is_array($decoded['value'])) throw new InvalidArgumentException('W6_E_PROOF_JSON');
        $original=$decoded['value'];$originMap=$decoded['map'];
        $a=$original['rows']??null;$b=$wire['rows'];
        if (!is_array($a) || SchemaW2::scalar($a,$originMap,['rows'])[0]!=='array' || count($a)!==count($b))
            throw new InvalidArgumentException('W6_E_PROOF_ROSTER');
        $oddsBinding=$original['odds_binding_sha256']??null;
        if (!is_string($oddsBinding) || preg_match('/^[0-9a-f]{64}$/D',$oddsBinding)!==1) throw new InvalidArgumentException('W7_E_ODDS_BINDING');
        $marketProof=$original['market_source_sha256']??null;
        if (!is_string($marketProof) || preg_match('/^[0-9a-f]{64}$/D',$marketProof)!==1) throw new InvalidArgumentException('W8_MARKET_PROOF_REQUIRED');
        unset($original['odds_binding_sha256'],$original['market_source_sha256']);
        unset($original['rows'],$wire['rows']);
        foreach (['input_freeze_at','history_before'] as $key) {
            if (ExpectedPacketW2::instant($original[$key]??null)!=ExpectedPacketW2::instant($wire[$key]??null))
                throw new InvalidArgumentException('W6_E_PROOF_HEADER');
            unset($original[$key],$wire[$key]);
        }
        if (!BaganrikiJson::same($original,$originMap,$wire,$wireMap,false)['same'])
            throw new InvalidArgumentException('W6_E_PROOF_HEADER');
        $positions=[];
        foreach ($a as $pos=>$row) {
            $no=is_array($row)?($row['horse_no']??null):null;
            if (!is_int($no) || isset($positions[$no])) throw new InvalidArgumentException('W6_E_PROOF_ROSTER');
            $positions[$no]=$pos;
        }
        foreach ($b as $pos=>$row) {
            $no=$row['horse_no'];
            if (!isset($positions[$no])) throw new InvalidArgumentException('W6_E_PROOF_ROSTER');
            $src=$positions[$no];unset($positions[$no]);
            $am=BaganrikiJson::mapSub($originMap,['rows',$src]);$bm=BaganrikiJson::mapSub($wireMap,['rows',$pos]);
            if (!BaganrikiJson::same($a[$src],$am,$row,$bm,false)['same'])
                throw new InvalidArgumentException('W6_E_PROOF_ROW_'.$no);
        }
        return [$oddsBinding,$marketProof];
    }

    /** Validate the same original common bytes, role fields and JSON types at both boundaries. */
    private static function boundRole(string $commonJson,string $roleJson,string $savedHash,string $role):array
    {
        if (!in_array($role,['1st','2nd'],true) || !hash_equals(hash('sha256',$commonJson),$savedHash))
            throw new InvalidArgumentException('W2_STORED_ID');
        $common=BaganrikiJson::decode($commonJson); $input=BaganrikiJson::decode($roleJson);
        if (!$common['ok'] || !$input['ok'] || !is_array($common['value']) || !is_array($input['value']))
            throw new InvalidArgumentException('W2_STORED_JSON');
        $tree=$input['value'];
        foreach (self::ROLE_FIELDS as $k) {
            if (array_key_exists($k,$common['value']) || !array_key_exists($k,$tree))
                throw new InvalidArgumentException('W2_ROLE_FIELDS');
        }
        if ($tree['snapshot_hash']!==$savedHash || $tree['ai_role']!==$role ||
            ($tree['snapshot_hash_version']??null)!=='exact-common-payload-sha256-r8' ||
            ($tree['prompt_version']??null)!==CandidateSelectionW2::VERSION || ($tree['schema_version']??null)!==CandidateSelectionW2::SCHEMA)
            throw new InvalidArgumentException('W2_ROLE_ID');
        $cmp=BaganrikiJson::same($common['value'],$common['map'],self::common($tree),$input['map'],true);
        if (!$cmp['same']) throw new InvalidArgumentException('W2_STORED_COMMON_MISMATCH');
        SchemaW2::validate('input',$tree,$input['map']);
        return $input;
    }

    /** Bind the saved row to its reserved role before diagnosing a response or restoring its ranks. */
    public static function forInspection(object $snapshot,string $role,?array &$types=null):?array
    {
        if (!in_array($role,['1st','2nd'],true)) throw new InvalidArgumentException('W15_STORED_ROLE');
        $column=$role==='1st'?'input_json_1st':'input_json_2nd';$raw=(string)($snapshot->$column??'');
        if (($snapshot->release_version??null)===CandidateSelectionW2::VERSION)
            return self::inspectStoredRole((string)($snapshot->common_json??''),$raw,(string)($snapshot->snapshot_hash??''),$role,(string)($snapshot->race_id??''),$types);
        $input=self::decodeForInspection($raw,$types);
        if (($input['prompt_version']??null)===CandidateSelectionW2::VERSION) throw new InvalidArgumentException('W15_STORED_VERSION');
        return $input;
    }

    public static function inspectStoredRole(string $commonJson,string $roleJson,string $savedHash,string $role,string $race,?array &$typeMap):array
    {
        $input=self::boundRole($commonJson,$roleJson,$savedHash,$role);
        if ($input['value']['race_id']!==$race) throw new InvalidArgumentException('W15_STORED_RACE');
        $tree=self::typedValues($input);
        CandidateSelectionW2::checkInspectionInput($tree);
        $typeMap=$input['map'];
        return $tree;
    }

    /** $savedHash and $role belong to the server's selected snapshot/job, separately from AI text. */
    public static function fromStored(string $commonJson,string $roleJson,string $savedHash,string $role,mixed $savedExpectedProof=null): self
    {
        $input=self::boundRole($commonJson,$roleJson,$savedHash,$role);
        $tree=$input['value'];
        [$oddsBinding,$marketProof]=self::verifyExpected($tree['expected_v01'],BaganrikiJson::mapSub($input['map'],['expected_v01']),$savedExpectedProof);
        $tree=self::typedValues($input);
        $ready=SourceEvidenceW2::input($tree,$input['map']);
        $actualMarket=MarketStateW2::verify($tree);
        if (!hash_equals($oddsBinding,ExpectedPacketW2::bindingForFrozen($tree))) throw new InvalidArgumentException('W7_E_ODDS_BINDING');
        if (!hash_equals($marketProof,$actualMarket)) throw new InvalidArgumentException('W8_MARKET_SOURCE_MISMATCH');
        $frozen=new self($tree,$roleJson,$input['map'],$ready,($input['stats']['inexact']??0)>0);
        CandidateSelectionW2::validateInput($frozen);
        return $frozen;
    }
}
