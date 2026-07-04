/**
 * keibaOddsGetPayout.mjs
 *
 * JRA「過去レース結果」から指定年月・指定開催の全レースの
 * 払戻金（単勝・複勝・枠連・ワイド・馬連・馬単・3連複・3連単）を取得する
 *
 * 払戻金は「全てのレースを表示」後のページの
 * div#race_result_NR 内の dl リストに格納されている。
 * 構造: li.tan/fuku/wakuren/wide/umaren/umatan/trio/tierce
 *       > dl > dt（券種名）+ dd > div.line > div.num/yen/pop
 *
 * Usage:
 *   node keibaOddsGetPayout.mjs --yearmonth=2023-01 --kaisai=1回中山1日
 *   （--kaisai 省略時は指定年月の全開催を処理）
 */

import { chromium } from 'playwright';
import { existsSync, writeFileSync, unlinkSync, readFileSync, createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ── 引数パース ────────────────────────────────────────────────
const args = process.argv.slice(2);
const yearmonthArg = args.find(a => a.startsWith('--yearmonth='));
const kaisaiArg    = args.find(a => a.startsWith('--kaisai='));
const listOnly     = args.includes('--list-only');

if (!yearmonthArg) {
    process.stderr.write('Usage: node keibaOddsGetPayout.mjs --yearmonth=2023-01 [--kaisai=1回中山1日]\n');
    process.exit(1);
}
const yearmonth    = yearmonthArg.split('=')[1];
const kaisaiFilter = kaisaiArg ? kaisaiArg.split('=')[1] : null;
const [year, month] = yearmonth.split('-');

// ── 開催場所マッピング ───────────────────────────────────────
const bashoMap = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

// ── ロック・ログ設定 ─────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey  = kaisaiFilter
    ? `${yearmonth}_${kaisaiFilter}`.replace(/\s/g, '')
    : yearmonth.replace('-', '');
const lockFile  = join(__dirname, `keibaOddsGetPayout_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetPayout.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ── 開催一覧ページへ遷移（History と同一） ──────────────────
async function navigateToKaisaiList(page, year, month) {
    await page.goto('https://www.jra.go.jp/JRADB/accessS.html', {
        waitUntil: 'networkidle', timeout: 60000,
    });
    await sleep(1000);
    await page.evaluate(() => {
        Array.from(document.querySelectorAll('a'))
            .find(el => el.textContent.trim() === '過去のレース結果')?.click();
    });
    await page.waitForSelector('select', { timeout: 15000 }).catch(() => {});
    await sleep(1500);
    await page.evaluate(({ y, m }) => {
        const selects = document.querySelectorAll('select');
        if (selects[0]) { selects[0].value = y; selects[0].dispatchEvent(new Event('change')); }
        if (selects[1]) { selects[1].value = m.padStart(2, '0'); selects[1].dispatchEvent(new Event('change')); }
    }, { y: year, m: month });
    await sleep(500);
    await page.evaluate(() => {
        const searchLink = Array.from(document.querySelectorAll('a'))
            .find(a => a.getAttribute('onclick') === 'getSelectData();');
        if (searchLink) { searchLink.click(); } else { getSelectData(); }
    });
    await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
    await sleep(2000);
}

// ── メイン ───────────────────────────────────────────────────
(async () => {

    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: [] }));
            logStream.end();
            process.exit(0);
        }
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = [];

    try {
        log('================================================================');
        log(`keibaOddsGetPayout 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter ?? '全開催'}`);
        log('================================================================');

        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });

        // 開催一覧を取得
        await navigateToKaisaiList(page, year, month);

        let kaisaiList = await page.evaluate(() => {
            const result = [];
            document.querySelectorAll('a').forEach(a => {
                const text = a.textContent.replace(/\s+/g, '').trim();
                const m = text.match(/^(\d+)回(.+?)(\d+)日$/);
                if (m) result.push({
                    text,
                    kaisuu:    parseInt(m[1]),
                    bashoName: m[2],
                    day:       parseInt(m[3]),
                });
            });
            return result;
        });

        // --kaisai 指定時はフィルタ
        if (kaisaiFilter) {
            kaisaiList = kaisaiList.filter(k => k.text === kaisaiFilter.replace(/\s+/g, ''));
            if (kaisaiList.length === 0) {
                log(`ERROR: 「${kaisaiFilter}」が見つかりませんでした。`);
                console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: [] }));
                return;
            }
        }

        log(`対象開催: ${kaisaiList.map(k => k.text).join(', ')}`);

        if (listOnly) {
            console.log(JSON.stringify({ yearmonth, kaisaiList: kaisaiList.map(k => k.text) }));
            return;
        }

        // ── 各開催を処理 ─────────────────────────────────────
        for (const kaisai of kaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho = bashoMap[bashoName] ?? null;
            log(`\n[開催] ${kaisaiText}`);

            await navigateToKaisaiList(page, year, month);

            const clickedKaisai = await page.evaluate(({ kaisaiText }) => {
                const target = Array.from(document.querySelectorAll('a'))
                    .find(a => a.textContent.replace(/\s+/g, '').trim() === kaisaiText);
                if (target) { target.click(); return true; }
                return false;
            }, { kaisaiText });

            if (!clickedKaisai) {
                log(`  WARNING: 「${kaisaiText}」リンクが見つかりませんでした。スキップします。`);
                continue;
            }
            await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
            await sleep(2000);

            // 「全てのレースを表示」クリック
            const clickedAll = await page.evaluate(() => {
                const target = Array.from(document.querySelectorAll('a, button'))
                    .find(el => el.textContent.includes('全てのレースを表示'));
                if (target) { target.click(); return true; }
                return false;
            });
            if (clickedAll) await sleep(2000);

            // ── ページ内の全レース払戻金を一括パース ─────────
            // 払戻金は div#race_result_NR 内の dl リスト
            // li.tan/fuku/wakuren/wide/umaren/umatan/trio/tierce
            // > dl > dt（券種名）+ dd > div.line（複数可）> div.num/yen/pop
            const { date, races } = await page.evaluate(() => {
                // 日付
                let date = null;
                for (const h1 of document.querySelectorAll('h1')) {
                    const m = h1.textContent.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
                    if (m) {
                        date = `${m[1]}-${m[2].padStart(2,'0')}-${m[3].padStart(2,'0')}`;
                        break;
                    }
                }

                const races = [];

                document.querySelectorAll('div[id^="race_result_"]').forEach(div => {
                    const idMatch = div.id.match(/race_result_(\d+)R/);
                    if (!idMatch) return;
                    const raceNum = parseInt(idMatch[1], 10);

                    // レース名
                    const h2 = div.querySelector('h2 .race_name');
                    const raceName = h2 ? h2.textContent.trim() : '';

                    // 払戻金: li.tan, li.fuku, li.wakuren, li.wide, li.umaren, li.umatan, li.trio, li.tierce
                    const payouts = [];
                    div.querySelectorAll('li.win, li.place, li.wakuren, li.wide, li.umaren, li.umatan, li.trio, li.tierce').forEach(li => {
                        const betType = li.querySelector('dt')?.textContent.replace(/\s+/g, '').trim() ?? '';
                        li.querySelectorAll('div.line').forEach(line => {
                            const combo      = line.querySelector('div.num')?.textContent.replace(/\s+/g, ' ').trim() ?? '';
                            const yenRaw     = line.querySelector('div.yen')?.textContent.replace(/[^\d]/g, '') ?? '';
                            const amount     = yenRaw ? parseInt(yenRaw, 10) : null;
                            const popRaw     = line.querySelector('div.pop')?.textContent.replace(/[^\d]/g, '') ?? '';
                            const popularity = popRaw ? parseInt(popRaw, 10) : null;
                            if (combo && amount !== null) {
                                payouts.push({ type: betType, combo, amount, popularity });
                            }
                        });
                    });

                    races.push({ race: raceNum, race_name: raceName, payouts });
                });

                races.sort((a, b) => a.race - b.race);
                return { date, races };
            });

            if (races.length === 0) {
                log(`  WARNING: 払戻金データが見つかりませんでした。`);
                continue;
            }

            races.forEach(r => {
                if (r.payouts.length > 0) {
                    log(`    [Race ${r.race}R] OK → ${r.payouts.length}件 (${r.race_name})`);
                } else {
                    log(`    [Race ${r.race}R] WARNING: 払戻金データなし (${r.race_name})`);
                }
            });

            allData.push({
                date,
                kaisuu,
                basho: bashoName,
                basho_code: basho,
                day,
                races: races.map(r => ({ ...r, date })),
            });
        }

        log(`\n完了 — 合計 ${allData.length} 開催 / ${allData.reduce((s, k) => s + k.races.length, 0)} レース取得`);

        let output;
        if (kaisaiFilter && allData.length === 1) {
            const { date, kaisuu, basho, basho_code, day, races } = allData[0];
            output = { yearmonth, kaisai: kaisaiFilter, date, kaisuu, basho, basho_code, day, races };
        } else {
            output = { yearmonth, data: allData };
        }
        console.log(JSON.stringify(output, null, 2));

    } catch (err) {
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: allData, error: err.message }));
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        logStream.end();
    }
})();
