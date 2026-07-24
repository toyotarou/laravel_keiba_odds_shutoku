/**
 * keibaOddsGetRaceResultHistory.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」から、指定した年月・開催の全レースの
 *   単勝・複勝オッズ（最終オッズ）を取得する。
 *
 * ════════════════════════════════════════════════════════════════
 * 【ブラウザで追える進行順路】
 *   ※ 実際に Chrome で同じ操作をすると、スクリプトの動きを確認できる
 * ════════════════════════════════════════════════════════════════
 *
 * ▼ STEP A ── JRAトップページへアクセス
 *   URL: https://www.jra.go.jp/
 *   ※ スクリプトは実際には https://www.jra.go.jp/JRADB/accessS.html へ直接アクセスする。
 *      ブラウザで手動追跡する場合は JRAトップ →「レース結果」→「過去のレース結果」
 *      と進んでも同じ画面に到達できる。
 *   ページ名: 「JRA 日本中央競馬会」公式トップページ
 *
 * ▼ STEP B ── 「過去のレース結果」リンクをクリック
 *   操作: JRAデータベース（accessS.html）の左メニューから「過去のレース結果」をクリック
 *   変化:
 *     同ページ右エリア（またはフレーム内）に
 *     「年」「月」のセレクトボックスと検索ボタンが表示される。
 *
 * ▼ STEP C ── 年・月を選択して検索ボタンをクリック
 *   操作:
 *     (1) 1番目の <select> で「年」を選ぶ（例: 2021）
 *     (2) 2番目の <select> で「月」を選ぶ（例: 01）
 *     (3) onclick="getSelectData();" が付いたリンクをクリック
 *   変化:
 *     指定した年月に開催された競馬場・回・日の一覧がリンク形式で表示される
 *     （例: 3回東京1日 / 1回中山1日 …）
 *
 * ▼ STEP D ── 開催一覧を収集 / 処理対象を確定
 *   操作: なし（ページを読み取るだけ）
 *   特記:
 *     ・--kaisai 指定あり → その開催名に一致するリンクだけを対象にする
 *     ・--kaisai 省略    → 表示されている全開催を対象にする
 *     ・--list-only      → 開催名一覧を JSON で出力してここで終了
 *
 * ▼ STEP E ── 各開催について外側ループ（対象開催の数だけ繰り返す）
 *
 *   ┌─ E-1: 開催一覧へ戻り、開催ボタンをクリック
 *   │   操作:
 *   │     毎回 accessS.html →「過去のレース結果」→ 年月選択 → 検索 をやり直してから
 *   │     「3回東京1日」等の開催リンクをクリック
 *   │     ※ 毎回やり直す理由: ページ遷移後に開催一覧が消えるため、安定したスタート地点を確保する
 *   │   変化: その開催のレース一覧ページへ遷移する
 *   │
 *   ├─ E-2: 「全てのレースを表示」ボタンをクリック
 *   │   操作: ページ内の「全てのレースを表示」リンクをクリック
 *   │          ※ ボタンがない場合（既に全件表示中）はスキップして続行
 *   │   変化: 1R〜最終Rの全レース一覧が展開される。
 *   │          各レースに「単複オッズ」リンクが並ぶ。
 *   │
 *   ├─ E-3: 単複オッズリンクを全レース分まとめて収集（操作なし）
 *   │   操作: なし（JavaScriptでDOMを読み取るだけ）
 *   │   収集対象:
 *   │     onclick に doAction('/JRADB/accessO.html', 'pw151ou...Z/...') 形式を持つリンク
 *   │   コード解析:
 *   │     "pw151ou000506202101150108202101150Z" のようなコードから
 *   │     substring(19, 21) → レース番号（2桁）
 *   │     substring(21, 29) → 日付（YYYYMMDD）
 *   │     を抽出し、レース番号の昇順にソートする
 *   │
 *   └─ E-4: 各レースの単複オッズページを順番に訪問（内側ループ）
 *
 *       ┌─ F-1: 単複オッズリンクをクリック → accessO.html へ遷移
 *       │   操作: 対象レースの「単複オッズ」リンクをクリック
 *       │   変化: 単複オッズページ（accessO.html）へ遷移する。
 *       │          table.tanpuku が表示される。
 *       │
 *       ├─ F-2: table.tanpuku をパース（操作なし）
 *       │   操作: なし（JavaScriptでDOMを読み取るだけ）
 *       │   対象要素:
 *       │     table.tanpuku > tbody > tr（1行＝1頭）
 *       │       ※ 枠番セル（rowspan あり）の有無で列インデックスがずれる
 *       │         10列以上 → 枠番セルあり → offset=1（馬番は td[1]）
 *       │          9列以下 → 枠番セルなし → offset=0（馬番は td[0]）
 *       │       td[offset+0] 馬番
 *       │       td[offset+1] 馬名
 *       │       td[offset+2] 単勝オッズ
 *       │       td[offset+3] 複勝オッズ（"1.2-2.4" 形式 → "-" で分割して最小・最大に変換）
 *       │   レース名は h2 タグから取得（「検索ウィンドウ」等のダミー h2 は除外）
 *       │
 *       └─ F-3: ブラウザの「戻る」で全レース一覧ページへ戻る
 *           操作: page.goBack() → 「全てのレースを表示」済みのページへ戻る
 *           変化: E-4 の内側ループの先頭へ戻り、次のレースへ進む
 *
 * ▼ 全開催ループ終了後 ── JSON を stdout に出力
 *   kaisai 指定あり → { yearmonth, kaisai, date, kaisuu, basho, basho_code, day, races }
 *   kaisai 省略    → { yearmonth, data: [ 開催1, 開催2, ... ] }
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【使い方】
 *   node keibaOddsGetRaceResultHistory.mjs --yearmonth=2021-01 --kaisai=3回東京1日
 *   node keibaOddsGetRaceResultHistory.mjs --yearmonth=2021-01   （全開催）
 *   node keibaOddsGetRaceResultHistory.mjs --yearmonth=2021-01 --list-only  （開催名一覧）
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
//   --kaisai=X回場所Y日  : 特定の開催のみ（省略で全開催）
//   --list-only          : 開催名一覧を返して終了（デバッグ用）
// ─────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所コードマッピング
//   漢字名 → JRA の2桁数字コード
//   DBへの保存時に数字コードが必要なため変換する。
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
// ─────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】開催一覧ページへの遷移ヘルパー関数
//   keibaOddsGetPayout.mjs と同一の処理。
//   accessS.html → 年月セレクト操作 → 検索 → 開催一覧表示
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
// 【ブロック 6】単複オッズリンクの収集関数（getOddsLinks）
//   「全てのレースを表示」後のページには、各レースの
//   「単複オッズ」リンクが accessO.html 形式で存在する。
//
//   onclick の書式:
//     doAction('/JRADB/accessO.html', 'pw151ou??_<コード>Z/<ハッシュ>')
//
//   コードの構造（インデックス）:
//     0〜8   : "pw151ou" + 識別子
//     9〜10  : 場所コード
//     11〜14 : 年（4桁）
//     15〜16 : 開催回（2桁）
//     17〜18 : 開催日次（2桁）
//     19〜20 : レース番号（2桁）← substring(19, 21) で取得
//     21〜28 : 日付 YYYYMMDD    ← substring(21, 29) で取得
//
//   例: "pw151ou000506202101150108202101150Z"
//       ↑場所05 年2021 回数01 日次15 レース01 日付20210115
// ─────────────────────────────────────────────────────────────
async function getOddsLinks(page) {
    const rawLinks = await page.evaluate(() => {
        const result = [];
        document.querySelectorAll('a').forEach(a => {
            const onclick = a.getAttribute('onclick') ?? '';
            // accessO.html への doAction 呼び出しで pw151ou で始まるコードを持つリンクを収集
            const m = onclick.match(/doAction\('\/JRADB\/accessO\.html',\s*'(pw151ou\w+Z)\/[0-9A-F]+'\)/);
            if (m) result.push({ code: m[1], onclick });
        });
        return result;
    });

    // コードを解析してレース番号と日付を抽出し、レース番号の昇順でソートして返す
    return rawLinks.map(({ code, onclick }) => {
        const raceNum = parseInt(code.substring(19, 21), 10); // 19〜20文字目がレース番号
        const d       = code.substring(21, 29);               // 21〜28文字目が日付 YYYYMMDD
        const date    = `${d.substring(0,4)}-${d.substring(4,6)}-${d.substring(6,8)}`; // YYYY-MM-DD 形式
        return { code, onclick, raceNum, date };
    })
    .filter(l => !isNaN(l.raceNum)) // レース番号が正常に取れたもののみ
    .sort((a, b) => a.raceNum - b.raceNum); // レース番号の昇順でソート
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
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: [] }));
            logStream.end();
            process.exit(0);
        }
        unlinkSync(lockFile); // スタールロック削除
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = []; // 全開催分のデータを格納する配列

    try {
        log('================================================================');
        log(`keibaOddsGetRaceResultHistory 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter ?? '全開催'}`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】ブラウザ起動
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】開催一覧の取得とフィルタリング
        //   kaisaiFilter が指定された場合は該当の1件のみ処理する。
        //   --list-only の場合は開催名一覧だけを返して終了する。
        // ─────────────────────────────────────────────────────
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

        // kaisai 指定時はフィルタ
        if (kaisaiFilter) {
            kaisaiList = kaisaiList.filter(k => k.text === kaisaiFilter.replace(/\s+/g, ''));
            if (kaisaiList.length === 0) {
                log(`ERROR: 「${kaisaiFilter}」が見つかりませんでした。`);
                console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: [] }));
                return;
            }
        }

        log(`対象開催: ${kaisaiList.map(k => k.text).join(', ')}`);

        // --list-only: 開催名一覧だけ返して終了（実際のスクレイピングは行わない）
        if (listOnly) {
            console.log(JSON.stringify({ yearmonth, kaisaiList: kaisaiList.map(k => k.text) }));
            return;
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】各開催のオッズ取得ループ
        //   (11-1) 開催一覧に戻り → 開催ボタンクリック → レース一覧へ
        //   (11-2) 「全てのレースを表示」クリック → 全レース展開
        //   (11-3) 全レースの単複オッズリンク（accessO.html）を収集
        //   (11-4) 各リンクをクリック → 単複オッズページ → パース → 戻る
        //   (11-5) allData に追加
        // ─────────────────────────────────────────────────────
        for (const kaisai of kaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho = bashoMap[bashoName] ?? null;
            log(`\n[開催] ${kaisaiText}`);

            // (11-1) 毎回開催一覧から再スタートして安定性を確保
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

            // (11-2) 「全てのレースを表示」クリック
            const clickedAll = await page.evaluate(() => {
                const target = Array.from(document.querySelectorAll('a, button'))
                    .find(el => el.textContent.includes('全てのレースを表示'));
                if (target) { target.click(); return true; }
                return false;
            });
            if (clickedAll) await sleep(2000);

            // (11-3) 全レースの単複オッズリンクを収集（レース番号の昇順でソート済み）
            const oddsLinks = await getOddsLinks(page);
            if (oddsLinks.length === 0) {
                log(`  WARNING: オッズリンクが見つかりませんでした。スキップします。`);
                continue;
            }
            log(`  ${oddsLinks.length} レース: ${oddsLinks.map(o => o.raceNum).join(', ')}R`);

            const races = []; // この開催のレースデータを格納

            // (11-4) 各レースの単複オッズを順番に取得
            for (const { code, raceNum, date: raceDate } of oddsLinks) {
                log(`    [Race ${raceNum}R] 取得中...`);

                // code を含む onclick のリンクをクリックしてオッズページへ遷移
                await page.evaluate(({ code }) => {
                    Array.from(document.querySelectorAll('a'))
                        .find(a => (a.getAttribute('onclick') ?? '').includes(code))?.click();
                }, { code });

                // table.tanpuku が出るまで最大15秒待つ
                await page.waitForSelector('table.tanpuku', { timeout: 15000 }).catch(() => {});
                await sleep(1000);

                // ─────────────────────────────────────────────
                // 【ブロック 12】単複オッズテーブルのパース
                //   table.tanpuku の列順（過去レース結果版）:
                //     枠あり行 (10列): [枠][馬番][馬名][単勝][複勝][...]
                //     枠なし行  (9列): [馬番][馬名][単勝][複勝][...]
                //   offset で枠の有無による列ズレを吸収する。
                //   複勝オッズは "1.2-2.4" 形式なので "-" で分割して最小・最大を取得。
                // ─────────────────────────────────────────────
                const { raceName, horses } = await page.evaluate(() => {
                    // レース名を h2 から取得（検索窓やお知らせなどのダミー h2 を除外）
                    const h2s = Array.from(document.querySelectorAll('h2'));
                    const raceNameEl = h2s.find(el => {
                        const t = el.textContent.trim();
                        return t && t !== '検索ウィンドウ' && t !== 'JRAからのお知らせ';
                    });
                    const raceName = raceNameEl
                        ? raceNameEl.textContent.replace(/\s+/g, ' ').trim()
                        : '';

                    const horses = [];
                    const table = document.querySelector('table.tanpuku');
                    if (!table) return { raceName, horses }; // テーブルが無い場合は空を返す

                    table.querySelectorAll('tbody tr').forEach(row => {
                        const cells = Array.from(row.querySelectorAll('td'));
                        if (cells.length < 4) return;

                        // 枠番セル（rowspan あり）の有無で列インデックスが変わる
                        // 10列以上: 枠番セルあり → offset=1（1列分ずらす）
                        //  9列以下: 枠番セルなし → offset=0
                        const offset  = cells.length >= 10 ? 1 : 0;
                        const num     = cells[offset]?.textContent.trim()     ?? ''; // 馬番
                        const name    = cells[offset + 1]?.textContent.trim() ?? ''; // 馬名
                        const tan     = cells[offset + 2]?.textContent.trim() ?? ''; // 単勝オッズ
                        const fukuRaw = cells[offset + 3]?.textContent.trim() ?? ''; // 複勝オッズ（範囲）

                        if (!num.match(/^\d+$/) || !name) return; // 馬番が数字でない行はスキップ

                        // 複勝オッズを最小・最大に分割（"1.2-2.4" → [1.2, 2.4]）
                        const fukuParts = fukuRaw.split('-').map(s => s.trim());
                        horses.push({
                            num:      parseInt(num),
                            name,
                            tan:      parseFloat(tan)                          || null, // 単勝
                            fuku_min: parseFloat(fukuParts[0])                 || null, // 複勝下限
                            fuku_max: parseFloat(fukuParts[1] ?? fukuParts[0]) || null, // 複勝上限（単一値の場合は同じ値）
                        });
                    });
                    return { raceName, horses };
                });

                if (horses.length > 0) {
                    log(`    [Race ${raceNum}R] OK → ${horses.length}頭 (${raceName})`);
                    races.push({ race: raceNum, race_name: raceName, date: raceDate, horses });
                } else {
                    log(`    [Race ${raceNum}R] WARNING: データなし`);
                }

                // 「全てのレースを表示」ページへ戻る（次のレースを取得するため）
                await page.goBack({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
                await sleep(1000);
            }

            // (11-5) この開催のデータを allData に追加
            if (races.length > 0) {
                const date = races[0].date; // 最初のレースの日付を開催日とする
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 13】JSON 出力
        //   kaisai を1件指定 → フラット形式で出力
        //   kaisai を省略   → data 配列として全開催をまとめて出力
        // ─────────────────────────────────────────────────────
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
        // ─────────────────────────────────────────────────────
        // 【ブロック 14】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, data: allData, error: err.message }));
        process.exitCode = 1;
    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 15】後処理（必ず実行）
        // ─────────────────────────────────────────────────────
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        logStream.end();
    }
})();
