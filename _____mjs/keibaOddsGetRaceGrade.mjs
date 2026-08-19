/**
 * keibaOddsGetRaceGrade.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」から、指定した年月の全開催・全レースの
 *   「レースグレード」を一括取得する。
 *   グレードなしのレース（通常の条件戦）は出力に含まない。
 *
 * 【取得するグレード】
 *   G1 / G2 / G3 / L（リステッド）/ J-G1 / J-G2 / J-G3（障害）
 *
 * 【DOM構造（レース選択ページのテーブル）】
 *   table#race_list > tbody > tr（1レースごとの行）
 *     th.race_num : レース番号ボタン
 *     td.race_name: レース名 ＋ グレードバッジ
 *       <div>
 *         <div class="stakes">
 *           レース名<span class="grade_icon"><img src="...icon_grade_s_g3.png" alt="GⅢ"></span>
 *         </div>
 *         <div>条件テキスト</div>
 *       </div>
 *     td.mov      : レース映像ボタン（eqPlayerButtonByAccount を含む）
 *     td.dist     : 距離
 *     td.course   : 馬場
 *     td.num      : 出走頭数
 *     td.odds     : 最終オッズ
 *     td.win5     : WIN5
 *
 *   グレードバッジ: span.grade_icon > img の src ファイル名で判定
 *     icon_grade_s_g1.png     → G1
 *     icon_grade_s_g2.png     → G2
 *     icon_grade_s_g3.png     → G3
 *     icon_grade_s_listed.png → L
 *     icon_grade_s_j_g1.png   → J-G1（障害）
 *     icon_grade_s_j_g2.png   → J-G2（障害）
 *     icon_grade_s_j_g3.png   → J-G3（障害）
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
 *   → レース一覧テーブルへ遷移
 *
 * ▼ STEP E ── テーブルをパースしてグレードを取得
 *   → グレードありのレースのみ収集して JSON で出力
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【使い方】
 *   node keibaOddsGetRaceGrade.mjs --yearmonth=2023-01
 *   node keibaOddsGetRaceGrade.mjs --yearmonth=2023-01 --list-only  （開催名一覧のみ）
 *
 * 【出力 JSON 形式】
 *   {
 *     "yearmonth": "2023-01",
 *     "data": [
 *       {
 *         "date":       "2023-01-05",
 *         "kaisuu":     1,
 *         "basho_name": "中山",
 *         "basho_code": "06",
 *         "day":        1,
 *         "race":       11,
 *         "race_name":  "日刊スポ賞中山金杯 4歳以上オープン（国際）（特指）",
 *         "grade":      "G3"
 *       },
 *       ...
 *     ]
 *   }
 *
 * 【Laravel からの呼び出し例】
 *   $result = shell_exec('node /path/to/keibaOddsGetRaceGrade.mjs --yearmonth=2023-01');
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
    process.stderr.write('Usage: node keibaOddsGetRaceGrade.mjs --yearmonth=2023-01\n');
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
const lockFile  = join(__dirname, `keibaOddsGetRaceGrade_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetRaceGrade.log'), { flags: 'a' });

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
    const allData = [];

    try {
        log('================================================================');
        log(`keibaOddsGetRaceGrade 開始 yearmonth=${yearmonth}`);
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
                    kaisuu:    parseInt(m[1]),
                    bashoName: m[2],
                    day:       parseInt(m[3]),
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
        // 【ブロック 10】各開催のグレード取得ループ
        //
        //   各開催につき:
        //     (10-1) 開催一覧へ戻り → 開催ボタンクリック
        //            ※ 毎回 navigateToKaisaiList をやり直す（ページ遷移後に一覧が消えるため）
        //     (10-2) レース一覧テーブルをパース
        //
        //   グレードの抽出:
        //     td.race_name 内の span.grade_icon > img の src ファイル名で判定。
        //     ファイル名パターン → グレード文字列のマッピングは
        //     ブロック 1 のコメント（DOM構造）を参照。
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

            // (10-2) 日付取得 + テーブルパース（グレード抽出）
            const { date, races } = await page.evaluate(() => {

                // span.grade_icon > img の src ファイル名でグレードを返す
                // グレードなしは null
                function extractGrade(cell) {
                    const img = cell.querySelector('span.grade_icon img');
                    if (!img) return null;
                    const file = (img.getAttribute('src') || '').split('/').pop();
                    if (file.includes('j_g1') || file.includes('j-g1') || file.includes('jg1')) return 'J-G1';
                    if (file.includes('j_g2') || file.includes('j-g2') || file.includes('jg2')) return 'J-G2';
                    if (file.includes('j_g3') || file.includes('j-g3') || file.includes('jg3')) return 'J-G3';
                    if (file.includes('_g1'))    return 'G1';
                    if (file.includes('_g2'))    return 'G2';
                    if (file.includes('_g3'))    return 'G3';
                    if (file.includes('listed')) return 'L';
                    return null;
                }

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

                    // 動画セルを起点にレースを特定
                    const videoTd = tds.find(td => td.textContent.includes('eqPlayerButtonByAccount'));
                    if (!videoTd) return;

                    const raceIdMatch = videoTd.textContent.match(/eqPlayerButtonByAccount\('(\d{12})'/);
                    if (!raceIdMatch) return;
                    const race = parseInt(raceIdMatch[1].slice(-2), 10);

                    const videoIdx = tds.indexOf(videoTd);

                    // レース名セル = 動画セルの1つ前
                    const nameTd = videoIdx > 0 ? tds[videoIdx - 1] : null;
                    const race_name = nameTd
                        ? nameTd.textContent.replace(/\s+/g, ' ').trim()
                        : '';

                    const grade = nameTd ? extractGrade(nameTd) : null;

                    races.push({ race, race_name, grade });
                });

                races.sort((a, b) => a.race - b.race);
                return { date, races };
            });

            if (races.length === 0) {
                log(`  WARNING: レースデータが見つかりませんでした。`);
                continue;
            }

            // グレードありのレースのみ記録・ログ出力
            races.forEach(r => {
                if (r.grade === null) return;
                log(`    [${r.race}R] ${r.race_name} / grade=${r.grade}`);
                allData.push({
                    date,
                    kaisuu,
                    basho_name: bashoName,
                    basho_code,
                    day,
                    race:      r.race,
                    race_name: r.race_name,
                    grade:     r.grade,
                });
            });
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】JSON 出力
        //   グレードありのレースのみ含む
        // ─────────────────────────────────────────────────────
        log(`\n完了 — グレードあり ${allData.length} レース取得`);
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
