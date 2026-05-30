import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ── ログ設定 ─────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetSchedule.log'), { flags: 'w' });
const lockFile  = join(__dirname, 'keibaOddsGetSchedule.lock');

// ログをファイルとターミナル（stderr）の両方に出力する
// stderr に書く理由: stdout は PHP が受け取る JSON 専用にしたいため
const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ── 定数 ─────────────────────────────────────────────────────────
const BASHO_MAP = {
    '札幌': '01', '函館': '02', '福島': '03', '新潟': '04',
    '東京': '05', '中山': '06', '中京': '07', '京都': '08',
    '阪神': '09', '小倉': '10',
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ── メイン ────────────────────────────────────────────────────────
(async () => {
    // ── 多重起動チェック ──────────────────────────────────────────
    if (existsSync(lockFile)) {
        log('[LOCK] 既に起動中のため終了します');
        console.log(JSON.stringify({ schedules: [], races: [], horses: [] }));
        logStream.end();
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid));
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {

    log('================================================================');
    log('keibaOddsGetSchedule 開始');
    log('================================================================');

    log('ブラウザ起動中...');
    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
    });
    const page = await context.newPage();

    // ── Step 1: JRA → オッズページへ ────────────────────────────
    log('[Step 1] JRAサイトにアクセス中...');
    await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });

    log('[Step 1] 「オッズ」リンクをクリック...');
    await page.evaluate(() => {
        [...document.querySelectorAll('a')]
            .find(a => a.textContent.trim() === 'オッズ')
            ?.click();
    });
    await sleep(3000);

    // ── Step 2: 開催情報取得 ──────────────────────────────────────
    log('[Step 2] 開催情報を取得中...');
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

    log(`[Step 2] 開催情報 ${kaisaiList.length}件取得`);
    kaisaiList.forEach(k => log(`  → ${k.label} (${k.date})`));

    if (kaisaiList.length === 0) {
        log('[Step 2] 本日の開催情報なし。終了します。');
        console.log(JSON.stringify({ schedules: [], races: [], horses: [] }));
        return; // finally でブラウザ・ロックを解放
    }

    const result = { schedules: [], races: [], horses: [] };

    // ── Step 3 & 4: 各開催のレース・馬情報を取得 ──────────────────
    for (const kaisai of kaisaiList) {
        log('');
        log(`----------------------------------------------------------------`);
        log(`[Step 3] ${kaisai.label} (${kaisai.date}) 処理開始`);
        log(`----------------------------------------------------------------`);

        // schedules テーブル用データ
        result.schedules.push({
            date:       kaisai.date,
            kaisuu:     kaisai.kaisuu,
            basho:      kaisai.basho,
            basho_name: kaisai.basho_name,
            day:        kaisai.day,
        });

        // 開催リンクをクリック → レース一覧ページへ
        log(`  開催「${kaisai.label}」クリック...`);
        await page.evaluate((onclick) => {
            [...document.querySelectorAll('a')]
                .find(a => a.getAttribute('onclick') === onclick)
                ?.click();
        }, kaisai.onclick);
        await sleep(3000);

        // レース一覧を取得（発走時刻・レース名・単複リンク有無）
        const raceInfoList = await page.evaluate(() => {
            return [...document.querySelectorAll('tbody tr')].map((row, i) => {
                const timeText = row.querySelector('td.time')?.textContent.trim() ?? '';
                const tm       = timeText.match(/(\d+)[時:](\d+)/);
                const startTime = tm
                    ? `${tm[1].padStart(2,'0')}:${tm[2].padStart(2,'0')}:00`
                    : 'XXX';
                const raceName    = row.querySelector('td.race_name div div')?.textContent.trim() ?? '';
                const tanpukuLink = row.querySelector('div.tanpuku a');
                const hasTanpuku  = !!tanpukuLink;

                // onclick / href からレース番号を取得する
                // インデックス(i+1)ではなく実際のレース番号を使うことで、
                // JRAが11R・12Rだけ表示している場合にも正しく記録できる
                const tanpukuAttr = tanpukuLink?.getAttribute('onclick')
                    ?? tanpukuLink?.getAttribute('href')
                    ?? '';
                const raceNum = (() => {
                    // パターン1: /数字/ または /0詰め数字/（例: /11/ /011/）
                    const slashMatches = [...tanpukuAttr.matchAll(/\/0*(\d{1,2})\//g)];
                    if (slashMatches.length > 0) {
                        return Number(slashMatches[slashMatches.length - 1][1]);
                    }
                    // パターン2: カンマ・シングルクォートで囲まれた1〜2桁の数字
                    const quoteMatches = [...tanpukuAttr.matchAll(/[,']\s*0*(\d{1,2})\s*[,'"]/g)];
                    if (quoteMatches.length > 0) {
                        return Number(quoteMatches[quoteMatches.length - 1][1]);
                    }
                    // フォールバック: 行インデックス+1
                    return i + 1;
                })();

                return { raceNum, rowIndex: i, raceName, startTime, hasTanpuku, tanpukuAttr };
            });
        });
        
        log(`  レース一覧: ${raceInfoList.length}件確認`);
        raceInfoList.forEach(ri =>
            log(`    行${ri.rowIndex}: raceNum=${ri.raceNum} startTime=${ri.startTime} attr="${ri.tanpukuAttr.slice(0, 80)}"`)
        );

        // ── Step 4: 各レースの馬名を取得 ────────────────────────
        for (const ri of raceInfoList) {
            const raceLabel = `${kaisai.label} ${ri.raceNum}R`;

            // 単複リンクがない場合（出走取消など）はスキップ
            if (!ri.hasTanpuku) {
                log(`  [${raceLabel}] 単複リンクなし → スキップ (${ri.startTime})`);
                result.races.push({
                    date:       kaisai.date,
                    kaisuu:     kaisai.kaisuu,
                    basho:      kaisai.basho,
                    basho_name: kaisai.basho_name,
                    race:       ri.raceNum,
                    race_name:  ri.raceName,
                    start_time: ri.startTime,
                    num_horses: 0,
                });
                continue;
            }

            log(`  [${raceLabel}] 単複ページへ遷移... (${ri.startTime} ${ri.raceName})`);

            // 該当レース行の単複リンクをクリック
            // rowIndex（0始まりの行位置）を使うことで、表示レースが途中から始まっても正しく動く
            const clicked = await page.evaluate((idx) => {
                const link = document.querySelectorAll('tbody tr')[idx]
                    ?.querySelector('div.tanpuku a');
                if (!link) return false;
                link.click();
                return true;
            }, ri.rowIndex);

            if (!clicked) {
                log(`  [${raceLabel}] クリック失敗 → スキップ`);
                result.races.push({
                    date:       kaisai.date,
                    kaisuu:     kaisai.kaisuu,
                    basho:      kaisai.basho,
                    basho_name: kaisai.basho_name,
                    race:       ri.raceNum,
                    race_name:  ri.raceName,
                    start_time: ri.startTime,
                    num_horses: 0,
                });
                continue;
            }

            await sleep(3000);

            // 馬名・騎手・調教師を取得
            const horses = await page.evaluate(() => {
                const table = document.querySelector('table.tanpuku');
                if (!table) return [];

                const list = [];
                let waku = 0;

                table.querySelectorAll('tbody tr').forEach(row => {
                    // 枠番の更新
                    const wakuImg = row.querySelector('td.waku img');
                    if (wakuImg) {
                        const m = wakuImg.getAttribute('alt')?.match(/枠(\d+)/);
                        if (m) waku = Number(m[1]);
                    }

                    const numEl  = row.querySelector('td.num');
                    const nameEl = row.querySelector('td.horse a');
                    if (!numEl || !nameEl) return;

                    const num  = numEl.textContent.trim();
                    const name = nameEl.textContent.trim();
                    if (!num || !name) return;

                    list.push({
                        waku,
                        num:       Number(num),
                        name,
                        horse_url: nameEl.href || '',
                        jockey:    row.querySelector('td.jockey a')?.textContent.trim() ?? '',
                        trainer:   row.querySelector('td.trainer a')?.textContent.trim() ?? '',
                    });
                });

                return list;
            });

            log(`  [${raceLabel}] ${horses.length}頭取得`);
            horses.forEach(h =>
                log(`    馬番${String(h.num).padStart(2,' ')} 枠${h.waku} ${h.name} / 騎手:${h.jockey} / 師:${h.trainer}`)
            );

            // races テーブル用データ
            result.races.push({
                date:       kaisai.date,
                kaisuu:     kaisai.kaisuu,
                basho:      kaisai.basho,
                basho_name: kaisai.basho_name,
                day:        kaisai.day,
                race:       ri.raceNum,
                race_name:  ri.raceName,
                start_time: ri.startTime,
                num_horses: horses.length,
            });

            // horses テーブル用データ
            horses.forEach(h => result.horses.push({
                date:       kaisai.date,
                kaisuu:     kaisai.kaisuu,
                basho:      kaisai.basho,
                basho_name: kaisai.basho_name,
                day:        kaisai.day,
                race:       ri.raceNum,
                waku:       h.waku,
                num:        h.num,
                name:       h.name,
                horse_url:  h.horse_url,
                jockey:     h.jockey,
                trainer:    h.trainer,
            }));

            // レース一覧ページへ戻る
            log(`  [${raceLabel}] レース一覧へ戻る...`);
            await page.goBack();
            try {
                await page.waitForSelector('tbody tr', { timeout: 8000 });
            } catch (_) {
                log(`  [${raceLabel}] WARNING: レース一覧の復元タイムアウト`);
            }
            await sleep(1000);
        }

        // 開催選択ページへ戻る
        log(`  ${kaisai.label} 全レース完了 → 開催選択へ戻る`);
        await page.evaluate(() => {
            [...document.querySelectorAll('a')]
                .find(a => a.textContent.includes('開催選択へ戻る'))
                ?.click();
        });
        await sleep(3000);
    }

    // ── 完了・結果出力 ────────────────────────────────────────────
    log('');
    log('================================================================');
    log('スクレイピング完了');
    log(`  開催 (schedules): ${result.schedules.length}件`);
    log(`  レース (races):   ${result.races.length}件`);
    log(`  馬 (horses):      ${result.horses.length}件`);
    log('================================================================');

    // JSON を stdout に出力（Laravel コマンドが受け取る）
    console.log(JSON.stringify(result));

    } catch (err) {
        log(`致命的エラー: ${err.message}`);
        console.log(JSON.stringify({ schedules: [], races: [], horses: [] }));
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
