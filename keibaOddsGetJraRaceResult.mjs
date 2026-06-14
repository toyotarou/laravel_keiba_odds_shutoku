/**
 * keibaOddsGetJraRaceResult.mjs
 *
 * JRA公式サイトからレース結果を取得する
 *
 * 処理フロー:
 *   Step A: JRAトップ → 「レース結果」メニュークリック → 開催一覧ページへ
 *   Step B: 直近の開催（最大6件）を対象に「全てのレースを表示」をクリック
 *   Step C: 1ページに全レースの着順が並ぶので解析して取得
 *           ※ 印刷用ページリンクはDIV内にあり tr には存在しない
 *           ※ レース区切りは「印刷用ページ」のonclickを全ページから先に収集して使う
 *
 * Output (stdout / Laravel が受け取る JSON):
 *   { "results": [ { kaisuu, basho, day, race, rank, horse_num, horse_name, jockey }, ... ] }
 */

import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetJraRaceResult.log'), { flags: 'w' });
const lockFile  = join(__dirname, 'keibaOddsGetJraRaceResult.lock');

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {

    if (existsSync(lockFile)) {
        log('[LOCK] 既に起動中のため終了します');
        console.log(JSON.stringify({ results: [] }));
        logStream.end();
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid));
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {

        log('================================================================');
        log('keibaOddsGetJraRaceResult 開始');
        log('================================================================');

        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const page = await context.newPage();

        // Step A: JRAトップ → レース結果一覧
        log('[Step A] JRAトップページにアクセス中...');
        await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
        await sleep(3000);

        log('[Step A] 「レース結果」メニューをクリック...');
        await page.evaluate(() => {
            [...document.querySelectorAll('a')]
                .find(a => a.getAttribute('onclick')?.includes("accessS.html','pw01sli00"))
                ?.click();
        });
        await page.waitForLoadState('networkidle', { timeout: 30000 });
        await sleep(3000);

        // Step B: 開催リンク取得（先頭6件のみ）
        const kaisaiLinks = await page.evaluate(() => {
            const links = [];
            document.querySelectorAll('a').forEach(a => {
                const m = a.textContent.trim().match(/^(\d+)回(.+?)(\d+)日$/);
                if (!m) return;
                links.push({
                    label:   a.textContent.trim(),
                    kaisuu:  Number(m[1]),
                    basho:   m[2],
                    day:     Number(m[3]),
                    onclick: a.getAttribute('onclick') ?? '',
                });
            });
            return links;
        });

        const targetKaisai = kaisaiLinks.slice(0, 6);
        log(`[Step B] 開催リンク総数: ${kaisaiLinks.length} 件 → 先頭 ${targetKaisai.length} 件を対象`);
        targetKaisai.forEach(k => log(`  → ${k.label}`));

        if (targetKaisai.length === 0) {
            log('[Step B] 対象開催なし。終了します。');
            console.log(JSON.stringify({ results: [] }));
            return;
        }

        const allResults = [];

        // Step C: 各開催の「全てのレースを表示」から着順を取得
        for (const kaisai of targetKaisai) {
            log('');
            log(`----------------------------------------------------------------`);
            log(`[Step C] ${kaisai.label} 処理開始`);
            log(`----------------------------------------------------------------`);

            // 開催リンクをクリック
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

            // 「全てのレースを表示」をクリック
            const allRaceOnclick = await page.evaluate(() => {
                const a = [...document.querySelectorAll('a')]
                    .find(el => el.textContent.trim().includes('全てのレースを表示'));
                if (!a) return null;
                a.click();
                return a.getAttribute('onclick');
            });

            if (!allRaceOnclick) {
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

            const raceData = await page.evaluate(({ kaisuu, basho, day }) => {
                const results = [];

                // レース番号リストを「印刷用ページ」から収集（ページ順）
                const raceNums = [];
                [...document.querySelectorAll('a')].forEach(a => {
                    if (a.textContent.trim() !== '印刷用ページ') return;
                    const oc = a.getAttribute('onclick') ?? '';
                    const m = oc.match(/pw01spr\d{4}(\d{4})(\d{2})(\d{2})(\d{2})\d{8}/);
                    if (m) raceNums.push(parseInt(m[4], 10));
                });

                if (raceNums.length === 0) return [];

                let raceIdx   = 0;
                let currentRace = raceNums[0];
                let firstOfRace = true;

                const rows = [...document.querySelectorAll('tbody tr')];

                for (const tr of rows) {
                    const cells = [...tr.querySelectorAll('td')];
                    if (cells.length < 4) continue;

                    const rankText = cells[0]?.textContent.trim();
                    const rank = parseInt(rankText, 10);
                    if (isNaN(rank) || rank < 1 || rank > 28) continue;

                    // 着順=1 かつ前のレースで既に1着が出ている場合 → 次のレースへ
                    if (rank === 1 && !firstOfRace) {
                        raceIdx++;
                        if (raceIdx >= raceNums.length) break;
                        currentRace = raceNums[raceIdx];
                    }
                    if (rank === 1) firstOfRace = false;

                    const horseNum  = parseInt(cells[2]?.textContent.trim(), 10) || null;
                    const horseName = cells[3]?.textContent.replace(/\s+/g, '').trim() ?? '';

                    // 騎手: accessK.html リンクのテキスト
                    const jockeyLink = [...tr.querySelectorAll('a')]
                        .find(a => a.getAttribute('onclick')?.includes('accessK.html'));
                    const jockey = jockeyLink?.textContent.replace(/\s+/g, '').trim() ?? '';

                    if (!horseNum || !horseName) continue;

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

            // レース別にログ出力
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
                if (byRace[race].length > 3) log(`    ...`);
            });

            allResults.push(...raceData);

            // 開催一覧ページへ戻る
            log(`  ${kaisai.label} 完了 → 開催一覧へ戻る`);
            await page.evaluate(() => {
                [...document.querySelectorAll('a')]
                    .find(a => a.getAttribute('onclick')?.includes("pw01sli00"))
                    ?.click();
            });
            await page.waitForLoadState('networkidle', { timeout: 30000 });
            await sleep(2000);
        }

        log('');
        log('================================================================');
        log('スクレイピング完了');
        log(`  総着順データ: ${allResults.length} 件`);
        log('================================================================');

        console.log(JSON.stringify({ results: allResults }));

    } catch (err) {
        log(`致命的エラー: ${err.message}`);
        log(err.stack ?? '');
        console.log(JSON.stringify({ results: [] }));
        process.exitCode = 1;

    } finally {
        if (browser) {
            await browser.close();
            log('[FINALLY] ブラウザをクローズしました。');
        }
        if (existsSync(lockFile)) {
            unlinkSync(lockFile);
            log('[FINALLY] ロックファイルを削除しました。');
        }
        logStream.end();
    }

})();
