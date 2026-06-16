/**
 * keibaOddsGetRaceResult.mjs
 *
 * netkeibaのレース結果ページ＋出馬表オッズページから
 * 着順・タイム・単勝複勝オッズをまとめて取得する
 *
 * Usage:
 *   node keibaOddsGetRaceResult.mjs 202605020901
 */

import { chromium } from "playwright";
import { existsSync, writeFileSync, unlinkSync, readFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";

const race_id = process.argv[2];
if (!race_id) {
    console.error("Usage: node keibaOddsGetRaceResult.mjs <race_id>");
    process.exit(1);
}

const __dirname = dirname(fileURLToPath(import.meta.url));
const lockFile = join(__dirname, `keibaOddsGetRaceResult_${race_id}.lock`);

const RESULT_URL = `https://race.netkeiba.com/race/result.html?race_id=${race_id}&rf=race_list`;
const SHUTUBA_URL = `https://race.netkeiba.com/race/shutuba.html?race_id=${race_id}&rf=race_list`;

/** ロックファイルが指すPIDが実際に生きているか確認する（スタールロック対策） */
function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

(async () => {
    // ── 多重起動チェック（スタールロック考慮）────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            console.error(`[LOCK] 既に起動中のため終了します: race_id=${race_id} (PID=${storedPid})`);
            console.log(JSON.stringify({ race_id, data: [] }));
            process.exit(0);
        }
        console.error(`[LOCK] 古いロックファイルを削除します (PID=${storedPid} は存在しない)`);
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;

    try {
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();

        // ---- 1. レース結果ページ（発走後のみ取得できる・失敗しても続行）---------
        let results = [];
        try {
            await page.goto(RESULT_URL, {
                waitUntil: "domcontentloaded",
                timeout: 60000,
            });
            const tableFound = await page.waitForSelector("#All_Result_Table", { timeout: 10000 })
                .then(() => true).catch(() => false);

            if (tableFound) {
                await page.waitForTimeout(1000);
                results = await page.$$eval("#All_Result_Table tbody tr", (rows) =>
                    rows.map((tr) => {
                        const cells = Array.from(tr.querySelectorAll("td"));
                        const get = (i) =>
                            cells[i]?.textContent.replace(/\s+/g, " ").trim() ?? "";

                        const stablerRaw = get(13).split(/\s+/).filter(Boolean);
                        const stable_loc = stablerRaw[0] ?? "";
                        const trainer = stablerRaw[1] ?? "";

                        const weightRaw = get(14);
                        const weightMatch = weightRaw.match(/(\d+)\(([+\-]\d+)\)/);

                        const horseAnchor = tr.querySelector(
                            'td.Horse_Info a[href*="/horse/"]',
                        );
                        const horseIdMatch = horseAnchor?.href.match(/\/horse\/(\w+)/);

                        return {
                            rank: parseInt(get(0), 10) || null,
                            waku: parseInt(get(1), 10) || null,
                            horse_num: parseInt(get(2), 10) || null,
                            horse_name: get(3),
                            horse_id: horseIdMatch ? horseIdMatch[1] : "",
                            sex_age: get(4),
                            weight_carry: parseFloat(get(5)) || null,
                            jockey: get(6),
                            time: get(7),
                            margin: get(8),
                            popularity: parseInt(get(9), 10) || null,
                            last_3f: parseFloat(get(11)) || null,
                            corner_order: get(12),
                            stable_loc,
                            trainer,
                            horse_weight: weightMatch ? parseInt(weightMatch[1], 10) : null,
                            horse_weight_diff: weightMatch
                                ? parseInt(weightMatch[2], 10)
                                : null,
                        };
                    }),
                );
                console.error(`[INFO] レース結果取得: ${results.length}頭`);
            } else {
                console.error("[INFO] レース結果未確定（発走前）→ 出馬表オッズのみ取得します");
            }
        } catch (err) {
            console.error(`[INFO] レース結果ページエラー（発走前の可能性）: ${err.message}`);
        }

        // ---- 2. 出馬表オッズページ（単勝・複勝・発走前後いずれも取得可）------
        await page.goto(SHUTUBA_URL, {
            waitUntil: "domcontentloaded",
            timeout: 60000,
        });
        await page.click("#navi_odds_view");
        await page.waitForSelector("#Ninki", { timeout: 15000 });
        await page.waitForTimeout(1000);

        const oddsMap = await page.$$eval(
            '#Ninki tbody tr[id^="ninki-data-"]',
            (rows) => {
                const map = {};
                rows.forEach((tr) => {
                    const horseNumEl = tr.querySelector('[id^="uno-"]');
                    const horse_num = horseNumEl
                        ? parseInt(horseNumEl.textContent.trim(), 10)
                        : null;
                    if (!horse_num) return;

                    const tanOddsEl = tr.querySelector('[id^="odds-1_"]');
                    const tan_odds = tanOddsEl
                        ? parseFloat(tanOddsEl.textContent.trim()) || null
                        : null;

                    const fukuOddsEl = tr.querySelector('[id^="odds-2_"]');
                    const fukuOddsRaw = fukuOddsEl
                        ? fukuOddsEl.textContent.trim()
                        : "";
                    const fukuMatch = fukuOddsRaw.match(/([\d.]+)\s*-\s*([\d.]+)/);
                    const fuku_odds_min = fukuMatch
                        ? parseFloat(fukuMatch[1])
                        : null;
                    const fuku_odds_max = fukuMatch
                        ? parseFloat(fukuMatch[2])
                        : null;

                    map[horse_num] = { tan_odds, fuku_odds_min, fuku_odds_max };
                });
                return map;
            },
        );

        console.error(`[INFO] 出馬表オッズ取得: ${Object.keys(oddsMap).length}頭`);

        // ---- 3. 馬番をキーに結合 -------------------------------------------
        // 発走後: 結果データにオッズを付加
        // 発走前: oddsMapのみからデータを構築（resultsが空のため）
        let combined;
        if (results.length > 0) {
            combined = results.map((r) => {
                const oddsData = oddsMap[r.horse_num] ?? {};
                return {
                    ...r,
                    odds: oddsData.tan_odds ?? null,
                    fuku_odds_min: oddsData.fuku_odds_min ?? null,
                    fuku_odds_max: oddsData.fuku_odds_max ?? null,
                };
            });
        } else {
            combined = Object.entries(oddsMap).map(([num, oddsData]) => ({
                horse_num: parseInt(num, 10),
                odds: oddsData.tan_odds ?? null,
                fuku_odds_min: oddsData.fuku_odds_min ?? null,
                fuku_odds_max: oddsData.fuku_odds_max ?? null,
            }));
        }

        const output = { race_id, data: combined };
        console.log(JSON.stringify(output, null, 2));

    } catch (err) {
        console.error(`致命的エラー: ${err.message}`);
        console.error(err.stack ?? "");
        console.log(JSON.stringify({ race_id, data: [] }));
        process.exitCode = 1;

    } finally {
        // ── 必ずブラウザを閉じ、ロックを解除する ────────────────────
        if (browser) {
            await browser.close();
        }
        if (existsSync(lockFile)) {
            unlinkSync(lockFile);
        }
    }
})();
