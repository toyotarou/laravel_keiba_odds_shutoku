<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * @ANCHOR-20261021-PREDICTION-EMPTYOBJ  空の {} と空の [] を区別する入れ物
 *   json_decode(..., true) はどちらも空配列にします。
 *   書き戻すときに {} が [] になってしまうので、空の {} だけ印を付けます
 *   （10月21日009のご指摘4(a)）。
 */
final class BaganrikiEmptyObject implements \JsonSerializable
{
    public function jsonSerialize(): mixed { return new \stdClass(); }
}
