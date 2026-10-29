<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * t_horse_odds_finder_horse_scores の馬名で JBIS-サーチを検索し、
 * 父馬(father)・母馬(mother)を取り込む。
 *
 * finish の意味
 *   0 : 未処理（このコマンドの対象）
 *   1 : 取得済み
 *   2 : JBIS に該当馬なし（再検索しない）
 *   ※通信エラーのときは 0 のまま残し、次回の実行で再挑戦する
 *
 * 毎晩 cron で回す前提
 *   ・対象（finish が 0 または NULL）が 0 件なら、JBIS へアクセスせずにすぐ終わる
 *   ・前回の実行がまだ動いている間は、二重に動かずに終わる（MySQL の GET_LOCK を使用。ファイルは作らない）
 */
class importHorsePedigree extends Command
{
    protected $signature = 'keiba:importHorsePedigree {--limit=0 : 処理する件数（0は全件）} {--sleep=2 : 1件ごとの待ち秒数}';

    protected $description = 'JBIS-サーチから馬の父・母を取得して t_horse_odds_finder_horse_scores に保存する';

    private const SEARCH_URL = 'https://www.jbis.or.jp/horse/result/';

    // 通信エラーがこの回数連続したら止める（相手側の障害・遮断を想定）
    private const MAX_CONSECUTIVE_ERRORS = 5;

    // 二重起動防止用のロック名
    private const LOCK_NAME = 'keiba_importHorsePedigree';

    // developerNews 通知用（_run の中で更新する）
    private string $status = '不明な理由で終了';
    private string $news = '';

    public function handle()
    {
        DB::disableQueryLog();

        $now = microtime(true);

        $this->info('開始 ' . now()->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s') . '（JST）');

        try {
            // 二重起動防止：前回の実行が終わっていなければ何もしないで終わる
            $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS got', [self::LOCK_NAME]);
            if (!$lock || (int) $lock->got !== 1) {
                $this->warn('前回の実行がまだ動いているため終了します');
                $this->status = 'SKIP（前回の実行が継続中）';
                return 0;
            }

            try {
                $this->_run();
            } finally {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
            }
        } catch (\Throwable $e) {
            $this->status = '異常終了';
            $this->news = $e->getMessage();
            $this->error('異常終了: ' . $e->getMessage());
        } finally {
            // developerNews 通知（t_horse_odds_finder_push_send_logs への記録は WebPushService 側で行われる）
            $elapsed = round(microtime(true) - $now, 1);
            $body = "ImportHorsePedigree::handle\n{$this->status}";
            if ($this->news !== '') {
                $body .= "\n{$this->news}";
            }
            $body .= " / {$elapsed}秒";

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', $body);
        }

        return 0;
    }

    private function _run(): void
    {
        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');

        $query = DB::table('t_horse_odds_finder_horse_scores')
            ->where(function ($q) {
                $q->where('finish', 0)
                    ->orWhereNull('finish');
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $horseList = $query->get(['id', 'name']);

        $this->info('対象件数: ' . $horseList->count());

        // 対象なし（毎晩のほとんどのケース）は JBIS へアクセスせずに終わる
        if ($horseList->isEmpty()) {
            $this->info('対象がないため終了します');
            $this->status = 'SKIP';
            return;
        }

        $okCount = 0;
        $notFoundCount = 0;
        $errorCount = 0;
        $consecutiveErrors = 0;
        $isAborted = false;

        foreach ($horseList as $horse) {
            $name = trim((string) $horse->name);

            if ($name === '') {
                DB::table('t_horse_odds_finder_horse_scores')
                    ->where('id', $horse->id)
                    ->update(['finish' => 2]);
                $notFoundCount++;
                continue;
            }

            $html = $this->_fetchSearchResult($name);

            if ($html === null) {
                $errorCount++;
                $consecutiveErrors++;
                $this->error("通信エラー id={$horse->id} name={$name}");

                if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                    $this->error('通信エラーが連続したため中断します');
                    $isAborted = true;
                    break;
                }

                sleep($sleep);
                continue;
            }

            $consecutiveErrors = 0;

            $pedigree = $this->_parsePedigree($html, $name);

            if ($pedigree === null) {
                DB::table('t_horse_odds_finder_horse_scores')
                    ->where('id', $horse->id)
                    ->update(['finish' => 2]);
                $notFoundCount++;
                $this->warn("該当なし id={$horse->id} name={$name}");
            } else {
                // 名前も条件に入れる（万一 summaryCalculateHorseScore の TRUNCATE と重なって id が振り直されても、別の馬に書かないため）
                DB::table('t_horse_odds_finder_horse_scores')
                    ->where('id', $horse->id)
                    ->where('name', $horse->name)
                    ->update([
                        'father' => $pedigree['father'],
                        'mother' => $pedigree['mother'],
                        'finish' => 1,
                    ]);
                $okCount++;
                $this->line("OK id={$horse->id} {$name} 父:{$pedigree['father']} 母:{$pedigree['mother']}");
            }

            sleep($sleep);
        }

        $this->info("完了 取得:{$okCount} 該当なし:{$notFoundCount} 通信エラー:{$errorCount}");

        $this->status = $isAborted ? '中断（通信エラー連続）' : '正常終了';
        $this->news = "対象:{$horseList->count()}件、取得:{$okCount}件、該当なし:{$notFoundCount}件、通信エラー:{$errorCount}件";
    }

    /**
     * JBIS の馬名検索（完全一致）の HTML を返す。失敗時は null。
     */
    private function _fetchSearchResult(string $name): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
                    'Accept-Language' => 'ja',
                ])
                ->get(self::SEARCH_URL, [
                    'sid' => 'horse',
                    'keyword' => $name,
                    'match' => 'exact',
                ]);

            if (!$response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 検索結果の表から、馬名が完全一致する行の父・母を取り出す。
     * 同名馬が複数いた場合は生年が一番新しい馬を採用する（現役馬のため）。
     * 該当なしは null。
     */
    private function _parsePedigree(string $html, string $name): ?array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // 1行目は見出し行なので除外
        $rows = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' data-6-1 ')]/div[position() > 1]");

        if ($rows === false || $rows->length === 0) {
            return null;
        }

        $result = null;
        $resultYear = -1;

        foreach ($rows as $row) {
            // 列：0馬名 / 1生年 / 2性別 / 3調教師 / 4父馬 / 5母馬 / 6総賞金
            $cols = $xpath->query('./div', $row);

            if ($cols === false || $cols->length < 6) {
                continue;
            }

            $nameLink = $xpath->query('.//a[starts-with(@href, "/horse/")]', $cols->item(0))->item(0);
            $rowName = $nameLink ? trim($nameLink->textContent) : '';

            if ($rowName !== $name) {
                continue;
            }

            $year = (int) trim($cols->item(1)->textContent);
            $father = trim($cols->item(4)->textContent);
            $mother = trim($cols->item(5)->textContent);

            if ($year > $resultYear) {
                $resultYear = $year;
                $result = [
                    'father' => $father,
                    'mother' => $mother,
                ];
            }
        }

        return $result;
    }
}
