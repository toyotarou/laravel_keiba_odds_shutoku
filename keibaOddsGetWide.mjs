import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync, readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const args    = process.argv.slice(2);
const date    = args[0] ?? '20260516';
const kaisuu  = args[1] ?? '2';
const basho   = args[2] ?? '05';
const raceNum = args[3] ?? '1';
const day     = args[4] ?? null;

const kaisaiMap = {
    '01': '札幌', '02': '函館', '03': '福島', '04': '新潟',
    '05': '東京', '06': '中山', '07': '中京', '08': '京都',
    '09': '阪神', '10': '小倉'
};
const bashoName = kaisaiMap[basho] ?? basho;

const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetWide.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ── ロックファイルパス（引数ごとに一意にする）─────────────────
const lockKey  = `${date}_${kaisuu}_${basho}_${raceNum}`;
const lockFile = join(__dirname, `keibaOddsGetWide_${lockKey}.lock`);

/** ロックファイルが指すPIDが実際に生きているか確認する（スタールロック対策） */
function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

(async () => {
    // ── 多重起動チェック（スタールロック考慮）────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に同じ引数で起動中のため終了します: ${lockKey} (PID=${storedPid})`);
            console.log(JSON.stringify([]));
            logStream.end();
            process.exit(0);
        }
        log(`[LOCK] 古いロックファイルを削除します (PID=${storedPid} は存在しない)`);
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {
        log('================================================================');
        log('keibaOddsGetWide 開始');
        log(`  date   : ${date}`);
        log(`  kaisuu : ${kaisuu}回`);
        log(`  basho  : ${basho} (${bashoName})`);
        log(`  race   : ${raceNum}R`);
        log(`  day    : ${day ?? '(未指定)'}日目`);
        log('================================================================');

        log('[Step 1] ブラウザ（Playwright / Chromium）を起動中...');
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });
        log('[Step 1] ブラウザ起動完了。');

        log('[Step 2] JRA トップページにアクセス中...');
        await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
        log('[Step 2] JRA トップページ ロード完了。');

        log('[Step 3] 「オッズ」リンクをクリック中...');
        await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('a'));
            const target = links.find(a => a.textContent.trim() === 'オッズ');
            if (target) target.click();
        });
        await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
        await sleep(2000);
        log('[Step 3] オッズ開催選択ページへ遷移完了。');

        const step4Label = day ? `${kaisuu}回${bashoName}${day}日` : `${kaisuu}回${bashoName}`;
        log(`[Step 4] 開催「${step4Label}」のリンクを探してクリック中...`);
        const clicked4 = await page.evaluate(({ kaisuu, bashoName, day }) => {
            const links = Array.from(document.querySelectorAll('a'));
            const searchText = day ? `${kaisuu}回${bashoName}${day}日` : `${kaisuu}回${bashoName}`;
            const target = links.find(a => a.textContent.includes(searchText));
            if (target) { target.click(); return true; }
            return false;
        }, { kaisuu, bashoName, day });
        if (!clicked4) {
            log(`[Step 4] WARNING: 「${step4Label}」のリンクが見つかりませんでした。`);
            console.log(JSON.stringify([]));
            return;
        }
        await page.waitForSelector('tbody tr', { timeout: 15000 }).catch(() => {});
        await sleep(1000);
        log(`[Step 4] ${step4Label} レース一覧ページへ遷移完了。`);

        log(`[Step 5] ${raceNum}R のワイドリンクをクリック中...`);
        const clicked5 = await page.evaluate(({ targetRace }) => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            for (const row of rows) {
                const link = row.querySelector('div.wide a');
                if (!link) continue;
                const attr = link.getAttribute('onclick') ?? link.getAttribute('href') ?? '';
                const patterns = [
                    new RegExp(`\\/0*${targetRace}\\/`),
                    new RegExp(`[,']\\s*0*${targetRace}\\s*[,'"]`),
                ];
                if (patterns.some(p => p.test(attr))) {
                    link.click();
                    return { ok: true, method: 'attr' };
                }
            }
            const row = rows[parseInt(targetRace) - 1];
            if (row) {
                const target = row.querySelector('div.wide a');
                if (target) { target.click(); return { ok: true, method: 'index' }; }
            }
            return { ok: false, method: 'none' };
        }, { targetRace: parseInt(raceNum) });

        log(`[Step 5] クリック結果: ok=${clicked5.ok} method=${clicked5.method}`);
        if (!clicked5.ok) {
            log(`[Step 5] WARNING: ${raceNum}R のワイドリンクが見つかりませんでした。`);
            console.log(JSON.stringify([]));
            return;
        }
        await page.waitForSelector('table.wide', { timeout: 15000 }).catch(() => {});
        await sleep(500);
        log(`[Step 5] ${step4Label} ${raceNum}R ワイドオッズページへ遷移完了。`);

        log('[Step 6] オッズテーブルをスクレイピング中...');
        const odds = await page.evaluate(() => {
            const tables = document.querySelectorAll('table.wide');
            const result = [];
            tables.forEach(table => {
                const uma1 = table.querySelector('caption')?.textContent.trim() ?? '';
                table.querySelectorAll('tbody tr').forEach(row => {
                    const uma2    = row.querySelector('th')?.textContent.trim() ?? '';
                    const oddsMin = row.querySelector('td.odds span.min')?.textContent.trim() ?? '';
                    const oddsMax = row.querySelector('td.odds span.max')?.textContent.trim() ?? '';
                    if (uma1 && uma2 && oddsMin) result.push({ uma1, uma2, odds_min: oddsMin, odds_max: oddsMax });
                });
            });
            return result;
        });

        log(`[Step 6] スクレイピング完了 ── ${odds.length} 組分のオッズを取得しました。`);

        log('================================================================');
        log('keibaOddsGetWide 完了');
        log(`  ${step4Label} ${raceNum}R  取得組数: ${odds.length}`);
        log('================================================================');

        console.log(JSON.stringify(odds));

    } catch (err) {
        log(`致命的エラー: ${err.message}`);
        log(err.stack ?? '');
        console.log(JSON.stringify([]));
        process.exitCode = 1;

    } finally {
        // ── 必ずブラウザを閉じ、ロックを解除する ────────────
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
