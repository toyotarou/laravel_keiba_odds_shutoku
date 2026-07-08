/**
 * keibaOddsGetPayout.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」から、指定した年月・開催の全レースの払戻金を取得する。
 *
 * 【取得する券種】
 *   単勝・複勝・枠連・ワイド・馬連・馬単・3連複・3連単
 *
 * 【払戻金の DOM 構造】
 *   「全てのレースを表示」後のページの div#race_result_NR 内
 *   li.win / li.place / li.wakuren / li.wide / li.umaren / li.umatan / li.trio / li.tierce
 *     └─ dl
 *          ├─ dt（券種名）
 *          └─ dd
 *               └─ div.line（1点ごとの払戻データ）
 *                    ├─ div.num   （組合せ馬番）
 *                    ├─ div.yen   （払戻金額）
 *                    └─ div.pop   （人気順位）
 *
 * 【使い方】
 *   node keibaOddsGetPayout.mjs --yearmonth=2023-01 --kaisai=1回中山1日
 *   node keibaOddsGetPayout.mjs --yearmonth=2023-01   （--kaisai 省略で全開催）
 *   node keibaOddsGetPayout.mjs --yearmonth=2023-01 --list-only   （開催名一覧のみ）
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
//   --kaisai=X回場所Y日  : 特定の開催のみ取得（省略で全開催）
//   --list-only          : 開催名一覧だけ返して終了（デバッグ用）
// ─────────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const yearmonthArg = args.find(a => a.startsWith('--yearmonth='));
const kaisaiArg    = args.find(a => a.startsWith('--kaisai='));
const listOnly     = args.includes('--list-only'); // 開催名一覧モードフラグ

if (!yearmonthArg) {
    process.stderr.write('Usage: node keibaOddsGetPayout.mjs --yearmonth=2023-01 [--kaisai=1回中山1日]\n');
    process.exit(1);
}
const yearmonth    = yearmonthArg.split('=')[1];
const kaisaiFilter = kaisaiArg ? kaisaiArg.split('=')[1] : null; // 省略時は null
const [year, month] = yearmonth.split('-');

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所コードマッピング
//   JRAの場所コード（2桁数字文字列）と漢字名の対応表。
//   払戻データに場所コードを付与するために使用する。
// ─────────────────────────────────────────────────────────────
const bashoMap = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ロック・ログ設定
//   ロックキー: kaisai 指定あり → "YYYY-MM_X回場所Y日"
//               kaisai 指定なし → "YYYYMM"
//   これにより同じ対象の二重起動を防ぎつつ、
//   別の kaisai や月は同時実行できる。
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey  = kaisaiFilter
    ? `${yearmonth}_${kaisaiFilter}`.replace(/\s/g, '')
    : yearmonth.replace('-', '');
const lockFile  = join(__dirname, `keibaOddsGetPayout_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetPayout.log'), { flags: 'a' }); // 追記モード

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// PID が現在も生きているかを確認する（スタールロック対策）
function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】開催一覧ページへの遷移ヘルパー関数
//   keibaOddsGetFinishingPosition.mjs / keibaOddsGetRaceResultHistory.mjs
//   と同一の処理。JRAデータベースを開き、年月セレクトを操作して
//   指定年月の開催一覧を表示させる。
//
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

    // 「過去のレース結果」テキストのリンクをクリック
    await page.evaluate(() => {
        Array.from(document.querySelectorAll('a'))
            .find(el => el.textContent.trim() === '過去のレース結果')?.click();
    });
    await page.waitForSelector('select', { timeout: 15000 }).catch(() => {});
    await sleep(1500);

    // 年・月セレクトに値をセットして change イベントを発火
    await page.evaluate(({ y, m }) => {
        const selects = document.querySelectorAll('select');
        if (selects[0]) { selects[0].value = y; selects[0].dispatchEvent(new Event('change')); }
        if (selects[1]) { selects[1].value = m.padStart(2, '0'); selects[1].dispatchEvent(new Event('change')); }
    }, { y: year, m: month });
    await sleep(500);

    // 検索リンクをクリック（見つからない場合はグローバル関数を直接呼ぶ）
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
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: [] }));
            logStream.end();
            process.exit(0);
        }
        unlinkSync(lockFile); // スタールロックを削除
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = []; // 全開催分の払戻データを格納する配列

    try {
        log('================================================================');
        log(`keibaOddsGetPayout 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter ?? '全開催'}`);
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
        //   ページ内の "X回場所Y日" 形式のリンクをすべて収集し、
        //   kaisaiFilter が指定されている場合はフィルタリングする。
        // ─────────────────────────────────────────────────────
        await navigateToKaisaiList(page, year, month);

        let kaisaiList = await page.evaluate(() => {
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

        // --kaisai が指定されていた場合は該当の開催だけに絞る
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】各開催の払戻金取得ループ
        //   各開催につき:
        //     (10-1) 開催一覧へ戻り → 開催ボタンクリック
        //     (10-2) 「全てのレースを表示」クリック
        //     (10-3) div#race_result_NR 内の払戻データをパース
        //     (10-4) allData に追加
        // ─────────────────────────────────────────────────────
        for (const kaisai of kaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho = bashoMap[bashoName] ?? null; // 場所コード（未定義なら null）
            log(`\n[開催] ${kaisaiText}`);

            // (10-1) 開催一覧ページへ再遷移し、開催ボタンをクリック
            //         毎回 navigateToKaisaiList を呼ぶことで安定したスタート地点を確保
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

            // (10-2) 「全てのレースを表示」クリック
            const clickedAll = await page.evaluate(() => {
                const target = Array.from(document.querySelectorAll('a, button'))
                    .find(el => el.textContent.includes('全てのレースを表示'));
                if (target) { target.click(); return true; }
                return false;
            });
            if (clickedAll) await sleep(2000); // ページが展開するまで待機

            // (10-3) ページ内の全レース払戻金を一括パース
            //
            // DOM 構造:
            //   div[id^="race_result_"]      : レース1件ごとのコンテナ
            //     h2 .race_name             : レース名
            //     li.win                    : 単勝
            //     li.place                  : 複勝
            //     li.wakuren                : 枠連
            //     li.wide                   : ワイド
            //     li.umaren                 : 馬連
            //     li.umatan                 : 馬単
            //     li.trio                   : 3連複
            //     li.tierce                 : 3連単
            //       dl > dt                 : 券種名
            //       dl > dd > div.line      : 1点ごとの払戻データ
            //         div.num              : 組合せ馬番（例: "3 - 7"）
            //         div.yen              : 払戻金額（例: "¥1,200"）
            //         div.pop              : 人気順位（例: "2人気"）
            const { date, races } = await page.evaluate(() => {
                // ページの日付を h1 から取得
                let date = null;
                for (const h1 of document.querySelectorAll('h1')) {
                    const m = h1.textContent.match(/(\d{4})年(\d{1,2})月(\d{1,2})日/);
                    if (m) {
                        date = `${m[1]}-${m[2].padStart(2,'0')}-${m[3].padStart(2,'0')}`;
                        break;
                    }
                }

                const races = [];

                // id が "race_result_" で始まる div を全て取得
                document.querySelectorAll('div[id^="race_result_"]').forEach(div => {
                    const idMatch = div.id.match(/race_result_(\d+)R/);
                    if (!idMatch) return;
                    const raceNum = parseInt(idMatch[1], 10);

                    // レース名を取得
                    const h2 = div.querySelector('h2 .race_name');
                    const raceName = h2 ? h2.textContent.trim() : '';

                    // 各券種の払戻データを収集
                    const payouts = [];
                    div.querySelectorAll('li.win, li.place, li.wakuren, li.wide, li.umaren, li.umatan, li.trio, li.tierce').forEach(li => {
                        const betType = li.querySelector('dt')?.textContent.replace(/\s+/g, '').trim() ?? ''; // 券種名（単勝・複勝・馬連 等）

                        li.querySelectorAll('div.line').forEach(line => {
                            // 組合せ馬番（複数馬の場合は "3 - 7 - 12" のような形式）
                            const combo      = line.querySelector('div.num')?.textContent.replace(/\s+/g, ' ').trim() ?? '';
                            // 払戻金額（数字以外を除去して整数化）
                            const yenRaw     = line.querySelector('div.yen')?.textContent.replace(/[^\d]/g, '') ?? '';
                            const amount     = yenRaw ? parseInt(yenRaw, 10) : null;
                            // 人気順位（数字以外を除去して整数化）
                            const popRaw     = line.querySelector('div.pop')?.textContent.replace(/[^\d]/g, '') ?? '';
                            const popularity = popRaw ? parseInt(popRaw, 10) : null;
                            if (combo && amount !== null) {
                                payouts.push({ type: betType, combo, amount, popularity });
                            }
                        });
                    });

                    races.push({ race: raceNum, race_name: raceName, payouts });
                });

                // レース番号の昇順でソート
                races.sort((a, b) => a.race - b.race);
                return { date, races };
            });

            if (races.length === 0) {
                log(`  WARNING: 払戻金データが見つかりませんでした。`);
                continue;
            }

            // ─────────────────────────────────────────────────
            // 【ブロック 11】レースごとの払戻ログ出力
            // ─────────────────────────────────────────────────
            races.forEach(r => {
                if (r.payouts.length > 0) {
                    log(`    [Race ${r.race}R] OK → ${r.payouts.length}件 (${r.race_name})`);
                } else {
                    log(`    [Race ${r.race}R] WARNING: 払戻金データなし (${r.race_name})`);
                }
            });

            // (10-4) この開催のデータを allData に追加
            //        各レースに日付を付与するため races.map で date を追加する
            allData.push({
                date,
                kaisuu,
                basho: bashoName,
                basho_code: basho,
                day,
                races: races.map(r => ({ ...r, date })), // レースごとに日付を付与
            });
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】JSON 出力
        //   kaisai を1件指定 → allData[0] をそのまま展開してシンプルな形式で出力
        //   kaisai を省略   → data 配列として全開催をまとめて出力
        // ─────────────────────────────────────────────────────
        log(`\n完了 — 合計 ${allData.length} 開催 / ${allData.reduce((s, k) => s + k.races.length, 0)} レース取得`);

        let output;
        if (kaisaiFilter && allData.length === 1) {
            // kaisai 指定かつ1件のみ → フラットな形式で出力
            const { date, kaisuu, basho, basho_code, day, races } = allData[0];
            output = { yearmonth, kaisai: kaisaiFilter, date, kaisuu, basho, basho_code, day, races };
        } else {
            // 全開催 or 複数件 → data 配列として出力
            output = { yearmonth, data: allData };
        }
        console.log(JSON.stringify(output, null, 2));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 13】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: allData, error: err.message }));
        process.exitCode = 1;
    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 14】後処理（必ず実行）
        // ─────────────────────────────────────────────────────
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        logStream.end();
    }
})();
