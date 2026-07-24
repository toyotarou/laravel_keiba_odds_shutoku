/**
 * keibaOddsGetSchedule.mjs
 *
 * 【概要】
 *   JRA公式サイトの「オッズ」ページから、本日開催の全レース情報と
 *   各レースの出走馬（馬名・騎手・調教師）を取得する。
 *
 * ════════════════════════════════════════════════════════════════
 * 【ブラウザで追える進行順路】
 *   ※ 実際に Chrome で同じ操作をすると、スクリプトの動きを確認できる
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
 * ▼ STEP D ── 各開催について外側ループ（開催数だけ繰り返す）
 *
 *   ┌─ D-1: 開催リンクをクリック → レース一覧ページへ
 *   │   操作: 「1回東京5日」等の開催リンクをクリック
 *   │   変化: その開催のレース一覧ページへ遷移する。
 *   │          tbody の各 tr が 1 レースに対応した表が表示される。
 *   │
 *   ├─ D-2: レース一覧を読み取り（操作なし）
 *   │   取得データ（1行 = 1レース）:
 *   │     td.time      → 発走時刻（"HH時MM分" → "HH:MM:SS" に変換）
 *   │     td.race_name → レース名
 *   │     div.tanpuku a → 単複リンクの有無・属性値
 *   │   レース番号の抽出（単複リンクの属性値から）:
 *   │     パターン1: "/N/" 形式（例: "/11/"）→ 最後のマッチを使用
 *   │     パターン2: カンマ・クォートで囲まれた数字（例: ",'11',"）
 *   │     フォールバック: 行インデックス + 1
 *   │
 *   └─ D-3: 各レースについて内側ループ（レース数だけ繰り返す）
 *
 *       ┌─ E-1: 単複リンクをクリック → 単複オッズページへ遷移
 *       │   操作: レース行内の「単複」リンク（div.tanpuku a）をクリック
 *       │          ※ 単複リンクがない場合（出走取消・レース中止等）はスキップ
 *       │             → races に num_horses=0 で登録して次のレースへ
 *       │   変化: 単複オッズページへ遷移する。table.tanpuku が表示される。
 *       │
 *       ├─ E-2: 単複オッズページから馬情報を取得（操作なし）
 *       │   対象要素:
 *       │     table.tanpuku > tbody > tr（1行＝1頭）
 *       │       td.waku img[alt="枠N"] ← 枠番画像（rowspan で複数行に結合）
 *       │                                 ※ 画像がある行でのみ枠番を更新し、
 *       │                                    ない行は前の値を引き継ぐ
 *       │       td.num                 ← 馬番
 *       │       td.horse a             ← 馬名（href が馬詳細ページ URL）
 *       │       td.jockey a            ← 騎手名
 *       │       td.trainer a           ← 調教師名
 *       │   コース・距離:
 *       │     ページテキスト内の「コース：1,200メートル（芝・右）」から抽出
 *       │
 *       ├─ E-3: ブラウザの「戻る」でレース一覧ページへ戻る
 *       │   操作: page.goBack() → D-1 で遷移したレース一覧ページへ戻る
 *       │   変化: 内側ループの先頭へ戻り、次のレースへ進む
 *       │
 *       └─ （内側ループ終了）
 *
 *   ▼ D-4: 「開催選択へ戻る」リンクをクリック
 *       操作: ページ内の「開催選択へ戻る」テキストのリンクをクリック
 *       変化: STEP B の「オッズ 開催選択ページ」へ戻る → 次の開催へ進む
 *
 * ▼ 全開催ループ終了後 ── JSON を stdout に出力
 *   { schedules: [...], races: [...], horses: [...] }
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【出力 JSON 構造】
 *   {
 *     schedules: [ { date, kaisuu, basho, basho_name, day } ],
 *     races:     [ { date, kaisuu, basho, basho_name, day, race, race_name, start_time, num_horses } ],
 *     horses:    [ { date, kaisuu, basho, basho_name, day, race, waku, num, name, horse_url, jockey, trainer } ]
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
//   logStream: flags: 'w' = 毎回上書き（スケジュール取得は毎回新鮮なログを取りたい）
//   lockFile : 二重起動防止（引数なしの単一ロック）
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetSchedule.log'), { flags: 'w' }); // 上書きモード
const lockFile  = join(__dirname, 'keibaOddsGetSchedule.lock');

// ログをファイルと stderr の両方に書く
// stdout は PHP/Laravel が受け取る JSON 専用にしているため、ログは stderr へ
const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】定数
//   BASHO_MAP: 漢字名 → JRA 2桁場所コードのマッピング
//   sleep()  : 指定ミリ秒待機（DOM の非同期描画完了を待つために使用）
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
    // 【ブロック 5】二重起動チェック（シンプル版）
    //   スケジュール取得は並列実行の需要がないため、
    //   PID 生存確認を行わないシンプルな実装にしている。
    // ─────────────────────────────────────────────────────────
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
    // 【ブロック 7】Step 1: JRAトップ → オッズ開催選択ページへ遷移
    //   JRAトップの「オッズ」テキストのリンクをクリックして
    //   開催選択ページへ遷移する。
    //   Promise.all を使うことで、クリックイベントとナビゲーション完了を
    //   同時に待つ（クリック後にナビゲーションを待つと競合する場合がある）
    // ─────────────────────────────────────────────────────────
    log('[Step 1] JRAサイトにアクセス中...');
    await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });

    log('[Step 1] 「オッズ」リンクをクリック...');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
        page.evaluate(() => {
            [...document.querySelectorAll('a')]
                .find(a => a.textContent.trim() === 'オッズ')
                ?.click();
        }).catch(e => { if (!e.message.includes('closed')) throw e; }), // ページ遷移による context closed は無視
    ]);
    await sleep(1000);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8】Step 2: 開催情報の収集
    //   オッズ開催選択ページには h3.sub_header（日付）と
    //   その直後の要素内にある開催リンク（"X回場所Y日"）が存在する。
    //   年度は new Date().getFullYear() で取得するが、
    //   ブラウザコンテキスト内で実行されるため node の Date と同じ値になる。
    // ─────────────────────────────────────────────────────────
    log('[Step 2] 開催情報を取得中...');
    const kaisaiList = await page.evaluate((bashoMap) => {
        const year = new Date().getFullYear(); // 現在年を取得（ページに年の記載がないため）
        const list = [];

        document.querySelectorAll('h3.sub_header').forEach(h3 => {
            // h3 から "M月D日" を抽出（年は上で取得済み）
            const dm = h3.textContent.match(/(\d+)月(\d+)日/);
            if (!dm) return;

            const date = `${year}-${dm[1].padStart(2,'0')}-${dm[2].padStart(2,'0')}`;

            // h3 の直後の要素（ul 等）内のリンクから開催情報を収集
            h3.nextElementSibling?.querySelectorAll('a').forEach(a => {
                const m = a.textContent.trim().match(/^(\d+)回(.+?)(\d+)日$/);
                if (!m) return;
                list.push({
                    date,
                    kaisuu:     m[1],                     // 開催回数（文字列）
                    basho:      bashoMap[m[2]] ?? '00',   // 場所コード（未定義は '00'）
                    basho_name: m[2],                     // 場所漢字名
                    day:        Number(m[3]),              // 開催日次
                    onclick:    a.getAttribute('onclick'), // レース一覧へのリンク属性
                    label:      `${m[1]}回${m[2]}${m[3]}日`, // ログ表示用ラベル
                });
            });
        });
        return list;
    }, BASHO_MAP); // BASHO_MAP をブラウザコンテキストに渡す

    log(`[Step 2] 開催情報 ${kaisaiList.length}件取得`);
    kaisaiList.forEach(k => log(`  → ${k.label} (${k.date})`));

    if (kaisaiList.length === 0) {
        log('[Step 2] 本日の開催情報なし。終了します。');
        console.log(JSON.stringify({ schedules: [], races: [], horses: [] }));
        return; // finally でブラウザ・ロックを解放
    }

    const result = { schedules: [], races: [], horses: [] };

    // ─────────────────────────────────────────────────────────
    // 【ブロック 9】Step 3 & 4: 各開催のレース・馬情報取得ループ
    // ─────────────────────────────────────────────────────────
    for (const kaisai of kaisaiList) {
        log('');
        log(`----------------------------------------------------------------`);
        log(`[Step 3] ${kaisai.label} (${kaisai.date}) 処理開始`);
        log(`----------------------------------------------------------------`);

        // schedules テーブル用データを追加
        result.schedules.push({
            date:       kaisai.date,
            kaisuu:     kaisai.kaisuu,
            basho:      kaisai.basho,
            basho_name: kaisai.basho_name,
            day:        kaisai.day,
        });

        // 開催リンクをクリック → レース一覧ページへ遷移
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

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】レース一覧の取得
        //   tbody tr がレース1行に対応する。
        //   raceNum の決定には「単複リンクの属性値」を優先し、
        //   見つからない場合は「行インデックス+1」をフォールバックとする。
        //   これにより、11R・12R だけ表示されている場合にも正しいレース番号が得られる。
        //
        //   レース番号の抽出パターン:
        //     パターン1: "/0*N/" 形式（例: "/11/" や "/011/"）
        //     パターン2: カンマ・クォートで囲まれた数字（例: ",'11',"）
        //     フォールバック: 行インデックス+1
        // ─────────────────────────────────────────────────────
        const raceInfoList = await page.evaluate(() => {
            return [...document.querySelectorAll('tbody tr')].map((row, i) => {
                // 発走時刻（例: "15時40分" or "15:40"）を "HH:MM:SS" 形式に変換
                const timeText  = row.querySelector('td.time')?.textContent.trim() ?? '';
                const tm        = timeText.match(/(\d+)[時:](\d+)/);
                const startTime = tm
                    ? `${tm[1].padStart(2,'0')}:${tm[2].padStart(2,'0')}:00`
                    : 'XXX'; // 発走時刻が取れない場合のダミー値

                const raceName    = row.querySelector('td.race_name div div')?.textContent.trim() ?? '';
                const tanpukuLink = row.querySelector('div.tanpuku a'); // 単複リンク要素
                const hasTanpuku  = !!tanpukuLink; // 単複リンクの有無（出走取消等はなし）

                // 単複リンクの onclick または href からレース番号を抽出
                const tanpukuAttr = tanpukuLink?.getAttribute('onclick')
                    ?? tanpukuLink?.getAttribute('href')
                    ?? '';
                const raceNum = (() => {
                    // パターン1: "/N/" 形式（先頭の0は無視）
                    const slashMatches = [...tanpukuAttr.matchAll(/\/0*(\d{1,2})\//g)];
                    if (slashMatches.length > 0) {
                        // 最後のマッチを使用（URL パスの末尾のレース番号を取得するため）
                        return Number(slashMatches[slashMatches.length - 1][1]);
                    }
                    // パターン2: カンマ・シングルクォートで囲まれた数字
                    const quoteMatches = [...tanpukuAttr.matchAll(/[,']\s*0*(\d{1,2})\s*[,'"]/g)];
                    if (quoteMatches.length > 0) {
                        return Number(quoteMatches[quoteMatches.length - 1][1]);
                    }
                    // フォールバック: 行インデックス+1（レース番号と行位置が一致する前提）
                    return i + 1;
                })();

                return { raceNum, rowIndex: i, raceName, startTime, hasTanpuku, tanpukuAttr };
            });
        });

        log(`  レース一覧: ${raceInfoList.length}件確認`);
        raceInfoList.forEach(ri =>
            log(`    行${ri.rowIndex}: raceNum=${ri.raceNum} startTime=${ri.startTime} attr="${ri.tanpukuAttr.slice(0, 80)}"`)
        );

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】Step 4: 各レースの馬名・騎手・調教師を取得
        //   「単複リンク」をクリック → 単複オッズページへ遷移
        //   → table.tanpuku から馬情報を取得 → レース一覧へ戻る
        // ─────────────────────────────────────────────────────
        for (const ri of raceInfoList) {
            const raceLabel = `${kaisai.label} ${ri.raceNum}R`;

            // 単複リンクがない場合（出走取消・レース中止等）はスキップ
            if (!ri.hasTanpuku) {
                log(`  [${raceLabel}] 単複リンクなし → スキップ (${ri.startTime})`);
                // races テーブルには登録（頭数0で）
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

            // ナビゲーション前に単複リンクの存在を再確認する
            // （画面戻り後にDOMが変わっている場合があるため）
            const hasLink = await page.evaluate((idx) => {
                return !!document.querySelectorAll('tbody tr')[idx]
                    ?.querySelector('div.tanpuku a');
            }, ri.rowIndex);

            if (!hasLink) {
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

            // クリックとナビゲーション完了を同時に待つ（競合を防ぐ）
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }),
                page.evaluate((idx) => {
                    document.querySelectorAll('tbody tr')[idx]
                        ?.querySelector('div.tanpuku a')
                        ?.click();
                }, ri.rowIndex).catch(e => { if (!e.message.includes('closed')) throw e; }),
            ]);
            await sleep(1000);

            // ─────────────────────────────────────────────────
            // 【ブロック 12】単複オッズページから馬情報を取得
            //   table.tanpuku の構造:
            //     tbody tr
            //       td.waku img[alt="枠X"] : 枠番画像（rowspan でセル結合あり）
            //       td.num                 : 馬番
            //       td.horse a             : 馬名（リンク先が馬詳細ページ）
            //       td.jockey a            : 騎手名
            //       td.trainer a           : 調教師名
            //
            //   枠番は rowspan で結合されているため、<img> がある行でのみ更新し、
            //   ない行では前の値を引き継ぐ。
            // ─────────────────────────────────────────────────
            const horseData = await page.evaluate(() => {
                // ページテキストから「コース：1,200メートル（芝・右）」を抽出
                const pageText = document.body.innerText ?? '';
                const courseMatch = pageText.match(/コース[：:]\s*([\d,，]+)\s*メートル[（(]([^)）・\s]+)/);
                const dist   = courseMatch ? Number(courseMatch[1].replace(/[,，]/g, '')) : 0;
                const course = courseMatch ? courseMatch[2].trim() : '';

                const table = document.querySelector('table.tanpuku');
                if (!table) return { list: [], course, dist };

                const list = [];
                let waku = 0; // 現在の枠番（前の行から引き継ぐ）

                table.querySelectorAll('tbody tr').forEach(row => {
                    // 枠番画像があれば枠番を更新（rowspan のある行のみ img が存在する）
                    const wakuImg = row.querySelector('td.waku img');
                    if (wakuImg) {
                        const m = wakuImg.getAttribute('alt')?.match(/枠(\d+)/);
                        if (m) waku = Number(m[1]);
                    }

                    const numEl  = row.querySelector('td.num');     // 馬番セル
                    const nameEl = row.querySelector('td.horse a'); // 馬名リンク
                    if (!numEl || !nameEl) return; // 必須要素がなければスキップ

                    const num  = numEl.textContent.trim();
                    const name = nameEl.textContent.trim();
                    if (!num || !name) return; // 空の場合もスキップ

                    list.push({
                        waku,
                        num:       Number(num),
                        name,
                        horse_url: nameEl.href || '',                                           // 馬詳細ページ URL
                        jockey:    row.querySelector('td.jockey a')?.textContent.trim()  ?? '', // 騎手名
                        trainer:   row.querySelector('td.trainer a')?.textContent.trim() ?? '', // 調教師名
                    });
                });

                return { list, course, dist };
            });

            const horses  = horseData.list;
            const rCourse = horseData.course;
            const rDist   = horseData.dist;

            log(`  [${raceLabel}] ${horses.length}頭取得 course=${rCourse} dist=${rDist}`);
            horses.forEach(h =>
                log(`    馬番${String(h.num).padStart(2,' ')} 枠${h.waku} ${h.name} / 騎手:${h.jockey} / 師:${h.trainer}`)
            );

            // races テーブル用データを追加
            result.races.push({
                date:       kaisai.date,
                kaisuu:     kaisai.kaisuu,
                basho:      kaisai.basho,
                basho_name: kaisai.basho_name,
                day:        kaisai.day,
                race:       ri.raceNum,
                race_name:  ri.raceName,
                start_time: ri.startTime,
                course:     rCourse,
                dist:       rDist,
                num_horses: horses.length,
            });

            // horses テーブル用データを追加（馬ごとに1レコード）
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

            // レース一覧ページへ戻る（次のレースの単複リンクをクリックするため）
            log(`  [${raceLabel}] レース一覧へ戻る...`);
            await page.goBack();
            try {
                // レース一覧テーブルが復元されるまで最大8秒待つ
                await page.waitForSelector('tbody tr', { timeout: 8000 });
            } catch (_) {
                // タイムアウトしても致命的でない → 警告ログだけ出して続行
                log(`  [${raceLabel}] WARNING: レース一覧の復元タイムアウト`);
            }
            await sleep(1000);
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 13】開催選択ページへ戻る
        //   次の開催を処理するために開催選択ページへ戻る。
        //   「開催選択へ戻る」テキストのリンクをクリックする。
        // ─────────────────────────────────────────────────────
        log(`  ${kaisai.label} 全レース完了 → 開催選択へ戻る`);
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
    // 【ブロック 14】完了ログ・JSON 出力
    // ─────────────────────────────────────────────────────────
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
        // ─────────────────────────────────────────────────────
        // 【ブロック 15】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}`);
        console.log(JSON.stringify({ schedules: [], races: [], horses: [] }));
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
