<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude API への送信を共通化するサービス
 *
 * 【使い方】
 *
 *   // 単発送信（BaganrikiBrain など）
 *   $response = $this->anthropic->send($prompt);
 *
 *   // 単発送信 + 429/529 リトライ（ApiController: system付き、timeout短め）
 *   $response = $this->anthropic->sendWithRetry($prompt, system: $brain, sleepBase: 2, timeout: 30);
 *
 *   // pool並列送信（ForecastFromLastRace / RacesIntrospection: バッチ単位で使う）
 *   $responses = $this->anthropic->sendPool($prompts);
 *
 *   // リトライ単発（pool後の 429/529 リトライに使う: sleepBase=10）
 *   $response = $this->anthropic->sendWithRetry($prompt, sleepBase: 10);
 *
 *   // レスポンスからテキスト取り出し
 *   $text = $this->anthropic->extractText($response);
 */
class AnthropicService
{
    private const API_URL     = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MODEL       = 'claude-haiku-4-5';
    private const MAX_TOKENS  = 4096;
    private const TIMEOUT     = 120;

    /**
     * 単発送信（リトライなし）
     */
    public function send(string $prompt, ?string $system = null, int $timeout = self::TIMEOUT): Response
    {
        return Http::withHeaders($this->headers())
            ->timeout($timeout)
            ->post(self::API_URL, $this->payload($prompt, $system));
    }

    /**
     * 単発送信 + 429/529 時に指数バックオフでリトライ
     *
     * @param  int  $maxAttempts  最大試行回数（デフォルト3）
     * @param  int  $sleepBase    バックオフ基数（秒）: sleep($attempt * $sleepBase)
     *                            ApiController: 2 / Commands リトライ: 10
     */
    public function sendWithRetry(
        string  $prompt,
        ?string $system      = null,
        int     $maxAttempts = 3,
        int     $sleepBase   = 2,
        int     $timeout     = self::TIMEOUT
    ): Response {
        $response = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->send($prompt, $system, $timeout);

            if (!in_array($response->status(), [429, 529])) {
                break;
            }

            if ($attempt < $maxAttempts) {
                sleep($attempt * $sleepBase);
            }
        }
        return $response;
    }

    /**
     * 複数プロンプトを Http::pool() で並列送信する
     *
     * @param  string[]  $prompts  プロンプト配列（インデックス順で結果が返る）
     * @return array<int, Response|\Throwable>
     */
    public function sendPool(array $prompts, ?string $system = null, int $timeout = self::TIMEOUT): array
    {
        $headers = $this->headers();
        return Http::pool(function ($pool) use ($prompts, $headers, $system, $timeout) {
            return array_map(
                fn(string $prompt) => $pool
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->post(self::API_URL, $this->payload($prompt, $system)),
                $prompts
            );
        });
    }

    /**
     * レスポンスからテキストを取り出す
     */
    public function extractText(Response $response): string
    {
        return trim($response->json('content.0.text') ?? '');
    }

    // ────────────────────────────────────────────────────────────────────────

    private function headers(): array
    {
        return [
            'x-api-key'         => config('services.anthropic.api_key'),
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];
    }

    private function payload(string $prompt, ?string $system = null): array
    {
        $payload = [
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($system !== null && $system !== '') {
            $payload['system'] = $system;
        }
        return $payload;
    }
}
