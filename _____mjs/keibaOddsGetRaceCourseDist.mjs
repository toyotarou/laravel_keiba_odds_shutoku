/**
 * keibaOddsGetRaceCourseDist.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」から、指定した年月の全開催・全レースの
 *   「距離」と「馬場」を一括取得する。
 *
 * 【取得する項目（レースごと）】
 *   race（レース番号）, race_name（レース名）, distance（距離 m）, surface（馬場: 芝/ダート）
 *
 * 【DOM構造（レース選択ページのテーブル）】
 *   table > tbody > tr（1レースごとの行）
 *     td[0] : レース番号（例: "1R"）
 *     td[1] : レース名（例: "3歳未勝利"）
 *     td[2] : レース映像ボタン
 *     td[3] : 距離（例: "1,200メートル"）
 *     td[4] : 馬場（例: "ダート" / "芝"）
 *     td[5] : 出走頭数
 *     td[6] : 最終オッズ
 *     td[7] : WIN5
 *
 * ════════════════════════════════════════════════════════════════
 * 【ブラウザで追える進行順路】
 * ════════════════════════════════════════════════════════════════
 *
 * ▼ STEP A ── JRAデータベースへアクセス
 *   URL: https://www.jra.go.jp/JRADB/accessS.html
 *
 * ▼ STEP B ── 「過去のレース結果」リンクをクリック
 *   → 年・月のセレクトボックスが表示される
 *
 * ▼ STEP C ── 年・月を選択して検索ボタンをクリック
 *   → 指定年月の全開催一覧（例: 1回中山1日, 1回中京1日 …）が表示される
 *
 * ▼ STEP D ── 各開催をループして開催ボタンをクリック
 *   → レース一覧テーブル（距離・馬場あり）へ遷移
 *
 * ▼ STEP E ── テーブルをパースして距離・馬場を取得
 *   → 全開催分を収集して JSON で出力
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【使い方】
 *   node keibaOddsGetRaceCourseDist.mjs --yearmonth=2023-01
 *   node keibaOddsGetRaceCourseDist.mjs --yearmonth=2023-01 --list-only  （開催名一覧のみ）
 *
 * 【出力 JSON 形式】
 *   {
 *     "yearmonth": "2023-01",
 *     "data": [
 *       {
 *         "kaisai": "1回中山1日",
 *         "date": "2023-01-05",
 *         "kaisuu": 1,
 *         "basho": "中山",
 *         "basho_code": "06",
 *         "day": 1,
 *         "races": [
 *           { "race": 1, "race_name": "3歳未勝利", "distance": 1200, "surface": "ダート" },
 *           ...
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 *
 * 【Laravel からの呼び出し例】
 *   $result = shell_exec('node /path/to/keibaOddsGetRaceCourseDist.mjs --yearmonth=2023-01');
 *   $data = json_decode($result, true);
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { existsSync, writeFileSync, unlinkSync, readFileSync, createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】コマンドライン引数のパース
//   --yearmonth=YYYY-MM  : 取得対象の年月（必須）
//   --list-only          : 開催名一覧だけ返して終了（デバッグ用）
// ─────────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const yearmonthArg = args.find(a => a.startsWith('--yearmonth='));
const listOnly     = args.includes('--list-only');

if (!yearmonthArg) {
    process.stderr.write('Usage: node keibaOddsGetRaceCourseDist.mjs --yearmonth=2023-01\n');
    process.exit(1);
}

const yearmonth     = yearmonthArg.split('=')[1];
const [year, month] = yearmonth.split('-');

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所コードマッピング
// ─────────────────────────────────────────────────────────────
const bashoMap = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ロック・ログ設定
//   ロックキー: "YYYYMM"（年月単位で二重起動防止）
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey   = yearmonth.replace('-', '');
const lockFile  = join(__dirname, `keibaOddsGetRaceCourseDist_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetRaceCourseDist.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】開催一覧ページへの遷移ヘルパー関数
//   (5-1) accessS.html を開く
//   (5-2) 「過去のレース結果」リンクをクリック
//   (5-3) 年・月セレクトを操作 → change イベント発火
//   (5-4) 「getSelectData()」リンクをクリックして一覧を読み込む
// ─────────────────────────────────────────────────────────────
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
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ yearmonth, data: [] }));
            logStream.end();
            process.exit(0);
        }
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = []; // 全開催分のデータを格納する配列

    try {
        log('================================================================');
        log(`keibaOddsGetRaceCourseDist 開始 yearmonth=${yearmonth}`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】ブラウザ起動
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】開催一覧の取得
        //   ページ内の "X回場所Y日" 形式のリンクをすべて収集する
        // ─────────────────────────────────────────────────────
        await navigateToKaisaiList(page, year, month);

        const kaisaiList = await page.evaluate(() => {
            const result = [];
            document.querySelectorAll('a').forEach(a => {
                const text = a.textContent.replace(/\s+/g, '').trim();
                const m = text.match(/^(\d+)回(.+?)(\d+)日$/);
                if (m) result.push({
                    text,
                    kaisuu:    parseInt(m[1]),   // 開催回数
                    bashoName: m[2],             // 開催場所名（漢字）
                    day:       parseInt(m[3]),   // 開催日次
                });
            });
            return result;
        });

        if (kaisaiList.length === 0) {
            log(`ERROR: ${yearmonth} の開催が見つかりませんでした。`);
            console.log(JSON.stringify({ yearmonth, data: [] }));
            return;
        }

        log(`対象開催 ${kaisaiList.length}件: ${kaisaiList.map(k => k.text).join(', ')}`);

        // --list-only モード: 開催名一覧だけ返して終了
        if (listOnly) {
            console.log(JSON.stringify({ yearmonth, kaisaiList: kaisaiList.map(k => k.text) }));
            return;
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】各開催の距離・馬場取得ループ
        //
        //   各開催につき:
        //     (10-1) 開催一覧へ戻り → 開催ボタンクリック
        //            ※ 毎回 navigateToKaisaiList をやり直す（ページ遷移後に一覧が消えるため）
        //     (10-2) レース一覧テーブルをパース
        //
        //   DOM構造（レース選択ページのテーブル）:
        //     table > tbody > tr
        //       td[0] : レース番号（"1R" など）
        //       td[1] : レース名
        //       td[2] : レース映像ボタン
        //       td[3] : 距離（"1,200メートル"）
        //       td[4] : 馬場（"ダート" / "芝"）
        //       td[5] : 出走頭数（"16頭"）
        //       td[6] : 最終オッズリンク
        //       td[7] : WIN5
        // ─────────────────────────────────────────────────────
        for (const kaisai of kaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho_code = bashoMap[bashoName] ?? null;
            log(`\n[開催] ${kaisaiText}`);

            // (10-1) 開催一覧へ再遷移し、開催ボタンをクリック
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
            await sleep(3000);

            // (10-2) 日付取得 + テーブルパース
            //
            //   インデックス固定をやめ、セルの「内容」で各列を特定する。
            //   （最初のセルが <th> か <td> かでインデックスがずれるため）
            //
            //   動画セル : textContent に "eqPlayerButtonByAccount" を含む td
            //   距離セル : textContent に "メートル" を含む td
            //   馬場セル : class="course" の td
            //   レース名 : 動画セルの1つ前の td
            //   レース番号: eqPlayerButtonByAccount('XXXXXXXXXXXX') 末尾2桁
            const { date, races } = await page.evaluate(() => {
                // 日付を見出しタグから取得
                let date = null;
                for (const el of document.querySelectorAll('h1, h2, h3')) {
                    const m = el.textContent.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
                    if (m) {
                        date = `${m[1]}-${m[2].padStart(2,'0')}-${m[3].padStart(2,'0')}`;
                        break;
                    }
                }

                const races = [];

                document.querySelectorAll('table tr').forEach(tr => {
                    const tds = [...tr.querySelectorAll('td')];

                    const videoTd = tds.find(td => td.textContent.includes('eqPlayerButtonByAccount'));
                    if (!videoTd) return;

                    const raceIdMatch = videoTd.textContent.match(/eqPlayerButtonByAccount\('(\d{12})'/);
                    if (!raceIdMatch) return;
                    const race = parseInt(raceIdMatch[1].slice(-2), 10);

                    const videoIdx = tds.indexOf(videoTd);
                    const race_name = (videoIdx > 0 ? tds[videoIdx - 1] : null)
                        ?.textContent.replace(/\s+/g, ' ').trim() ?? '';

                    const distTd    = tds.find(td => td.textContent.includes('メートル'));
                    const distRaw   = distTd?.textContent.replace(/\s+/g, '').trim() ?? '';
                    const distMatch = distRaw.match(/([\d,]+)メートル/);
                    const dist      = distMatch ? parseInt(distMatch[1].replace(/,/g, ''), 10) : null;

                    const course = tr.querySelector('td.course')
                        ?.textContent.replace(/\s+/g, '').trim() ?? '';

                    races.push({ race, race_name, dist, course });
                });

                races.sort((a, b) => a.race - b.race);
                return { date, races };
            });

            if (races.length === 0) {
                log(`  WARNING: レースデータが見つかりませんでした。`);
                continue;
            }

            races.forEach(r => {
                log(`    [${r.race}R] ${r.race_name} / ${r.dist}m / ${r.course}`);
            });

            // フラットなレコードに展開して allData に追加
            races.forEach(r => {
                allData.push({
                    date,
                    kaisuu,
                    basho_name: bashoName,
                    basho_code,
                    day,
                    race:      r.race,
                    race_name: r.race_name,
                    dist:      r.dist,
                    course:    r.course,
                });
            });
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】JSON 出力
        //   { yearmonth, data: [ 開催1, 開催2, ... ] }
        // ─────────────────────────────────────────────────────
        log(`\n完了 — 合計 ${allData.length} レース取得`);

        console.log(JSON.stringify({ yearmonth, data: allData }, null, 2));

    } catch (err) {
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, data: allData, error: err.message }));
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        logStream.end();
    }
})();
