/**
 * keibaOddsGetRaceInnerOuter.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」から、指定した年月の全開催・全レースの
 *   「外/内コース」情報を一括取得する。
 *
 *   対象競馬場: 新潟・京都・中山・阪神
 *   （これら4場は外回り/内回りコースが存在する）
 *   その他の競馬場はスキップ（inner_outer = null）。
 *
 * 【取得する項目（レースごと）】
 *   date, kaisuu, basho_name, basho_code, day,
 *   race（レース番号）, race_name, inner_outer（"外" / "内" / null）
 *
 * 【仕組み】
 *   keibaOddsGetRaceCourseDist.mjs と同様にレース一覧ページへ遷移した後、
 *   各レースの「レース結果」ボタン（行の先頭セル）をクリックして
 *   詳細ページの「コース：●,●●●メートル（芝・右 外）」を解析する。
 *
 *   ダートレースは外/内の区別がないため inner_outer = null とする。
 *
 * 【DOM（レース詳細ページ）】
 *   "コース：X,XXXメートル（芝・右 外）"  → inner_outer = "外"
 *   "コース：X,XXXメートル（芝・右 内）"  → inner_outer = "内"
 *   "コース：X,XXXメートル（ダート・右）"  → inner_outer = null
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
 *   → 指定年月の全開催一覧が表示される
 *
 * ▼ STEP D ── 対象4場（新潟・京都・中山・阪神）の開催をループ
 *   → 開催ボタンクリック → レース一覧ページへ
 *
 * ▼ STEP E ── 各レースの「レース結果」ボタンをクリック
 *   → 詳細ページの「コース：」テキストから外/内を抽出
 *   → 戻ってレース一覧へ
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【使い方】
 *   node keibaOddsGetRaceInnerOuter.mjs --yearmonth=2023-01
 *   node keibaOddsGetRaceInnerOuter.mjs --yearmonth=2023-01 --list-only
 *
 * 【出力 JSON 形式】
 *   {
 *     "yearmonth": "2023-01",
 *     "data": [
 *       {
 *         "date": "2023-01-05",
 *         "kaisuu": 1,
 *         "basho_name": "中山",
 *         "basho_code": "06",
 *         "day": 1,
 *         "race": 5,
 *         "race_name": "3歳未勝利",
 *         "inner_outer": "外"
 *       },
 *       ...
 *     ]
 *   }
 *
 * 【Laravel からの呼び出し例】
 *   $result = shell_exec('node /path/to/keibaOddsGetRaceInnerOuter.mjs --yearmonth=2023-01');
 *   $data = json_decode($result, true);
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { existsSync, writeFileSync, unlinkSync, readFileSync } from 'fs';
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
    process.stderr.write('Usage: node keibaOddsGetRaceInnerOuter.mjs --yearmonth=2023-01\n');
    process.exit(1);
}

const yearmonth     = yearmonthArg.split('=')[1];
const [year, month] = yearmonth.split('-');

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所コードマッピング・対象競馬場定義
// ─────────────────────────────────────────────────────────────
const bashoMap = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

// 外/内コースが存在する4競馬場
const INNER_OUTER_BASHO = new Set(['新潟', '京都', '中山', '阪神']);

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ロック・ログ設定
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey   = yearmonth.replace('-', '');
const lockFile  = join(__dirname, `keibaOddsGetRaceInnerOuter_${lockKey}.lock`);

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】開催一覧ページへの遷移ヘルパー関数
//   keibaOddsGetRaceCourseDist.mjs と同じ実装
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
// 【ブロック 6】レース詳細ページから外/内を抽出するヘルパー
//
//   「コース：1,600メートル（芝・右 外）」のような文字列を解析する。
//   ・"外" が含まれていれば "外"
//   ・"内" が含まれていれば "内"
//   ・どちらもなければ null（ダートなど内外区別なし）
// ─────────────────────────────────────────────────────────────
async function extractInnerOuter(page) {
    return page.evaluate(() => {
        // "コース：" を含むテキストノードを幅広く探す
        const allText = document.body.innerText;
        const courseMatch = allText.match(/コース[：:][^\n]+/);
        if (!courseMatch) return { inner_outer: null, courseText: '(コース行なし)' };

        const courseText = courseMatch[0];

        // JRAの表記ルール:
        //   外回り → 「（芝・右 外）」と明示
        //   内回り → 「（芝・右）」と書くだけで "内" は表記しない
        // → "外" がなければ "内" と判断する
        // 障害コースは「（芝 外内）」のように「外内」が連続して表記される → null
        // 直線コース（新潟芝1000m）は「（芝・直）」と表記される → null
        if (/外内/.test(courseText)) return { inner_outer: null, courseText };
        if (/直/.test(courseText))   return { inner_outer: null, courseText };
        if (/外/.test(courseText)) return { inner_outer: '外', courseText };
        return { inner_outer: '内', courseText };
    });
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 7】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8】二重起動防止（スタールロック考慮）
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ yearmonth, data: [] }));
            process.exit(0);
        }
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = [];

    try {
        log('================================================================');
        log(`keibaOddsGetRaceInnerOuter 開始 yearmonth=${yearmonth}`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】ブラウザ起動ヘルパー
        //   開催ごとに再起動するためファクトリ関数として定義する
        // ─────────────────────────────────────────────────────
        const launchBrowser = async () => {
            if (browser) await browser.close().catch(() => {});
            browser = await chromium.launch({
                headless: true,
                args: ['--no-sandbox', '--disable-setuid-sandbox'],
            });
            const page = await browser.newPage();
            await page.setViewportSize({ width: 1280, height: 800 });
            return page;
        };

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】開催一覧の取得（専用ブラウザで取得後すぐ閉じる）
        // ─────────────────────────────────────────────────────
        let page = await launchBrowser();
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

        log(`全開催 ${kaisaiList.length}件: ${kaisaiList.map(k => k.text).join(', ')}`);

        // 対象4場のみに絞る
        const targetKaisaiList = kaisaiList.filter(k => INNER_OUTER_BASHO.has(k.bashoName));
        log(`対象開催（新潟・京都・中山・阪神）: ${targetKaisaiList.length}件`);

        if (listOnly) {
            console.log(JSON.stringify({
                yearmonth,
                allKaisai:    kaisaiList.map(k => k.text),
                targetKaisai: targetKaisaiList.map(k => k.text),
            }));
            return;
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】各開催の外/内取得ループ
        //
        //   対象4場の各開催につき:
        //     (11-1) 開催一覧へ再遷移 → 開催ボタンクリック
        //     (11-2) レース一覧テーブルからレース情報を収集
        //            （レース番号・レース名・レース結果ページURL）
        //     (11-3) 各レースの結果ページへ遷移 → 外/内を抽出 → 戻る
        //
        //   DOM構造（レース選択ページのテーブル）:
        //     table > tbody > tr
        //       td[0] : レース番号セル（"1R" など、クリックでレース結果へ）
        //       td[1] : レース名
        //       td[2] : レース映像ボタン（eqPlayerButtonByAccount を含む）
        //       td[3] : 距離（"1,200メートル"）
        //       td[4] : 馬場（"ダート" / "芝"）
        //
        //   レース詳細ページ:
        //     "コース：1,600メートル（芝・右 外）" → 外
        //     "コース：1,600メートル（芝・右 内）" → 内
        //     "コース：1,200メートル（ダート・右）" → null（ダートは内外なし）
        // ─────────────────────────────────────────────────────
        for (const kaisai of targetKaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho_code = bashoMap[bashoName] ?? null;
            log(`\n[開催] ${kaisaiText}`);

            // (11-1) 開催ごとにブラウザを再起動してメモリリークを防ぐ
            //        → 長時間実行時のブラウザクラッシュ対策
            page = await launchBrowser();
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

            // (11-2) レース一覧から日付・レース情報を収集
            //   ※ レース結果ボタン（td[0]のリンク）の href を収集する
            const { date, raceLinks } = await page.evaluate(() => {
                // 日付を見出しタグから取得
                let date = null;
                for (const el of document.querySelectorAll('h1, h2, h3')) {
                    const m = el.textContent.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
                    if (m) {
                        date = `${m[1]}-${m[2].padStart(2,'0')}-${m[3].padStart(2,'0')}`;
                        break;
                    }
                }

                const raceLinks = [];

                document.querySelectorAll('table tr').forEach(tr => {
                    const tds = [...tr.querySelectorAll('td')];

                    // 動画セルでレース番号を特定
                    const videoTd = tds.find(td => td.textContent.includes('eqPlayerButtonByAccount'));
                    if (!videoTd) return;

                    const raceIdMatch = videoTd.textContent.match(/eqPlayerButtonByAccount\('(\d{12})'/);
                    if (!raceIdMatch) return;
                    const race = parseInt(raceIdMatch[1].slice(-2), 10);

                    const videoIdx = tds.indexOf(videoTd);
                    const race_name = (videoIdx > 0 ? tds[videoIdx - 1] : null)
                        ?.textContent.replace(/\s+/g, ' ').trim() ?? '';

                    // 馬場セル（course クラスを持つ td）で芝/ダートを確認
                    const course = tr.querySelector('td.course')
                        ?.textContent.replace(/\s+/g, '').trim() ?? '';

                    // レース番号ボタンは <th class="race_num"> 内の <a href> に入っている
                    const raceLink = tr.querySelector('th.race_num a')?.href ?? null;

                    raceLinks.push({ race, race_name, course, raceLink });
                });

                raceLinks.sort((a, b) => a.race - b.race);
                return { date, raceLinks };
            });

            if (raceLinks.length === 0) {
                log(`  WARNING: レースデータが見つかりませんでした。`);
                continue;
            }

            log(`  ${raceLinks.length}レース検出 (日付: ${date})`);

            // (11-3) 各レースの詳細ページへ並列遷移して外/内を抽出
            //   芝レースのみ対象。同時実行数は CONCURRENCY で制限する。
            //   各レースは独立したページで動くため、レース一覧への「戻る」は不要。
            const CONCURRENCY = 3;
            const turfRaces = raceLinks.filter(r => r.course === '芝');

            // 非芝レースはリンクなしとして結果に追加しない（従来と同じ動作）

            // 同時実行数を制限しながら Promise を処理するヘルパー
            const runWithConcurrency = async (items, fn, limit) => {
                const results = [];
                let idx = 0;
                const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
                    while (idx < items.length) {
                        const i = idx++;
                        results[i] = await fn(items[i], i);
                    }
                });
                await Promise.all(workers);
                return results;
            };

            const kaisaiResults = await runWithConcurrency(turfRaces, async (raceInfo) => {
                const { race, race_name, raceLink } = raceInfo;

                if (!raceLink) {
                    log(`    [${race}R] ${race_name} / リンクなし → inner_outer=null`);
                    return { date, kaisuu, basho_name: bashoName, basho_code, day, race, race_name, inner_outer: null };
                }

                // 独立したページで詳細取得（ブラウザ本体は使い回す）
                const racePage = await browser.newPage();
                try {
                    await racePage.goto(raceLink, { waitUntil: 'networkidle', timeout: 30000 });
                    await sleep(1500);

                    const result = await extractInnerOuter(racePage);
                    const inner_outer = result.inner_outer;
                    log(`    [${race}R] ${race_name} / 芝 → inner_outer=${inner_outer}`);

                    if (inner_outer === null) return null;
                    return { date, kaisuu, basho_name: bashoName, basho_code, day, race, race_name, inner_outer };
                } catch (err) {
                    log(`    [${race}R] ${race_name} / 詳細ページ取得エラー: ${err.message}`);
                    return { date, kaisuu, basho_name: bashoName, basho_code, day, race, race_name, inner_outer: null };
                } finally {
                    await racePage.close().catch(() => {});
                }
            }, CONCURRENCY);

            for (const item of kaisaiResults) {
                if (item && item.inner_outer !== null) allData.push(item);
            }
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】JSON 出力
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
    }
})();
