/**
 * keibaOddsGetFinishingPosition.mjs
 *
 * JRA「過去レース結果」から指定年月・指定開催の全レースの
 * 着順・馬番・馬名を取得する。
 *
 * フロー:
 *   accessS.html → 「過去のレース結果」→ 年月セレクト → 検索
 *   → 開催ボタン（例: 1回中山1日）→ 「全てのレースを表示」
 *   → 表示された全レース分の結果テーブルをまとめてパース
 *
 * ページ構造（調査済み）:
 *   各レースは <div class="race_result_unit" id="race_result_5R"> で1単位。
 *   id="race_result_<N>R" の N がレース番号。
 *   その中の結果テーブルは table.basic.narrow-xy.striped。
 *   列順は固定で [0]着順 [1]枠 [2]馬番 [3]馬名 ...（枠の rowspan による
 *   セル欠落は無く、全行14〜15列で揃う）。
 *
 * Usage:
 *   node keibaOddsGetFinishingPosition.mjs --yearmonth=2023-01 --kaisai=1回中山1日
 *
 * 出力(JSON, stdout):
 *   {
 *     "yearmonth": "2023-01",
 *     "kaisai": "1回中山1日",
 *     "races": [
 *       { "race": 1, "horses": [ { "chakujun": 1, "num": 14, "name": "シュバルツガイスト" }, ... ] },
 *       ...
 *     ]
 *   }
 *   ※ chakujun は数字ならその整数、「中止」「除外」等は元の文字列のまま。
 */

import { chromium } from 'playwright';
import { existsSync, writeFileSync, unlinkSync, readFileSync, createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ── 引数パース ────────────────────────────────────────────────
const args = process.argv.slice(2);
const yearmonthArg = args.find(a => a.startsWith('--yearmonth='));
const kaisaiArg    = args.find(a => a.startsWith('--kaisai='));

if (!yearmonthArg || !kaisaiArg) {
    process.stderr.write('Usage: node keibaOddsGetFinishingPosition.mjs --yearmonth=2023-01 --kaisai=1回中山1日\n');
    process.exit(1);
}
const yearmonth     = yearmonthArg.split('=')[1];
const kaisaiFilter  = kaisaiArg.split('=')[1];
const [year, month] = yearmonth.split('-');

// ── ロック・ログ設定 ─────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey   = `${yearmonth}_${kaisaiFilter}`.replace(/\s/g, '');
const lockFile  = join(__dirname, `keibaOddsGetFinishingPosition_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetFinishingPosition.log'), { flags: 'a' });

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
        const searchLink = Array.from(document.querySelectorAll('a'))
            .find(a => a.getAttribute('onclick') === 'getSelectData();');
        if (searchLink) { searchLink.click(); } else { getSelectData(); }
    });
    await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
    await sleep(2000);
}

// ── メイン ───────────────────────────────────────────────────
(async () => {

    // 二重起動防止（スタールロックは無視して上書き）
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races: [] }));
            logStream.end();
            process.exit(0);
        }
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    let races = [];

    try {
        log('================================================================');
        log(`keibaOddsGetFinishingPosition 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter}`);
        log('================================================================');

        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 1600 });

        // ① 開催一覧
        await navigateToKaisaiList(page, year, month);

        // ② 開催ボタンをクリック
        const kaisaiText = kaisaiFilter.replace(/\s+/g, '');
        const clickedKaisai = await page.evaluate(({ kaisaiText }) => {
            const target = Array.from(document.querySelectorAll('a'))
                .find(a => a.textContent.replace(/\s+/g, '').trim() === kaisaiText);
            if (target) { target.click(); return true; }
            return false;
        }, { kaisaiText });

        if (!clickedKaisai) {
            log(`ERROR: 開催「${kaisaiText}」が見つかりませんでした。`);
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races: [] }));
            return;
        }
        log(`開催ボタン「${kaisaiText}」クリック OK`);
        await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
        await sleep(2000);

        // ③ 「全てのレースを表示」をクリック
        const clickedAll = await page.evaluate(() => {
            const target = Array.from(document.querySelectorAll('a, button'))
                .find(el => el.textContent.includes('全てのレースを表示'));
            if (target) { target.click(); return true; }
            return false;
        });
        log(`「全てのレースを表示」クリック ${clickedAll ? 'OK' : '（ボタン無し→続行）'}`);
        // テーブルが出揃うまで待つ
        await page.waitForSelector('div.race_result_unit table.basic.narrow-xy.striped', { timeout: 15000 }).catch(() => {});
        await sleep(2500);

        // ④ 全レース分を一括パース
        races = await page.evaluate(() => {
            const norm = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

            // 着順セル: 数字ならInt、それ以外（中止/除外/取消/失格/降着 等）は文字列のまま
            const parseChakujun = (raw) => {
                const t = norm(raw);
                return /^\d+$/.test(t) ? parseInt(t, 10) : (t || null);
            };

            const out = [];
            const units = Array.from(document.querySelectorAll('div.race_result_unit'));

            units.forEach((unit) => {
                // id="race_result_5R" → 5
                const m = (unit.id || '').match(/race_result_(\d+)R/);
                if (!m) return;
                const raceNum = parseInt(m[1], 10);

                const table = unit.querySelector('table.basic.narrow-xy.striped');
                if (!table) return;

                const horses = [];
                table.querySelectorAll('tbody tr').forEach((row) => {
                    const cells = Array.from(row.querySelectorAll('td'));
                    if (cells.length < 4) return; // ヘッダ等をスキップ

                    // 列順固定: [0]着順 [1]枠 [2]馬番 [3]馬名
                    const chakujun = parseChakujun(cells[0].textContent);
                    const numText  = norm(cells[2].textContent);
                    const name     = norm(cells[3].textContent);

                    // 馬番が数字でない行（区切り等）は除外
                    if (!/^\d+$/.test(numText)) return;
                    if (!name) return;

                    horses.push({
                        chakujun,
                        num: parseInt(numText, 10),
                        name,
                    });
                });

                if (horses.length > 0) {
                    out.push({ race: raceNum, horses });
                }
            });

            // レース番号順に整列
            out.sort((a, b) => a.race - b.race);
            return out;
        });

        log(`取得完了: ${races.length} レース / 合計 ${races.reduce((s, r) => s + r.horses.length, 0)} 頭`);
        races.forEach(r => {
            const top = r.horses.find(h => h.chakujun === 1);
            log(`  ${r.race}R: ${r.horses.length}頭 (1着 ${top ? `${top.num} ${top.name}` : '不明'})`);
        });

        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races }, null, 2));

    } catch (err) {
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races, error: err.message }));
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        logStream.end();
    }
})();
