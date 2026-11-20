<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-PREDICTION-DECIMAL  値と型を一緒に運ぶ入れ物（）
 * ═══════════════════════════════════════════════════════════════════════════
 * ■ なぜ要るか
 *   前の版は「どの経路がJSON上の数値だったか」を<b>別の表（number_paths）</b>で
 *   持っていました。これだと、
 *     ・診断・訂正の比較・保存へ渡していなければ、そこで型が消える
 *     ・horses を馬番キーへ並べ替えると、経路がずれる
 *     ・値を直しても、経路の表は古い値のままなので、書き戻すと元へ戻る
 *     ・監査用の5,000件の制限で、型の保持まで打ち切られる
 *   という問題が残ります。10月21日009のご指摘のとおりです。
 *
 * ■ いま
 *   <b>値そのものを、この入れ物に入れます。</b>値と型が常に一緒に動くので、
 *   並べ替えても、別の関数へ渡しても、保存しても、離れません。
 *   5,000件の制限も関係ありません（表ではなく値だからです）。
 *
 * ■ 使う側から見た振る舞い
 *   ・`is_int()` は false。採点は整数と決まっているので、採点の検査が落とします
 *   ・`(string)` で十進数の原表現になります
 *   ・`json_encode` すると文字列になります。<b>原表現のまま数値で書き戻すときは
 *     `BaganrikiJson::reserialize()` を使ってください</b>
 *   ・`===` は同じ中身なら true になりません（オブジェクトなので）。
 *     比べるときは `BaganrikiJson::plain()` で素の形にしてください
 */
final class BaganrikiDecimal implements \JsonSerializable
{
    // ══════════════════════════════════════════════════════════════════
    // @ANCHOR-20261021-PREDICTION-PHP80  このサーバの artisan は PHP 8.0.30 です
    // ══════════════════════════════════════════════════════════════════
    //   `readonly` は PHP 8.1 からです。8.0 では構文エラーになります。
    //   コンストラクタのプロモーションは 8.0 で使えるので、そちらだけ使い、
    //   書き換え防止は「private + 読み出し用メソッド」で行います。
    //   戻り値型 `mixed` も 8.0 からなので、そのまま使えます。

    /** @param string $decimal 正確な十進値（指数を展開し、符号を正規化したもの） */
    public function __construct(
        private string $rawToken,
        private string $decimalValue
    ) {}

    public function raw(): string { return $this->rawToken; }
    public function decimal(): string { return $this->decimalValue; }

    public function __toString(): string { return $this->decimalValue; }

    /** json_encode したときは文字列になります（型を保ちたいなら reserialize） */
    public function jsonSerialize(): mixed { return $this->decimalValue; }
}
