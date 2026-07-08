/**
 * keibaOddsGetRaceResult.mjs
 *
 * 【概要】
 *   指定した race_id のレース結果ページと出馬表オッズページから
 *   着順・タイム・騎手・馬体重・単勝複勝オッズをまとめて取得する。
 *
 * 【スクレイピングフロー】
 *   Step 1: レース結果ページ（発走後のみ取得可能、失敗しても続行）
 *             → 着順・タイム・騎手・馬体重・上がり3F 等を取得
 *   Step 2: 出馬表オッズページ（発走前後どちらでも取得可能）
 *             → 単勝オッズ・複勝オッズ（最小〜最大）を取得
 *   Step 3: 馬番をキーに Step1 の結果に Step2 のオッズを結合
 *             → 発走後: 結果 + オッズの結合データ
 *             → 発走前: オッズのみのデータ
 *
 * 【使い方】
 *   node keibaOddsGetRaceResult.mjs 202605020901
 *
 * 【標準出力】
 *   { "race_id": "...", "data": [ { rank, waku, horse_num, horse_name, ... }, ... ] }
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from "playwright";
import { existsSync, writeFileSync, unlinkSync, readFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】引数チェック・定数設定
//   race_id: レース固有ID（例: "202605020901"）
//     形式: YYYY + MM + DD + 場所コード(2) + レース番号(2)
//   RESULT_URL : レース結果ページ（発走後に有効）
//   SHUTUBA_URL: 出馬表オッズページ（発走前後どちらでも有効）
// ─────────────────────────────────────────────────────────────
const race_id = process.argv[2];
if (!race_id) {
    console.error("Usage: node keibaOddsGetRaceResult.mjs <race_id>");
    process.exit(1);
}

const __dirname = dirname(fileURLToPath(import.meta.url));
// race_id をキーに含めることで複数レースの同時実行を可能にする
const lockFile = join(__dirname, `keibaOddsGetRaceResult_${race_id}.lock`);

const RESULT_URL  = `https://race.netkeiba.com/race/result.html?race_id=${race_id}&rf=race_list`;
const SHUTUBA_URL = `https://race.netkeiba.com/race/shutuba.html?race_id=${race_id}&rf=race_list`;

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】スタールロック対策ユーティリティ
//   ロックファイルが残っていても PID が死んでいれば幽霊ロックとみなして削除する
// ─────────────────────────────────────────────────────────────
function isProcessAlive(pid) {
    // kill(pid, 0) はシグナルを送らず「プロセスの存在確認」のみ行う
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    // ─────────────────────────────────────────────────────────
    // 【ブロック 5】二重起動防止（スタールロック考慮）
    //   同じ race_id を指定した重複起動を防ぐ。
    //   ロックファイルがあっても PID が死んでいれば削除して続行する。
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            console.error(`[LOCK] 既に起動中のため終了します: race_id=${race_id} (PID=${storedPid})`);
            console.log(JSON.stringify({ race_id, data: [] }));
            process.exit(0);
        }
        // スタールロックを検出 → ロックファイルを削除して続行
        console.error(`[LOCK] 古いロックファイルを削除します (PID=${storedPid} は存在しない)`);
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid)); // 現在の PID を書き込む

    let browser = null;

    try {
        // ─────────────────────────────────────────────────────
        // 【ブロック 6】ブラウザ起動
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();

        // ─────────────────────────────────────────────────────
        // 【ブロック 7】Step 1: レース結果ページの取得
        //   発走後のみ有効なページ。発走前や発走直後で未確定の場合は
        //   #All_Result_Table が存在しないため、エラーを握り潰して続行する。
        //
        //   取得する列（テーブルの列順）:
        //     [0]着順  [1]枠番  [2]馬番  [3]馬名  [4]性齢  [5]斤量
        //     [6]騎手  [7]タイム [8]着差  [9]人気  [10]単勝オッズ
        //     [11]上がり3F [12]コーナー通過順 [13]厩舎 [14]馬体重
        // ─────────────────────────────────────────────────────
        let results = [];
        try {
            await page.goto(RESULT_URL, {
                waitUntil: "domcontentloaded",
                timeout: 60000,
            });
            // #All_Result_Table が出現するまで最大10秒待つ
            const tableFound = await page.waitForSelector("#All_Result_Table", { timeout: 10000 })
                .then(() => true).catch(() => false);

            if (tableFound) {
                await page.waitForTimeout(1000); // テーブルの完全描画を待つ

                results = await page.$$eval("#All_Result_Table tbody tr", (rows) =>
                    rows.map((tr) => {
                        const cells = Array.from(tr.querySelectorAll("td"));
                        // セル取得ヘルパー: 空白を除去して整合的なテキストにする
                        const get = (i) =>
                            cells[i]?.textContent.replace(/\s+/g, " ").trim() ?? "";

                        // 厩舎列: "美浦 田中博康" のように所属と名前がスペース区切り
                        const stablerRaw = get(13).split(/\s+/).filter(Boolean);
                        const stable_loc = stablerRaw[0] ?? ""; // "美浦" or "栗東"
                        const trainer    = stablerRaw[1] ?? ""; // 調教師名

                        // 馬体重列: "480(+2)" 等の形式 → 体重と増減を分離
                        const weightRaw   = get(14);
                        const weightMatch = weightRaw.match(/(\d+)\(([+\-]\d+)\)/);

                        // 馬名リンクから horse_id を取得
                        const horseAnchor  = tr.querySelector('td.Horse_Info a[href*="/horse/"]');
                        const horseIdMatch = horseAnchor?.href.match(/\/horse\/(\w+)/);

                        return {
                            rank:              parseInt(get(0), 10) || null,  // 着順（null = 中止/除外等）
                            waku:              parseInt(get(1), 10) || null,  // 枠番
                            horse_num:         parseInt(get(2), 10) || null,  // 馬番
                            horse_name:        get(3),                         // 馬名
                            horse_id:          horseIdMatch ? horseIdMatch[1] : "", // 馬の固有ID
                            sex_age:           get(4),                         // 性齢（例: "牡3"）
                            weight_carry:      parseFloat(get(5)) || null,     // 負担重量（斤量）
                            jockey:            get(6),                         // 騎手名
                            time:              get(7),                         // タイム（例: "1:34.5"）
                            margin:            get(8),                         // 着差
                            popularity:        parseInt(get(9), 10) || null,   // 人気
                            last_3f:           parseFloat(get(11)) || null,    // 上がり3F タイム
                            corner_order:      get(12),                        // コーナー通過順（例: "3-3-2-1"）
                            stable_loc,                                        // 厩舎所在地（美浦 or 栗東）
                            trainer,                                           // 調教師名
                            horse_weight:      weightMatch ? parseInt(weightMatch[1], 10) : null,  // 馬体重
                            horse_weight_diff: weightMatch ? parseInt(weightMatch[2], 10) : null,  // 前走比（kg）
                        };
                    }),
                );
                console.error(`[INFO] レース結果取得: ${results.length}頭`);
            } else {
                console.error("[INFO] レース結果未確定（発走前）→ 出馬表オッズのみ取得します");
            }
        } catch (err) {
            // ページ取得失敗（発走前・ネットワークエラー等）は警告のみで続行
            console.error(`[INFO] レース結果ページエラー（発走前の可能性）: ${err.message}`);
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】Step 2: 出馬表オッズページの取得
        //   発走前後どちらでも取得できる。
        //   「#navi_odds_view」クリックで「人気順」表示に切り替え、
        //   #Ninki テーブルから単勝・複勝オッズを取得する。
        //
        //   oddsMap の構造: { 馬番(number) → { tan_odds, fuku_odds_min, fuku_odds_max } }
        //
        //   複勝オッズは "1.2 - 2.4" のような範囲表示なので
        //   正規表現で最小値と最大値に分けて取り出す。
        // ─────────────────────────────────────────────────────
        await page.goto(SHUTUBA_URL, {
            waitUntil: "domcontentloaded",
            timeout: 60000,
        });
        // 「オッズ」タブ（人気順表示）をクリックしてオッズテーブルを表示
        await page.click("#navi_odds_view");
        await page.waitForSelector("#Ninki", { timeout: 15000 });
        await page.waitForTimeout(1000); // テーブルの描画が完了するまで待機

        const oddsMap = await page.$$eval(
            '#Ninki tbody tr[id^="ninki-data-"]', // 人気順テーブルの各行
            (rows) => {
                const map = {};
                rows.forEach((tr) => {
                    // 馬番セル: id が "uno-X" 形式
                    const horseNumEl = tr.querySelector('[id^="uno-"]');
                    const horse_num  = horseNumEl
                        ? parseInt(horseNumEl.textContent.trim(), 10)
                        : null;
                    if (!horse_num) return; // 馬番が取れない行はスキップ

                    // 単勝オッズ: id が "odds-1_X" 形式
                    const tanOddsEl = tr.querySelector('[id^="odds-1_"]');
                    const tan_odds  = tanOddsEl
                        ? parseFloat(tanOddsEl.textContent.trim()) || null
                        : null;

                    // 複勝オッズ: "1.2 - 2.4" 形式を最小・最大に分割
                    const fukuOddsEl  = tr.querySelector('[id^="odds-2_"]');
                    const fukuOddsRaw = fukuOddsEl
                        ? fukuOddsEl.textContent.trim()
                        : "";
                    const fukuMatch   = fukuOddsRaw.match(/([\d.]+)\s*-\s*([\d.]+)/);
                    const fuku_odds_min = fukuMatch ? parseFloat(fukuMatch[1]) : null;
                    const fuku_odds_max = fukuMatch ? parseFloat(fukuMatch[2]) : null;

                    map[horse_num] = { tan_odds, fuku_odds_min, fuku_odds_max };
                });
                return map;
            },
        );

        console.error(`[INFO] 出馬表オッズ取得: ${Object.keys(oddsMap).length}頭`);

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】Step 3: 結果とオッズを馬番で結合
        //   発走後（results あり）: 着順データにオッズを付加
        //   発走前（results なし）: oddsMap のみからデータを構築
        //   どちらの場合も最終的な出力構造を統一する
        // ─────────────────────────────────────────────────────
        let combined;
        if (results.length > 0) {
            // 発走後: 着順データの各馬に対してオッズを結合
            combined = results.map((r) => {
                const oddsData = oddsMap[r.horse_num] ?? {}; // 馬番でオッズを検索
                return {
                    ...r,
                    odds:          oddsData.tan_odds    ?? null, // 単勝オッズ
                    fuku_odds_min: oddsData.fuku_odds_min ?? null, // 複勝オッズ下限
                    fuku_odds_max: oddsData.fuku_odds_max ?? null, // 複勝オッズ上限
                };
            });
        } else {
            // 発走前: オッズのみのシンプルな配列を構築
            combined = Object.entries(oddsMap).map(([num, oddsData]) => ({
                horse_num:     parseInt(num, 10),
                odds:          oddsData.tan_odds    ?? null,
                fuku_odds_min: oddsData.fuku_odds_min ?? null,
                fuku_odds_max: oddsData.fuku_odds_max ?? null,
            }));
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】JSON 出力
        // ─────────────────────────────────────────────────────
        const output = { race_id, data: combined };
        console.log(JSON.stringify(output, null, 2));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 11】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        console.error(`致命的エラー: ${err.message}`);
        console.error(err.stack ?? "");
        console.log(JSON.stringify({ race_id, data: [] }));
        process.exitCode = 1;

    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 12】後処理（必ず実行）
        // ─────────────────────────────────────────────────────
        if (browser) {
            await browser.close();
        }
        if (existsSync(lockFile)) {
            unlinkSync(lockFile); // ロック解除
        }
    }
})();
