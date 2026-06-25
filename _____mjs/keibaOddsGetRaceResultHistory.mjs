/**
 * keibaOddsGetRaceResultHistory.mjs
 *
 * JRA「過去レース結果」から指定年月・指定開催の全レースの
 * 単勝・複勝オッズ（最終オッズ）を取得する
 *
 * Usage:
 *   node keibaOddsGetRaceResultHistory.mjs --yearmonth=2021-01 --kaisai=3回東京1日
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
    process.stderr.write('Usage: node keibaOddsGetRaceResultHistory.mjs --yearmonth=2021-01 [--kaisai=3回東京1日]\n');
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
const lockFile  = join(__dirname, `keibaOddsGetRaceResultHistory_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetRaceResultHistory.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ── 開催一覧ページへ遷移 ────────────────────────────────────
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
        // getSelectData() を呼び出す検索リンクをクリック
        const searchLink = Array.from(document.querySelectorAll('a')).find(a => a.getAttribute('onclick') === 'getSelectData();');
        if (searchLink) { searchLink.click(); } else { getSelectData(); }
    });
    await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
    await sleep(2000);
}

// ── オッズリンク収集 ─────────────────────────────────────────
// code: pw151ou1(8) + 0(1) + basho(2) + year(4) + kaisuu(2) + race(2) + date(8) + Z
// race番号 = substring(19, 21)
// 日付     = substring(21, 29) → YYYYMMDD
async function getOddsLinks(page) {
    const rawLinks = await page.evaluate(() => {
        const result = [];
        document.querySelectorAll('a').forEach(a => {
            const onclick = a.getAttribute('onclick') ?? '';
            const m = onclick.match(/doAction\('\/JRADB\/accessO\.html',\s*'(pw151ou1\w+Z)\/[0-9A-F]+'\)/);
            if (m) result.push({ code: m[1], onclick });
        });
        return result;
    });
    return rawLinks.map(({ code, onclick }) => {
        const raceNum = parseInt(code.substring(19, 21), 10);
        const d = code.substring(21, 29);
        const date = `${d.substring(0,4)}-${d.substring(4,6)}-${d.substring(6,8)}`;
        return { code, onclick, raceNum, date };
    })
    .filter(l => !isNaN(l.raceNum))
    .sort((a, b) => a.raceNum - b.raceNum);
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
        log(`keibaOddsGetRaceResultHistory 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter ?? '全開催'}`);
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

        // --list-only モード: 開催名一覧だけ返して終了
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

            // 全レースのオッズリンクを収集
            const oddsLinks = await getOddsLinks(page);
            if (oddsLinks.length === 0) {
                log(`  WARNING: オッズリンクが見つかりませんでした。スキップします。`);
                continue;
            }
            log(`  ${oddsLinks.length} レース: ${oddsLinks.map(o => o.raceNum).join(', ')}R`);

            const races = [];

            // ── 各レースのオッズを取得 ───────────────────────
            for (const { code, raceNum } of oddsLinks) {
                log(`    [Race ${raceNum}R] 取得中...`);

                await page.evaluate(({ code }) => {
                    Array.from(document.querySelectorAll('a'))
                        .find(a => (a.getAttribute('onclick') ?? '').includes(code))?.click();
                }, { code });

                await page.waitForSelector('table.tanpuku', { timeout: 15000 }).catch(() => {});
                await sleep(1000);

                const { raceName, raceDate, horses } = await page.evaluate(() => {
                    // レース名: h2の中で「検索ウィンドウ」「JRAからのお知らせ」以外の最初のもの
                    const h2s = Array.from(document.querySelectorAll('h2'));
                    const raceNameEl = h2s.find(el => {
                        const t = el.textContent.trim();
                        return t && t !== '検索ウィンドウ' && t !== 'JRAからのお知らせ';
                    });
                    const raceName = raceNameEl
                        ? raceNameEl.textContent.replace(/\s+/g, ' ').trim()
                        : '';

                    // 日付: h1から「2023年1月5日」を抽出
                    let raceDate = null;
                    const h1s = Array.from(document.querySelectorAll('h1'));
                    for (const h1 of h1s) {
                        const m = h1.textContent.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
                        if (m) {
                            const y  = m[1];
                            const mo = m[2].padStart(2, '0');
                            const d  = m[3].padStart(2, '0');
                            raceDate = `${y}-${mo}-${d}`;
                            break;
                        }
                    }

                    const horses = [];
                    const table = document.querySelector('table.tanpuku');
                    if (!table) return { raceName, horses };

                    // カラム順: 枠 | 馬番 | 馬名 | 単勝 | 複勝（3着払い） | ...
                    table.querySelectorAll('tbody tr').forEach(row => {
                        const cells = Array.from(row.querySelectorAll('td'));
                        if (cells.length < 4) return;

                        // 10列=枠あり行(offset=1), 9列=枠なし行(offset=0)
                        const offset  = cells.length >= 10 ? 1 : 0;
                        const num     = cells[offset]?.textContent.trim() ?? '';
                        const name    = cells[offset + 1]?.textContent.trim() ?? '';
                        const tan     = cells[offset + 2]?.textContent.trim() ?? '';
                        const fukuRaw = cells[offset + 3]?.textContent.trim() ?? '';

                        if (!num.match(/^\d+$/) || !name) return;

                        const fukuParts = fukuRaw.split('-').map(s => s.trim());
                        horses.push({
                            num:      parseInt(num),
                            name,
                            tan:      parseFloat(tan)              || null,
                            fuku_min: parseFloat(fukuParts[0])     || null,
                            fuku_max: parseFloat(fukuParts[1] ?? fukuParts[0]) || null,
                        });
                    });
                    return { raceName, raceDate, horses };
                });

                if (horses.length > 0) {
                    log(`    [Race ${raceNum}R] OK → ${horses.length}頭 (${raceName})`);
                    races.push({ race: raceNum, race_name: raceName, date: raceDate, horses });
                } else {
                    log(`    [Race ${raceNum}R] WARNING: データなし`);
                }

                await page.goBack({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
                await sleep(1000);
            }

            // 開催単位でまとめてpush（dateはracesの最初のレースから取得）
            if (races.length > 0) {
                const date = races[0].date;
                allData.push({
                    date,
                    kaisuu,
                    basho: bashoName,
                    basho_code: basho,
                    day,
                    races,
                });
            }
        }

        log(`\n完了 — 合計 ${allData.length} 開催 / ${allData.reduce((s, k) => s + k.races.length, 0)} レース取得`);

        // kaisai指定時は開催が1つ → 開催情報を上位に展開
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