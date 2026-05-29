import { chromium } from 'playwright';
import { createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ── 引数パース ────────────────────────────────────────────────────
// 呼び出し例: node keibaOddsGetTanpuku.mjs 2026-05-17 2 05 1
const args    = process.argv.slice(2);
const date    = args[0] ?? '2026-05-17';
const kaisuu  = args[1] ?? '2';
const basho   = args[2] ?? '05';
const raceNum = args[3] ?? '1';

const kaisaiMap = {
    '01': '札幌', '02': '函館', '03': '福島', '04': '新潟',
    '05': '東京', '06': '中山', '07': '中京', '08': '京都',
    '09': '阪神', '10': '小倉'
};
const bashoName = kaisaiMap[basho] ?? basho;

// ── ログ設定 ─────────────────────────────────────────────────────
// flags:'a' でレース分を1つのファイルに追記していく
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetTanpuku.log'), { flags: 'a' });

// ファイルとターミナル（stderr）の両方に出力する
// stderr に書く理由: stdout は PHP が受け取る JSON 専用にしたいため
const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ── メイン ────────────────────────────────────────────────────────
(async () => {
    log('================================================================');
    log('keibaOddsGetTanpuku 開始');
    log(`  date   : ${date}`);
    log(`  kaisuu : ${kaisuu}回`);
    log(`  basho  : ${basho} (${bashoName})`);
    log(`  race   : ${raceNum}R`);
    log('================================================================');

    // ── Step 1: ブラウザ起動 ─────────────────────────────────────
    log('[Step 1] ブラウザ（Playwright / Chromium）を起動中...');
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    await page.setViewportSize({ width: 1280, height: 800 });
    log('[Step 1] ブラウザ起動完了。');

    // ── Step 2: JRA トップページへ ───────────────────────────────
    log('[Step 2] JRA トップページにアクセス中...');
    log('  URL: https://www.jra.go.jp/');
    await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
    log('[Step 2] JRA トップページ ロード完了。');

    // ── Step 3: 「オッズ」リンクをクリック ──────────────────────
    log('[Step 3] 「オッズ」リンクをクリック中...');
    await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('a'));
        const target = links.find(a => a.textContent.trim() === 'オッズ');
        if (target) target.click();
    });
    log('[Step 3] クリック完了。オッズ開催選択ページへの遷移を待機中...');
    await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
    await sleep(2000);
    log('[Step 3] オッズ開催選択ページへ遷移完了。');

    // ── Step 4: 指定開催をクリック ──────────────────────────────
    log(`[Step 4] 開催「${kaisuu}回${bashoName}」のリンクを探してクリック中...`);
    const clicked4 = await page.evaluate(({ kaisuu, bashoName }) => {
        const links = Array.from(document.querySelectorAll('a'));
        const target = links.find(a => a.textContent.includes(`${kaisuu}回${bashoName}`));
        if (target) { target.click(); return true; }
        return false;
    }, { kaisuu, bashoName });
    if (!clicked4) {
        log(`[Step 4] WARNING: 「${kaisuu}回${bashoName}」のリンクが見つかりませんでした。`);
        console.log(JSON.stringify([]));
        await browser.close();
        return;
    }
    log(`[Step 4] クリック完了。${kaisuu}回${bashoName} レース一覧ページへの遷移を待機中...`);
    await page.waitForSelector('tbody tr', { timeout: 15000 }).catch(() => {});
    await sleep(1000);
    log(`[Step 4] ${kaisuu}回${bashoName} レース一覧ページへ遷移完了。`);

    // ── Step 5: 指定レースの単複リンクをクリック ────────────────
    log(`[Step 5] ${raceNum}R の行から「単勝・複勝」リンクをクリック中...`);
    log(`  対象行インデックス: ${parseInt(raceNum) - 1}（0始まり）`);
    const clicked5 = await page.evaluate(({ raceNum }) => {
        const rows  = Array.from(document.querySelectorAll('tbody tr'));
        const row   = rows[parseInt(raceNum) - 1];
        if (row) {
            const target = row.querySelector('div.tanpuku a');
            if (target) { target.click(); return true; }
        }
        return false;
    }, { raceNum });
    if (!clicked5) {
        log(`[Step 5] WARNING: ${raceNum}R の単複リンクが見つかりませんでした。`);
        console.log(JSON.stringify([]));
        await browser.close();
        return;
    }
    log(`[Step 5] クリック完了。単複オッズページへの遷移を待機中...`);
    await page.waitForSelector('table.tanpuku', { timeout: 15000 }).catch(() => {});
    await sleep(500);
    log(`[Step 5] ${kaisuu}回${bashoName} ${raceNum}R 単複オッズページへ遷移完了。`);

    // ── Step 6: オッズテーブルをスクレイピング ───────────────────
    log('[Step 6] オッズテーブル（table.tanpuku）をスクレイピング中...');
    const odds = await page.evaluate(() => {
        const table = document.querySelector('table.tanpuku');
        if (!table) return [];

        const result = [];
        table.querySelectorAll('tbody tr').forEach(row => {
            const num     = row.querySelector('td.num')?.textContent.trim()           ?? '';
            const tan     = row.querySelector('td.odds_tan')?.textContent.trim()      ?? '';
            const fukuMin = row.querySelector('td.odds_fuku span.min')?.textContent.trim() ?? '';
            const fukuMax = row.querySelector('td.odds_fuku span.max')?.textContent.trim() ?? '';
            if (num && tan) {
                result.push({ num, tan, fuku_min: fukuMin, fuku_max: fukuMax });
            }
        });
        return result;
    });

    log(`[Step 6] スクレイピング完了 ── ${odds.length} 頭分のオッズを取得しました。`);

    if (odds.length === 0) {
        log('[Step 6] WARNING: オッズが0件です。テーブルが見つからなかった可能性があります。');
    } else {
        log('');
        log('  馬番  単勝オッズ  複勝オッズ（最小〜最大）');
        log('  ----  ----------  -----------------------');
        odds.forEach(h => {
            const num     = String(h.num).padStart(3, ' ');
            const tan     = String(h.tan).padStart(8, ' ');
            const fukuRange = h.fuku_min && h.fuku_max
                ? `${h.fuku_min} 〜 ${h.fuku_max}`
                : '（取得不可）';
            log(`  ${num}  ${tan}  ${fukuRange}`);
        });
        log('');
    }

    // ── 完了 ────────────────────────────────────────────────────
    log('================================================================');
    log('keibaOddsGetTanpuku 完了');
    log(`  ${kaisuu}回${bashoName} ${raceNum}R  取得頭数: ${odds.length}`);
    log('================================================================');

    logStream.end();

    // JSON を stdout に出力（Laravel コマンドが受け取る）
    // ※ ここ以外で console.log を使わないこと（JSON が壊れる）
    console.log(JSON.stringify(odds));

    await browser.close();

})().catch(async (err) => {
    log(`致命的エラー: ${err.message}`);
    log(err.stack ?? '');
    logStream.end();
    process.exit(1);
});
