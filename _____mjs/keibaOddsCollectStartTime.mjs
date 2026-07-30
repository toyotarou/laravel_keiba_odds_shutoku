/**
 * keibaOddsCollectStartTime.mjs
 *
 * 【概要】
 *   JRA公式サイトの「オッズ」ページから、本日開催の全レースの
 *   発走時刻を収集する。
 *   レース遅延による時刻変動を検知して修正するための補助スクリプト。
 *
 * ════════════════════════════════════════════════════════════════
 * 【ブラウザで追える進行順路】
 * ════════════════════════════════════════════════════════════════
 *
 * ▼ STEP A ── JRAトップページへアクセス
 *   URL: https://www.jra.go.jp/
 *   ページ名: 「JRA 日本中央競馬会」公式トップページ
 *   画面の説明:
 *     JRA公式サイトのトップ。ヘッダーに「レース情報」「オッズ」等の
 *     グローバルメニューが並んでいる。
 *
 * ▼ STEP B ── 「オッズ」リンクをクリック
 *   操作: グローバルメニュー内のテキストが「オッズ」のリンクをクリック
 *   変化:
 *     本日開催の競馬場が選べる「オッズ 開催選択ページ」へ遷移する。
 *     「〇月〇日（〇）」という h3 見出しの下に
 *     「1回東京5日」「2回阪神3日」のような開催リンクが並ぶ。
 *
 * ▼ STEP C ── 開催情報を収集（操作なし）
 *   操作: なし（ページを読み取るだけ）
 *   取得方法:
 *     ・h3.sub_header のテキストから「M月D日」を抽出 → 日付を生成
 *     ・h3 直後の要素内のリンクから「X回場所Y日」を抽出 → 開催情報を収集
 *   取得データ: 日付・開催回数・場所コード・場所名・開催日次
 *   ※ 本日の開催がない場合はここで空の JSON を返して終了
 *
 * ▼ STEP D ── 各開催について発走時刻を収集
 *
 *   ┌─ D-1: 開催リンクをクリック → レース一覧ページへ
 *   │   操作: 「1回東京5日」等の開催リンクをクリック
 *   │   変化: その開催のレース一覧ページへ遷移する。
 *   │
 *   └─ D-2: レース一覧を読み取り（操作なし）
 *       取得データ（1行 = 1レース）:
 *         td.time      → 発走時刻（"HH時MM分" → "HH:MM:SS" に変換）
 *         td.race_name → レース名
 *         div.tanpuku a → レース番号の抽出に使用
 *       読み取り後、「開催選択へ戻る」をクリックして次の開催へ。
 *
 * ▼ 全開催ループ終了後 ── JSON を stdout に出力
 *   { races: [...] }
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【出力 JSON 構造】
 *   {
 *     races: [ { date, kaisuu, basho, basho_name, day, race, race_name, start_time } ]
 *   }
 *
 * 【標準出力】 JSON（Laravel コマンドが受け取る）
 * 【標準エラー】 ログ（stdout は JSON 専用のため）
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】ログ・ロックファイルの設定
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsCollectStartTime.log'), { flags: 'w' });
const lockFile  = join(__dirname, 'keibaOddsCollectStartTime.lock');

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】定数
// ─────────────────────────────────────────────────────────────
const BASHO_MAP = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    // ─────────────────────────────────────────────────────────
    // 【ブロック 5】二重起動チェック
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        log('[LOCK] 既に起動中のため終了します');
        console.log(JSON.stringify({ races: [] }));
        logStream.end();
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid));
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {

    log('================================================================');
    log('keibaOddsCollectStartTime 開始');
    log('================================================================');

    // ─────────────────────────────────────────────────────────
    // 【ブロック 6】ブラウザ起動
    // ─────────────────────────────────────────────────────────
    log('ブラウザ起動中...');
    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
    });
    const page = await context.newPage();

    // ─────────────────────────────────────────────────────────
    // 【ブロック 7】Step A: JRAトップ → オッズ開催選択ページへ遷移
    // ─────────────────────────────────────────────────────────
    log('[Step A] JRAサイトにアクセス中...');
    await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });

    log('[Step B] 「オッズ」リンクをクリック...');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
        page.evaluate(() => {
            [...document.querySelectorAll('a')]
                .find(a => a.textContent.trim() === 'オッズ')
                ?.click();
        }).catch(e => { if (!e.message.includes('closed')) throw e; }),
    ]);
    await sleep(1000);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8】Step C: 開催情報の収集
    // ─────────────────────────────────────────────────────────
    log('[Step C] 開催情報を取得中...');
    const kaisaiList = await page.evaluate((bashoMap) => {
        const year = new Date().getFullYear();
        const list = [];

        document.querySelectorAll('h3.sub_header').forEach(h3 => {
            const dm = h3.textContent.match(/(\d+)月(\d+)日/);
            if (!dm) return;

            const date = `${year}-${dm[1].padStart(2,'0')}-${dm[2].padStart(2,'0')}`;

            h3.nextElementSibling?.querySelectorAll('a').forEach(a => {
                const m = a.textContent.trim().match(/^(\d+)回(.+?)(\d+)日$/);
                if (!m) return;
                list.push({
                    date,
                    kaisuu:     m[1],
                    basho:      bashoMap[m[2]] ?? '00',
                    basho_name: m[2],
                    day:        Number(m[3]),
                    onclick:    a.getAttribute('onclick'),
                    label:      `${m[1]}回${m[2]}${m[3]}日`,
                });
            });
        });
        return list;
    }, BASHO_MAP);

    log(`[Step C] 開催情報 ${kaisaiList.length}件取得`);
    kaisaiList.forEach(k => log(`  → ${k.label} (${k.date})`));

    if (kaisaiList.length === 0) {
        log('[Step C] 本日の開催情報なし。終了します。');
        console.log(JSON.stringify({ races: [] }));
        return;
    }

    const result = { races: [] };

    // ─────────────────────────────────────────────────────────
    // 【ブロック 9】Step D: 各開催のレース一覧から発走時刻を収集
    // ─────────────────────────────────────────────────────────
    for (const kaisai of kaisaiList) {
        log('');
        log(`----------------------------------------------------------------`);
        log(`[Step D] ${kaisai.label} (${kaisai.date}) 処理開始`);
        log(`----------------------------------------------------------------`);

        // D-1: 開催リンクをクリック → レース一覧ページへ遷移
        log(`  開催「${kaisai.label}」クリック...`);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
            page.evaluate((onclick) => {
                [...document.querySelectorAll('a')]
                    .find(a => a.getAttribute('onclick') === onclick)
                    ?.click();
            }, kaisai.onclick).catch(e => { if (!e.message.includes('closed')) throw e; }),
        ]);
        await sleep(1000);

        // D-2: レース一覧を読み取り（発走時刻・レース名・レース番号）
        const raceInfoList = await page.evaluate(() => {
            return [...document.querySelectorAll('tbody tr')].map((row, i) => {
                const timeText  = row.querySelector('td.time')?.textContent.trim() ?? '';
                const tm        = timeText.match(/(\d+)[時:](\d+)/);
                const startTime = tm
                    ? `${tm[1].padStart(2,'0')}:${tm[2].padStart(2,'0')}:00`
                    : null;

                const raceName    = row.querySelector('td.race_name div div')?.textContent.trim() ?? '';
                const tanpukuLink = row.querySelector('div.tanpuku a');
                const tanpukuAttr = tanpukuLink?.getAttribute('onclick')
                    ?? tanpukuLink?.getAttribute('href')
                    ?? '';

                const raceNum = (() => {
                    const slashMatches = [...tanpukuAttr.matchAll(/\/0*(\d{1,2})\//g)];
                    if (slashMatches.length > 0) {
                        return Number(slashMatches[slashMatches.length - 1][1]);
                    }
                    const quoteMatches = [...tanpukuAttr.matchAll(/[,']\s*0*(\d{1,2})\s*[,'"]/g)];
                    if (quoteMatches.length > 0) {
                        return Number(quoteMatches[quoteMatches.length - 1][1]);
                    }
                    return i + 1;
                })();

                return { raceNum, raceName, startTime };
            });
        });

        log(`  レース一覧: ${raceInfoList.length}件`);
        raceInfoList.forEach(ri =>
            log(`    ${ri.raceNum}R ${ri.startTime ?? '(時刻なし)'} ${ri.raceName}`)
        );

        raceInfoList.forEach(ri => {
            result.races.push({
                date:       kaisai.date,
                kaisuu:     kaisai.kaisuu,
                basho:      kaisai.basho,
                basho_name: kaisai.basho_name,
                day:        kaisai.day,
                race:       ri.raceNum,
                race_name:  ri.raceName,
                start_time: ri.startTime,
            });
        });

        // 開催選択ページへ戻る
        log(`  ${kaisai.label} 完了 → 開催選択へ戻る`);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
            page.evaluate(() => {
                [...document.querySelectorAll('a')]
                    .find(a => a.textContent.includes('開催選択へ戻る'))
                    ?.click();
            }).catch(e => { if (!e.message.includes('closed')) throw e; }),
        ]);
        await sleep(1000);
    }

    // ─────────────────────────────────────────────────────────
    // 【ブロック 10】完了ログ・JSON 出力
    // ─────────────────────────────────────────────────────────
    log('');
    log('================================================================');
    log('スクレイピング完了');
    log(`  レース (races): ${result.races.length}件`);
    log('================================================================');

    console.log(JSON.stringify(result));

    } catch (err) {
        log(`致命的エラー: ${err.message}`);
        console.log(JSON.stringify({ races: [] }));
        process.exitCode = 1;
    } finally {
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
