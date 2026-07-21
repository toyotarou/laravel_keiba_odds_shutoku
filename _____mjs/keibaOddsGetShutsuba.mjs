/**
 * keibaOddsGetShutsuba.mjs
 *
 * 【概要】
 *   JRA出馬表から、指定した日付・開催の全レースの出走馬情報（前走〜4走前含む）を取得する。
 *
 * 【スクレイピングフロー】
 *   (1) accessS.html → 「出馬表」クリック → 開催選択ページ（accessD.html）
 *   (2) 開催一覧を収集。--list-only 時は日付付きで JSON 出力して終了
 *   (3) --date 指定時は該当日付の開催のみに絞り込む
 *   (4) --kaisai 指定時はその1開催のみに絞り込む
 *   (5) 各開催ボタンをクリック → レース一覧ページへ
 *   (6) 各レースの「出馬表」リンク（accessD.html?CNAME=pw01dde...）を収集
 *   (7) 各リンクへ page.goto() → 出馬表ページ → 全情報をパース
 *   (8) 全レース分のデータをまとめて JSON 出力
 *
 * 【引数】
 *   --date=YYYY-MM-DD  : 指定日付の開催のみ取得（Laravelから翌日を渡す用途）
 *   --kaisai=X回XX Y日 : 指定開催のみ取得（デバッグ・手動実行用）
 *   --list-only        : 開催名・日付一覧を返して終了（デバッグ用）
 *
 * 【CNAMEコード構造】
 *   pw01dde01 + 場所(2桁) + 年(4桁) + 回(2桁) + 日次(2桁) + レース番号(2桁) + 日付(8桁)
 *   例: pw01dde0103202602050120260711
 *       場所=03(福島) 年=2026 回=02 日次=05 レース=01 日付=20260711
 *
 * 【pw01drl コード構造】（開催選択ページの開催リンク）
 *   pw01drl + 場所(2桁) + 年(4桁) + 回(2桁) + 日次(2桁) + 日付(8桁)
 *   例: pw01drl00032026020520260711
 *       場所=03(福島) 年=2026 回=02 日次=05 日付=20260711
 *   → 末尾8桁（インデックス16〜23）が YYYYMMDD 形式の開催日
 *
 * 【使い方】
 *   node keibaOddsGetShutsuba.mjs --date=2026-07-12
 *   node keibaOddsGetShutsuba.mjs --kaisai=2回福島5日
 *   node keibaOddsGetShutsuba.mjs --list-only
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
//   --date=YYYY-MM-DD  : 指定日付の開催のみ取得（Laravelから翌日を渡す）
//   --kaisai=X回XX Y日 : 指定開催のみ（省略で全開催 or --date で絞り込み）
//   --list-only        : 開催名・日付一覧のみ返して終了
// ─────────────────────────────────────────────────────────────
const args        = process.argv.slice(2);
const dateArg     = args.find(a => a.startsWith('--date='));
const kaisaiArg   = args.find(a => a.startsWith('--kaisai='));
const listOnly    = args.includes('--list-only');

const dateFilter   = dateArg   ? dateArg.split('=')[1]   : null;  // 例: "2026-07-12"
const kaisaiFilter = kaisaiArg ? kaisaiArg.split('=')[1] : null;  // 例: "2回福島5日"

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所コードマッピング
//   漢字名 → JRA の2桁数字コード
// ─────────────────────────────────────────────────────────────
const bashoMap = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ロック・ログ設定
//   ロックキー: date 指定 → "YYYYMMDD"
//              kaisai 指定 → "kaisai名（スペース除去）"
//              両方なし → "all"
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockKey   = dateFilter
    ? dateFilter.replace(/-/g, '')
    : kaisaiFilter
        ? kaisaiFilter.replace(/\s/g, '')
        : 'all';
const lockFile  = join(__dirname, `keibaOddsGetShutsuba_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetShutsuba.log'), { flags: 'a' });

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
// 【ブロック 5】開催選択ページへの遷移ヘルパー
//   accessS.html → 「出馬表」クリック → accessD.html（開催選択）
//   各開催処理の前に毎回呼び出して安定性を確保する。
// ─────────────────────────────────────────────────────────────
async function navigateToKaisaiList(page) {
    await page.goto('https://www.jra.go.jp/JRADB/accessS.html', {
        waitUntil: 'networkidle', timeout: 60000,
    });
    await sleep(1000);

    // 「出馬表」リンクをクリック（onclick="doAction('/JRADB/accessD.html','pw01dli00/F3')"）
    await page.evaluate(() => {
        Array.from(document.querySelectorAll('a'))
            .find(a => a.textContent.trim() === '出馬表' &&
                       (a.getAttribute('onclick') ?? '').includes('accessD'))
            ?.click();
    });
    await page.waitForSelector('a[onclick*="pw01drl"]', { timeout: 15000 }).catch(() => {});
    await sleep(1500);
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 6】開催一覧の収集関数（getKaisaiList）
//   開催選択ページの「X回XX Y日」形式リンクを収集する。
//
//   pw01drl コードの構造:
//     "pw01drl" + 場所(2桁) + 年(4桁) + 回(2桁) + 日次(2桁) + 日付(8桁)
//     例: "pw01drl00032026020520260711"
//              ^^               ^^^^^^^^
//           場所=03            末尾8桁=20260711（開催日）
//   → substring(16, 24) で YYYYMMDD を取得し YYYY-MM-DD に変換する
// ─────────────────────────────────────────────────────────────
async function getKaisaiList(page) {
    const result = await page.evaluate(() => {
        const items = [];
        document.querySelectorAll('a').forEach(a => {
            const text    = a.textContent.replace(/\s+/g, '').trim();
            const onclick = a.getAttribute('onclick') ?? '';

            // 「2回福島5日」「2回福島5日馬番確定」などにマッチ
            const m = text.match(/^(\d+)回(.+?)(\d+)日/);
            if (!m || !onclick.includes('pw01drl')) return;

            // onclick から pw01drl コードを抽出して開催日を取得
            // 例: "return doAction('/JRADB/accessD.html', 'pw01drl00032026020520260711/9D');"
            const codeMatch = onclick.match(/pw01drl(\w+)/);
            const code      = codeMatch ? 'pw01drl' + codeMatch[1] : '';

            // pw01drl(7文字) + 場所(2) + 年(4) + 回(2) + 日次(2) = 17文字目から8桁が日付
            // ただし末尾に "/HASH" が付く場合があるので split('/')[0] で除去する
            const codeClean = code.split('/')[0];
            const d         = codeClean.length >= 25 ? codeClean.substring(17, 25) : '';
            const date      = d.length === 8
                ? `${d.substring(0,4)}-${d.substring(4,6)}-${d.substring(6,8)}`
                : '';

            items.push({
                text:      `${m[1]}回${m[2]}${m[3]}日`,  // 「馬番確定」を除去した正規化名
                textRaw:   text,
                kaisuu:    parseInt(m[1]),
                bashoName: m[2],
                day:       parseInt(m[3]),
                date,
                onclick,
            });
        });

        // 重複除去（同じ text が複数存在する場合）
        const seen = new Set();
        return items.filter(k => {
            if (seen.has(k.text)) return false;
            seen.add(k.text);
            return true;
        });
    });

    return result;
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 7】出馬表リンクの収集関数（getShutsubaLinks）
//   レース一覧ページの「出馬表」リンク（href="accessD.html?CNAME=pw01dde..."）を収集する。
//   CNAMEで重複除去し、レース番号の昇順でソートして返す。
//
//   CNAMEコードの構造（インデックス）:
//     0〜8   : "pw01dde01"（9文字）
//     9〜10  : 場所コード（2桁）
//     11〜14 : 年（4桁）
//     15〜16 : 開催回（2桁）
//     17〜18 : 開催日次（2桁）
//     19〜20 : レース番号（2桁）← substring(19, 21) で取得
//     21〜28 : 日付 YYYYMMDD    ← substring(21, 29) で取得
// ─────────────────────────────────────────────────────────────
async function getShutsubaLinks(page) {
    const rawLinks = await page.evaluate(() => {
        const result = [];
        document.querySelectorAll('a[href*="CNAME=pw01dde"]').forEach(a => {
            const href = a.href ?? '';
            const m    = href.match(/CNAME=(pw01dde\w+)/);
            if (m) result.push({ cname: m[1], href });
        });
        return result;
    });

    // CNAMEで重複除去してからパース
    const seen = new Set();
    return rawLinks
        .filter(({ cname }) => {
            if (seen.has(cname)) return false;
            seen.add(cname);
            return true;
        })
        .map(({ cname, href }) => {
            const raceNum = parseInt(cname.substring(19, 21), 10);
            const d       = cname.substring(21, 29);
            const date    = d ? `${d.substring(0,4)}-${d.substring(4,6)}-${d.substring(6,8)}` : '';
            return { cname, href, raceNum, date };
        })
        .filter(l => !isNaN(l.raceNum))
        .sort((a, b) => a.raceNum - b.raceNum);
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 8】出馬表テーブルのパース関数（parseShutsubaTable）
//   出馬表ページ（accessD.html?CNAME=pw01dde...）の馬情報テーブルをパース。
//
//   テーブル構造（1頭1行）:
//     td.waku  : 枠番画像（alt="枠N白" or "枠N黒"）→ 数字を抽出
//     td.num   : 馬番（テキスト先頭数字）
//     td.horse : 馬名(.name a)、戦績(.result)、総賞金(.win)、
//                馬主(.owner)、生産者(.breeder)、調教師(a[onclick*=accessC])、血統(.family_line)
//     td.jockey: 騎手(a[onclick*=accessK])、性齢(.age)、負担重量(.weight)
//     td.past.p1〜p4: 前走〜4走前（各種情報を個別セレクタで取得）
// ─────────────────────────────────────────────────────────────
async function parseShutsubaTable(page) {
    return await page.evaluate(() => {
        // レース名
        const raceNameEl = document.querySelector('table caption h2 .race_name')
                        ?? document.querySelector('h2 .race_name')
                        ?? document.querySelector('h2');
        const raceName = raceNameEl ? raceNameEl.textContent.replace(/\s+/g, ' ').trim() : '';

        const horses = [];

        // 馬情報テーブル: table.basic
        const horseTable = document.querySelector('table.basic');
        if (!horseTable) return { raceName, horses };

        horseTable.querySelectorAll('tbody tr').forEach(row => {
            const wakuCell   = row.querySelector('td.waku');
            const numCell    = row.querySelector('td.num');
            const horseCell  = row.querySelector('td.horse');
            const jockeyCell = row.querySelector('td.jockey');

            if (!wakuCell || !numCell || !horseCell) return;

            // ── 枠番・馬番 ────────────────────────────────────
            const wakuAlt = wakuCell.querySelector('img')?.alt ?? '';
            const wakuM   = wakuAlt.match(/(\d+)/);
            const waku    = wakuM ? parseInt(wakuM[1]) : null;

            const numText = numCell.textContent.trim();
            const numM    = numText.match(/^(\d+)/);
            const num     = numM ? parseInt(numM[1]) : null;

            // ── 馬名 ──────────────────────────────────────────
            const name = horseCell.querySelector('.name a')?.textContent.trim() ?? '';
            if (!name) return;

            // ── 戦績・総賞金 ──────────────────────────────────
            const resultText  = horseCell.querySelector('.result_line .result')?.textContent.trim() ?? '';
            const result      = resultText.replace(/[()]/g, '');
            const winEl       = horseCell.querySelector('.result_line .win');
            const total_prize = winEl?.getAttribute('title') ?? winEl?.textContent.trim() ?? '';

            // ── 馬主・生産者 ──────────────────────────────────
            const owner   = horseCell.querySelector('.owner')?.textContent.trim()   ?? '';
            const breeder = horseCell.querySelector('.breeder')?.textContent.trim() ?? '';

            // ── 調教師・所属 ──────────────────────────────────
            const trainerEl = horseCell.querySelector('a[onclick*="accessC.html"]');
            const trainer   = trainerEl?.textContent.trim() ?? '';
            const division  = horseCell.querySelector('.division')
                ?.textContent.replace(/[()]/g, '').trim() ?? '';

            // ── 血統（父・母・母の父）──────────────────────────
            const sire      = horseCell.querySelector('.family_line .sire')
                ?.textContent.replace('父：', '').trim() ?? '';
            const mareEl    = horseCell.querySelector('.family_line .mare');
            const mare      = mareEl ? (mareEl.childNodes[1]?.textContent.trim() ?? '') : '';
            const bloodmare = horseCell.querySelector('.bloodmare')
                ?.textContent.replace(/[()]/g, '').replace('母の父：', '').trim() ?? '';

            // ── 騎手・性齢・負担重量 ──────────────────────────
            const jockeyEl      = jockeyCell?.querySelector('a[onclick*="accessK.html"]');
            const jockey        = jockeyEl?.textContent.trim() ?? '';
            const seirei        = jockeyCell?.querySelector('.age')?.textContent.trim() ?? '';
            const burden_weight = jockeyCell?.querySelector('.weight')
                ?.textContent.replace(/\s+/g, '').trim() ?? '';

            // ── 前走〜4走前 ───────────────────────────────────
            // 各走のセルは td.past.p1 〜 td.past.p4 で特定する。
            // セルが存在しない（出走歴が少ない）場合は null を格納する。
            const pasts = [1, 2, 3, 4].map(n => {
                const pastCell = row.querySelector(`td.past.p${n}`);
                if (!pastCell) return null;

                const date     = pastCell.querySelector('.date_line .date')?.textContent.trim()   ?? '';
                const place    = pastCell.querySelector('.date_line .rc')?.textContent.trim()     ?? '';
                const raceName = pastCell.querySelector('.race_line .name a')?.textContent.trim()
                              ?? pastCell.querySelector('.race_line .name')?.textContent.trim()   ?? '';
                const grade    = pastCell.querySelector('.r_class img')?.alt ?? '';

                // 着順・頭数・枠番・人気
                const placeNum = pastCell.querySelector('.place_line .place')
                    ?.textContent.replace('着', '').trim() ?? '';
                const maxHead  = pastCell.querySelector('.place_line .num .max')
                    ?.textContent.replace('頭', '').trim() ?? '';
                const gate     = pastCell.querySelector('.place_line .num .gate')
                    ?.textContent.replace('番', '').trim() ?? '';
                const pop      = pastCell.querySelector('.place_line .num .pop')
                    ?.textContent.replace('番人気', '').trim() ?? '';

                // 騎手・負担重量
                const pastJockey = pastCell.querySelector('.info_line1 .jockey')?.textContent.trim() ?? '';
                const pastWeight = pastCell.querySelector('.info_line1 .weight')
                    ?.textContent.replace(/\s+/g, '').trim() ?? '';

                // 距離・タイム・馬場・馬体重
                const dist      = pastCell.querySelector('.info_line2 .dist')?.textContent.trim()      ?? '';
                const time      = pastCell.querySelector('.info_line2 .time')?.textContent.trim()      ?? '';
                const condition = pastCell.querySelector('.info_line2 .condition')?.textContent.trim() ?? '';
                const h_weight  = pastCell.querySelector('.info_line2 .h_weight')
                    ?.textContent.replace(/\s+/g, '').trim() ?? '';

                // コーナー通過順（1〜4コーナー）
                const corners = Array.from(pastCell.querySelectorAll('.corner_list li'))
                    .map(li => li.textContent.trim());

                // 上がり
                const f3 = pastCell.querySelector('.info_line3 .f3')?.textContent.trim() ?? '';

                // 1着馬名・タイム差
                const finEl      = pastCell.querySelector('.info_line3 .fin');
                const fin_horse  = finEl ? (finEl.childNodes[0]?.textContent.trim() ?? '') : '';
                const fin_time   = finEl?.querySelector('.time')
                    ?.textContent.replace(/[()]/g, '').trim() ?? '';

                return {
                    date, place, race_name: raceName, grade,
                    place_num:    placeNum ? parseInt(placeNum) : null,
                    head_count:   maxHead  ? parseInt(maxHead)  : null,
                    gate:         gate     ? parseInt(gate)     : null,
                    popularity:   pop      ? parseInt(pop)      : null,
                    jockey:       pastJockey,
                    burden_weight: pastWeight,
                    dist, time, condition,
                    horse_weight: h_weight,
                    corners,
                    last_3f:       f3,
                    fin_horse,
                    fin_time_diff: fin_time,
                };
            });

            horses.push({
                waku, num, name,
                result, total_prize,
                owner, breeder,
                trainer, division,
                sire, mare, bloodmare,
                seirei, burden_weight,
                jockey,
                pasts,
            });
        });

        return { raceName, horses };
    });
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 9】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {

    // ── 多重起動防止（スタールロック考慮）──────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ error: 'already_running', data: [] }));
            logStream.end();
            process.exit(0);
        }
        log(`[LOCK] 古いロックファイルを削除します (PID=${storedPid} は存在しない)`);
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    const allData = [];

    try {
        log('================================================================');
        log(`keibaOddsGetShutsuba 開始 date=${dateFilter ?? 'なし'} kaisai=${kaisaiFilter ?? 'なし'} listOnly=${listOnly}`);
        log('================================================================');

        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】開催一覧の取得
        //   accessS.html → 出馬表クリック → 開催選択ページ
        //   getKaisaiList() で「X回XX Y日」形式のリンクと日付を収集する。
        // ─────────────────────────────────────────────────────
        await navigateToKaisaiList(page);
        let kaisaiList = await getKaisaiList(page);

        log(`開催一覧 (${kaisaiList.length}件): ${kaisaiList.map(k => `${k.text}(${k.date})`).join(', ')}`);

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】絞り込み
        //   優先順位: --kaisai > --date > 全件
        //   --list-only 時は絞り込み後の一覧を JSON 出力して終了する。
        //   list-only の出力形式:
        //     { kaisaiList: [{ kaisai: "2回福島5日", date: "2026-07-11" }, ...] }
        // ─────────────────────────────────────────────────────
        if (kaisaiFilter) {
            // 特定の開催名で絞り込み（「馬番確定」付きテキストにも対応）
            kaisaiList = kaisaiList.filter(k =>
                k.text    === kaisaiFilter.replace(/\s+/g, '') ||
                k.textRaw.startsWith(kaisaiFilter.replace(/\s+/g, ''))
            );
            if (kaisaiList.length === 0) {
                log(`ERROR: 「${kaisaiFilter}」が見つかりませんでした。`);
                console.log(JSON.stringify({ kaisai: kaisaiFilter, data: [], error: 'kaisai_not_found' }));
                return;
            }
        } else if (dateFilter) {
            // 指定日付の開催のみに絞り込み（Laravelから翌日を渡す用途）
            kaisaiList = kaisaiList.filter(k => k.date === dateFilter);
            if (kaisaiList.length === 0) {
                log(`INFO: ${dateFilter} の開催が見つかりませんでした（出馬表未確定の可能性）。`);
                if (listOnly) {
                    // --list-only 時は kaisaiList キーを返す（PHP側が "対象開催なし" と判定できるように）
                    console.log(JSON.stringify({ kaisaiList: [] }));
                } else {
                    console.log(JSON.stringify({ date: dateFilter, data: [], error: 'date_not_found' }));
                }
                return;
            }
            log(`日付絞り込み後 (${kaisaiList.length}件): ${kaisaiList.map(k => k.text).join(', ')}`);
        }

        // --list-only: 絞り込み後の一覧を返して終了
        if (listOnly) {
            console.log(JSON.stringify({
                kaisaiList: kaisaiList.map(k => ({ kaisai: k.text, date: k.date })),
            }));
            return;
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】各開催のループ
        //   各開催について毎回 navigateToKaisaiList() で開催選択ページへ戻り、
        //   開催ボタンをクリック → レース一覧 → 各出馬表 → パースの順に処理する。
        // ─────────────────────────────────────────────────────
        for (const kaisai of kaisaiList) {
            const { text: kaisaiText, kaisuu, bashoName, day } = kaisai;
            const basho = bashoMap[bashoName] ?? null;
            log(`\n[開催] ${kaisaiText} (${kaisai.date})`);

            // 開催選択ページに戻る（毎回）
            await navigateToKaisaiList(page);

            // 対象開催のボタンをクリック
            const clicked = await page.evaluate(({ kaisaiText }) => {
                const target = Array.from(document.querySelectorAll('a'))
                    .find(a => {
                        const t  = a.textContent.replace(/\s+/g, '').trim();
                        const oc = a.getAttribute('onclick') ?? '';
                        return t.startsWith(kaisaiText) && oc.includes('pw01drl');
                    });
                if (target) { target.click(); return true; }
                return false;
            }, { kaisaiText });

            if (!clicked) {
                log(`  WARNING: 「${kaisaiText}」リンクが見つかりませんでした。スキップします。`);
                continue;
            }

            // レース一覧テーブルが出るまで待つ
            await page.waitForSelector('table#race_list', { timeout: 15000 }).catch(() => {});
            await sleep(1000);

            // 出馬表リンク収集（CNAME=pw01dde 形式）
            const shutsubaLinks = await getShutsubaLinks(page);
            if (shutsubaLinks.length === 0) {
                log(`  WARNING: 出馬表リンクが見つかりませんでした。スキップします。`);
                continue;
            }
            log(`  ${shutsubaLinks.length} レース: ${shutsubaLinks.map(l => l.raceNum).join(', ')}R`);

            const races = [];

            // ─────────────────────────────────────────────────
            // 【ブロック 13】各レースの出馬表取得
            //   page.goto() で直接出馬表URLへ遷移し parseShutsubaTable() でパースする。
            //   馬データが空でもレース情報は races に追加する（後続処理で確認できるよう）。
            // ─────────────────────────────────────────────────
            for (const { href, raceNum, date: raceDate } of shutsubaLinks) {
                log(`    [Race ${raceNum}R] 取得中...`);

                await page.goto(href, { waitUntil: 'domcontentloaded', timeout: 30000 });
                await sleep(1000);

                const { raceName, horses } = await parseShutsubaTable(page);

                if (horses.length > 0) {
                    log(`    [Race ${raceNum}R] OK → ${horses.length}頭 (${raceName})`);
                } else {
                    log(`    [Race ${raceNum}R] WARNING: 馬データなし (${raceName})`);
                }

                races.push({
                    race:       raceNum,
                    race_name:  raceName,
                    date:       raceDate,
                    cname_href: href,
                    horses,
                });
            }

            if (races.length > 0) {
                const date = races.find(r => r.date)?.date ?? '';
                allData.push({
                    date,
                    kaisuu,
                    basho: bashoName,
                    basho_code: basho,
                    day,
                    kaisai: kaisaiText,
                    races,
                });
            }
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 14】JSON 出力
        //   kaisai 1件指定 → フラット形式
        //   date 指定 or 全件 → { date, data: [...] } 形式
        // ─────────────────────────────────────────────────────
        const totalRaces = allData.reduce((s, k) => s + k.races.length, 0);
        log(`\n完了 — 合計 ${allData.length} 開催 / ${totalRaces} レース取得`);

        let output;
        if (kaisaiFilter && allData.length === 1) {
            output = { kaisai: kaisaiFilter, ...allData[0] };
        } else {
            output = { date: dateFilter, data: allData };
        }
        console.log(JSON.stringify(output, null, 2));

    } catch (err) {
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ error: err.message, date: dateFilter, kaisai: kaisaiFilter, data: allData }));
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
        log('================================================================');
        log(`keibaOddsGetShutsuba 終了 ${new Date().toLocaleString('ja-JP')}`);
        log('================================================================');
        logStream.end();
    }
})();
