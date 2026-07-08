/**
 * keibaOddsGetWide.mjs
 *
 * 【概要】
 *   JRA公式サイトの「オッズ」→「ワイド」ページから
 *   指定レースの全馬番組合せのワイドオッズを取得する。
 *
 * 【スクレイピングフロー】
 *   Step 1: ブラウザ起動
 *   Step 2: JRAトップへアクセス
 *   Step 3: 「オッズ」リンクをクリック → 開催選択ページへ
 *   Step 4: 指定の開催をクリック → レース一覧ページへ
 *   Step 5: 指定レース番号の「ワイド」リンクをクリック → ワイドオッズページへ
 *   Step 6: table.wide から全組合せのオッズをスクレイピング
 *
 * 【keibaOddsGetTanpuku との違い】
 *   Step 5 で div.wide a を探す（単複は div.tanpuku a）
 *   Step 6 で table.wide を対象にする（単複は table.tanpuku）
 *   ワイドは馬番ペアごとの複数テーブルで構成される
 *
 * 【引数】
 *   $1: date    - 日付（例: "20260516"）※ YYYYMMDD 形式（Tanpuku は YYYY-MM-DD だが此処は YYYYMMDD）
 *   $2: kaisuu  - 開催回数（例: "2"）
 *   $3: basho   - 場所コード（例: "05" = 東京）
 *   $4: raceNum - レース番号（例: "1"）
 *   $5: day     - 開催日次（省略可）
 *
 * 【使い方】
 *   node keibaOddsGetWide.mjs 20260516 2 05 1
 *   node keibaOddsGetWide.mjs 20260516 2 05 1 3  （日次指定）
 *
 * 【標準出力】
 *   [
 *     { "uma1": "1", "uma2": "2", "odds_min": "3.5", "odds_max": "5.0" },
 *     ...
 *   ]
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync, readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】コマンドライン引数のパース
//   デフォルト値は開発・テスト用（実運用時は全引数を渡す）。
//   keibaOddsGetTanpuku.mjs と同じ引数構造。
//   ただし date のデフォルト形式が YYYYMMDD（ハイフンなし）。
// ─────────────────────────────────────────────────────────────
const args    = process.argv.slice(2);
const date    = args[0] ?? '20260516'; // YYYYMMDD 形式
const kaisuu  = args[1] ?? '2';
const basho   = args[2] ?? '05';
const raceNum = args[3] ?? '1';
const day     = args[4] ?? null;

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】場所コード → 漢字名変換
// ─────────────────────────────────────────────────────────────
const kaisaiMap = {
    '01': '札幌', '02': '函館', '03': '福島', '04': '新潟',
    '05': '東京', '06': '中山', '07': '中京', '08': '京都',
    '09': '阪神', '10': '小倉'
};
const bashoName = kaisaiMap[basho] ?? basho;

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ログ設定（追記モード）
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetWide.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】ロックファイルの設定
//   同一レースの重複起動を防ぐため、引数の組み合わせをキーにする。
// ─────────────────────────────────────────────────────────────
const lockKey  = `${date}_${kaisuu}_${basho}_${raceNum}`;
const lockFile = join(__dirname, `keibaOddsGetWide_${lockKey}.lock`);

// PID が現在も生きているかを確認する（スタールロック対策）
function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 6】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    // ─────────────────────────────────────────────────────────
    // 【ブロック 7】二重起動防止（スタールロック考慮）
    // ─────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】Step 1: ブラウザ起動
        // ─────────────────────────────────────────────────────
        log('[Step 1] ブラウザ（Playwright / Chromium）を起動中...');
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });
        log('[Step 1] ブラウザ起動完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】Step 2: JRAトップページへアクセス
        // ─────────────────────────────────────────────────────
        log('[Step 2] JRA トップページにアクセス中...');
        await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
        log('[Step 2] JRA トップページ ロード完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】Step 3: 「オッズ」リンクをクリック
        //   → 開催選択ページへ遷移
        // ─────────────────────────────────────────────────────
        log('[Step 3] 「オッズ」リンクをクリック中...');
        await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('a'));
            const target = links.find(a => a.textContent.trim() === 'オッズ');
            if (target) target.click();
        });
        await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
        await sleep(2000);
        log('[Step 3] オッズ開催選択ページへ遷移完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】Step 4: 指定の開催リンクをクリック
        //   → レース一覧ページへ遷移
        //   keibaOddsGetTanpuku.mjs と完全に同一のロジック。
        //   day の有無で完全一致・部分一致を切り替える。
        // ─────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】Step 5: 指定レース番号の「ワイド」リンクをクリック
        //   → ワイドオッズページへ遷移
        //
        //   keibaOddsGetTanpuku.mjs の Step 5 との違い:
        //     Tanpuku: div.tanpuku a を探す
        //     Wide   : div.wide a   を探す
        //
        //   リンク検索のアルゴリズムは Tanpuku と同一。
        //     1. onclick/href 属性からレース番号を抽出して一致確認
        //     2. フォールバック: 行インデックスによるアクセス
        // ─────────────────────────────────────────────────────
        log(`[Step 5] ${raceNum}R のワイドリンクをクリック中...`);
        const clicked5 = await page.evaluate(({ targetRace }) => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            for (const row of rows) {
                const link = row.querySelector('div.wide a'); // ← Tanpuku は div.tanpuku a
                if (!link) continue;
                const attr = link.getAttribute('onclick') ?? link.getAttribute('href') ?? '';
                const patterns = [
                    new RegExp(`\\/0*${targetRace}\\/`),           // パターン1: "/N/" 形式
                    new RegExp(`[,']\\s*0*${targetRace}\\s*[,'"]`), // パターン2: クォート囲み
                ];
                if (patterns.some(p => p.test(attr))) {
                    link.click();
                    return { ok: true, method: 'attr' };
                }
            }
            // フォールバック: 行インデックスによるアクセス
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
        // table.wide が出るまで最大15秒待つ
        await page.waitForSelector('table.wide', { timeout: 15000 }).catch(() => {});
        await sleep(500);
        log(`[Step 5] ${step4Label} ${raceNum}R ワイドオッズページへ遷移完了。`);

        // ─────────────────────────────────────────────────────
        // 【ブロック 13】Step 6: ワイドオッズテーブルのスクレイピング
        //   ワイドオッズは馬番ごとの複数テーブルで構成される。
        //
        //   DOM 構造:
        //     table.wide[N 個]（馬番1から順にある）
        //       caption          : 基準馬番（uma1）
        //       tbody tr
        //         th             : 相手馬番（uma2）
        //         td.odds
        //           span.min     : オッズ下限
        //           span.max     : オッズ上限
        //
        //   例: table の caption が "1" で、行の th が "3" の場合
        //       → 1-3 の組合せのワイドオッズ
        //
        //   uma1 と uma2 と odds_min の3つが揃った組合せのみ有効データとする。
        // ─────────────────────────────────────────────────────
        log('[Step 6] オッズテーブルをスクレイピング中...');
        const odds = await page.evaluate(() => {
            const tables = document.querySelectorAll('table.wide'); // 全ての wide テーブルを取得
            const result = [];
            tables.forEach(table => {
                // caption が基準馬番（uma1）
                const uma1 = table.querySelector('caption')?.textContent.trim() ?? '';
                table.querySelectorAll('tbody tr').forEach(row => {
                    // th が相手馬番（uma2）
                    const uma2    = row.querySelector('th')?.textContent.trim() ?? '';
                    // オッズは最小・最大の2値（「1.5 - 2.8」の形式で表示されているが span で分かれている）
                    const oddsMin = row.querySelector('td.odds span.min')?.textContent.trim() ?? '';
                    const oddsMax = row.querySelector('td.odds span.max')?.textContent.trim() ?? '';
                    // 3つが揃っている場合のみ有効データとして追加
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 14】JSON 出力
        // ─────────────────────────────────────────────────────
        console.log(JSON.stringify(odds));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 15】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}`);
        log(err.stack ?? '');
        console.log(JSON.stringify([]));
        process.exitCode = 1;

    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 16】後処理（必ず実行）
        // ─────────────────────────────────────────────────────
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
