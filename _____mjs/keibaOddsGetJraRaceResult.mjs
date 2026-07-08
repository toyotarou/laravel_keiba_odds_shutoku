/**
 * keibaOddsGetJraRaceResult.mjs
 *
 * 【概要】
 *   JRA公式サイトから直近の開催（最大6件）のレース結果（着順・騎手）を取得する。
 *
 * 【スクレイピングフロー】
 *   Step A: JRAトップ → 「レース結果」メニュークリック → 開催一覧ページへ
 *   Step B: 開催一覧ページから開催リンクを最大6件収集する
 *   Step C: 各開催の「全てのレースを表示」クリック後、
 *           全レースの着順データをまとめてパースする
 *
 * 【設計上の注意】
 *   ・「印刷用ページ」リンクの onclick に埋め込まれたコードから
 *     レース番号を取得する（tr 要素に直接レース番号が無いため）
 *   ・着順=1 の行が2回目に出現したらレースが切り替わったと判断する
 *
 * 【標準出力 (JSON)】
 *   {
 *     "results": [
 *       { kaisuu, basho, day, race, rank, horse_num, horse_name, jockey },
 *       ...
 *     ]
 *   }
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
//   - chromium: Playwright ヘッドレスブラウザ
//   - fs 系: ロックファイル・ログファイルの操作
//   - url/path: ESModules での __dirname 相当
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】ログ・ロックファイルの設定
//   logStream: flags: 'w' = 上書きモード（毎回クリアして新しいログを記録）
//   lockFile : 二重起動防止ファイル（このスクリプト固有の単一ロック）
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetJraRaceResult.log'), { flags: 'w' }); // 上書きモード
const lockFile  = join(__dirname, 'keibaOddsGetJraRaceResult.lock');

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line); // stdout は JSON 専用のため stderr へ
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms)); // 待機ユーティリティ

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {

    // ─────────────────────────────────────────────────────────
    // 【ブロック 4】二重起動防止
    //   このスクリプトは引数を取らないため、ロックキーは固定の1つ。
    //   すでにロックファイルがあれば空の結果を返して即終了する。
    //   ※ このスクリプトではスタールロックチェック（PID 生存確認）は
    //      行わないシンプル版（ファイルが残っていたら常に起動中とみなす）
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        log('[LOCK] 既に起動中のため終了します');
        console.log(JSON.stringify({ results: [] }));
        logStream.end();
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid)); // 現在の PID を書き込む
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {

        log('================================================================');
        log('keibaOddsGetJraRaceResult 開始');
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 5】ブラウザ起動
        //   headless モードでウィンドウを表示せず起動する。
        //   context と page を別々に作成することで viewport 設定が可能。
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'], // Linux サンドボックスエラー回避
        });
        const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const page = await context.newPage();

        // ─────────────────────────────────────────────────────
        // 【ブロック 6】Step A: JRAトップ → 「レース結果」ページへ
        //   JRAトップの「レース結果」リンクの onclick には
        //   "pw01sli00" を含む文字列が設定されているため、
        //   それをキーにリンクを特定してクリックする。
        // ─────────────────────────────────────────────────────
        log('[Step A] JRAトップページにアクセス中...');
        await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
        await sleep(3000); // 広告や遅延ロードが完了するまで待つ

        log('[Step A] 「レース結果」メニューをクリック...');
        await page.evaluate(() => {
            [...document.querySelectorAll('a')]
                .find(a => a.getAttribute('onclick')?.includes("accessS.html','pw01sli00"))
                ?.click();
        });
        await page.waitForLoadState('networkidle', { timeout: 30000 });
        await sleep(3000);

        // ─────────────────────────────────────────────────────
        // 【ブロック 7】Step B: 開催リンクの収集（最大6件）
        //   開催リンクのテキストは "1回東京1日" 等の形式なので
        //   正規表現でパースして kaisuu・basho・day を取得する。
        //   先頭6件に絞るのは、過去開催が大量にある場合に処理を短縮するため。
        // ─────────────────────────────────────────────────────
        const kaisaiLinks = await page.evaluate(() => {
            const links = [];
            document.querySelectorAll('a').forEach(a => {
                // "X回Y場Z日" のパターンに一致するリンクのみ収集
                const m = a.textContent.trim().match(/^(\d+)回(.+?)(\d+)日$/);
                if (!m) return;
                links.push({
                    label:   a.textContent.trim(),   // 表示テキスト全体
                    kaisuu:  Number(m[1]),            // 開催回数（数値）
                    basho:   m[2],                   // 開催場所（文字列）
                    day:     Number(m[3]),            // 開催日次（数値）
                    onclick: a.getAttribute('onclick') ?? '', // クリック後に再利用するため保持
                });
            });
            return links;
        });

        // 先頭6件のみを処理対象とする（古い開催が大量にある場合の負荷軽減）
        const targetKaisai = kaisaiLinks.slice(0, 6);
        log(`[Step B] 開催リンク総数: ${kaisaiLinks.length} 件 → 先頭 ${targetKaisai.length} 件を対象`);
        targetKaisai.forEach(k => log(`  → ${k.label}`));

        if (targetKaisai.length === 0) {
            log('[Step B] 対象開催なし。終了します。');
            console.log(JSON.stringify({ results: [] }));
            return;
        }

        const allResults = []; // 全開催分の着順データを格納する配列

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】Step C: 各開催の処理ループ
        //   各開催につき以下の3ステップを繰り返す:
        //     (8-1) 開催リンクをクリック → レース一覧へ
        //     (8-2) 「全てのレースを表示」をクリック → 全レース展開
        //     (8-3) DOM をパースして着順データを収集 → allResults へ追加
        // ─────────────────────────────────────────────────────
        for (const kaisai of targetKaisai) {
            log('');
            log(`----------------------------------------------------------------`);
            log(`[Step C] ${kaisai.label} 処理開始`);
            log(`----------------------------------------------------------------`);

            // (8-1) 開催リンクをクリック
            //       onclick 属性が完全一致する <a> を探してクリックする
            const clicked = await page.evaluate((onclick) => {
                const a = [...document.querySelectorAll('a')]
                    .find(el => el.getAttribute('onclick') === onclick);
                if (!a) return false;
                a.click();
                return true;
            }, kaisai.onclick);

            if (!clicked) {
                log(`  → クリック失敗。スキップ。`);
                continue;
            }
            await page.waitForLoadState('networkidle', { timeout: 30000 });
            await sleep(2000);

            // (8-2) 「全てのレースを表示」をクリック
            //       ボタンが存在しない場合（既に全件表示中）は null を返す
            const allRaceOnclick = await page.evaluate(() => {
                const a = [...document.querySelectorAll('a')]
                    .find(el => el.textContent.trim().includes('全てのレースを表示'));
                if (!a) return null;
                a.click();
                return a.getAttribute('onclick'); // クリックした onclick 属性をログ用に返す
            });

            if (!allRaceOnclick) {
                // 「全てのレースを表示」がない場合は開催一覧へ戻ってスキップ
                log(`  → 「全てのレースを表示」が見つかりません。スキップ。`);
                await page.evaluate(() => {
                    [...document.querySelectorAll('a')]
                        .find(a => a.getAttribute('onclick')?.includes("pw01sli00"))?.click();
                });
                await page.waitForLoadState('networkidle', { timeout: 30000 });
                await sleep(2000);
                continue;
            }

            log(`  「全てのレースを表示」クリック: ${allRaceOnclick}`);
            await page.waitForLoadState('networkidle', { timeout: 30000 });
            await sleep(2000);

            // (8-3) DOM をパースして着順データを収集
            const raceData = await page.evaluate(({ kaisuu, basho, day }) => {
                const results = [];

                // ─────────────────────────────────────────────
                // レース番号リストの収集:
                //   「印刷用ページ」リンクの onclick 属性には
                //   "pw01spr{YYYY}{MM}{DD}{RACE_NUM}..." 形式のコードが含まれる。
                //   このコードからレース番号（2桁）を抽出して順番にリスト化する。
                //   例: "pw01spr20230115010501" → basho=05, race=05(5R)
                //   コード構造: pw01spr + 年(4) + 月(2) + 日(2) + 場所(2) + レース(2) + 固有コード(8)
                // ─────────────────────────────────────────────
                const raceNums = [];
                [...document.querySelectorAll('a')].forEach(a => {
                    if (a.textContent.trim() !== '印刷用ページ') return;
                    const oc = a.getAttribute('onclick') ?? '';
                    // レース番号は19〜20文字目（0インデックスで substring(19,21)）
                    const m = oc.match(/pw01spr\d{4}(\d{4})(\d{2})(\d{2})(\d{2})\d{8}/);
                    if (m) raceNums.push(parseInt(m[4], 10)); // m[4] がレース番号
                });

                if (raceNums.length === 0) return []; // レース番号が取れなければ空を返す

                let raceIdx   = 0;                 // 現在処理中のレースインデックス
                let currentRace = raceNums[0];     // 現在のレース番号
                let firstOfRace = true;            // そのレースで最初の行かどうか

                const rows = [...document.querySelectorAll('tbody tr')];

                for (const tr of rows) {
                    const cells = [...tr.querySelectorAll('td')];
                    if (cells.length < 4) continue; // 列数が足りない行はスキップ

                    const rankText = cells[0]?.textContent.trim();
                    const rank = parseInt(rankText, 10);
                    // 着順は 1〜28 の整数のみを有効とする（除外・中止等の文字列は除外）
                    if (isNaN(rank) || rank < 1 || rank > 28) continue;

                    // 着順=1 が2回目に出現 → 次のレースへ切り替わった判断
                    if (rank === 1 && !firstOfRace) {
                        raceIdx++;
                        if (raceIdx >= raceNums.length) break; // 全レース処理済み
                        currentRace = raceNums[raceIdx];
                    }
                    if (rank === 1) firstOfRace = false; // 1着が出たらフラグを下げる

                    const horseNum  = parseInt(cells[2]?.textContent.trim(), 10) || null;
                    const horseName = cells[3]?.textContent.replace(/\s+/g, '').trim() ?? '';

                    // 騎手: accessK.html へのリンクのテキストから取得
                    //       リンクがある理由: 騎手名は別ページへの <a> で囲まれているため
                    const jockeyLink = [...tr.querySelectorAll('a')]
                        .find(a => a.getAttribute('onclick')?.includes('accessK.html'));
                    const jockey = jockeyLink?.textContent.replace(/\s+/g, '').trim() ?? '';

                    if (!horseNum || !horseName) continue; // 馬番・馬名が取れない行はスキップ

                    results.push({
                        kaisuu,
                        basho,
                        day,
                        race:       currentRace,
                        rank,
                        horse_num:  horseNum,
                        horse_name: horseName,
                        jockey,
                    });
                }

                return results;
            }, { kaisuu: kaisai.kaisuu, basho: kaisai.basho, day: kaisai.day });

            log(`  → ${raceData.length} 件取得`);

            // ─────────────────────────────────────────────────
            // 【ブロック 9】レース別ログ出力
            //   raceData を race 番号でグルーピングし、
            //   各レースの頭数と上位3頭を詳細ログに記録する。
            // ─────────────────────────────────────────────────
            const byRace = {};
            raceData.forEach(r => {
                if (!byRace[r.race]) byRace[r.race] = [];
                byRace[r.race].push(r);
            });
            Object.keys(byRace).sort((a, b) => a - b).forEach(race => {
                log(`  ${race}R: ${byRace[race].length}頭`);
                byRace[race].slice(0, 3).forEach(h =>
                    log(`    ${h.rank}着 馬番${String(h.horse_num).padStart(2,' ')} ${h.horse_name} / ${h.jockey}`)
                );
                if (byRace[race].length > 3) log(`    ...`); // 4頭目以降は省略
            });

            allResults.push(...raceData); // この開催の結果を全体配列に追加

            // ─────────────────────────────────────────────────
            // 【ブロック 10】開催一覧ページへ戻る
            //   次の開催を処理するために開催一覧ページへ戻る。
            //   "pw01sli00" を含む onclick のリンクが「開催一覧」ボタン。
            // ─────────────────────────────────────────────────
            log(`  ${kaisai.label} 完了 → 開催一覧へ戻る`);
            await page.evaluate(() => {
                [...document.querySelectorAll('a')]
                    .find(a => a.getAttribute('onclick')?.includes("pw01sli00"))
                    ?.click();
            });
            await page.waitForLoadState('networkidle', { timeout: 30000 });
            await sleep(2000);
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】完了ログ・JSON 出力
        //   全開催分の着順データを stdout に出力する。
        // ─────────────────────────────────────────────────────
        log('');
        log('================================================================');
        log('スクレイピング完了');
        log(`  総着順データ: ${allResults.length} 件`);
        log('================================================================');

        console.log(JSON.stringify({ results: allResults }));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 12】致命的エラーハンドリング
        //   エラーが発生した場合でも空の results を返して呼び出し元が認識できるようにする
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}`);
        log(err.stack ?? '');
        console.log(JSON.stringify({ results: [] }));
        process.exitCode = 1;

    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 13】後処理（必ず実行）
        //   ブラウザのクローズとロックファイルの削除を確実に行う。
        //   エラー発生時でも必ず実行されるため finally に記述。
        // ─────────────────────────────────────────────────────
        if (browser) {
            await browser.close();
            log('[FINALLY] ブラウザをクローズしました。');
        }
        if (existsSync(lockFile)) {
            unlinkSync(lockFile); // ロックを解除
            log('[FINALLY] ロックファイルを削除しました。');
        }
        logStream.end(); // ログファイルを閉じる
    }

})();
