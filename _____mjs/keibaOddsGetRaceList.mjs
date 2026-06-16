/**
 * keibaOddsGetRaceList.mjs
 *
 * Usage:
 *   node keibaOddsGetRaceList.mjs 20260531      # 指定日
 *   node keibaOddsGetRaceList.mjs all            # 表示されている全開催日
 */

import { chromium } from "playwright";
import { existsSync, writeFileSync, unlinkSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const lockFile  = join(__dirname, "keibaOddsGetRaceList.lock");

const BASE_URL = "https://race.netkeiba.com/top/";
const SUB_URL = "https://race.netkeiba.com/top/race_list_sub.html";
const TOP_URL = `${BASE_URL}?rf=navi`;
const arg = process.argv[2] ?? null;

// レース一覧HTMLから開催場所ブロックを抽出する関数（page.evaluate内で使用）
function extractPlaces(lists) {
    return lists.map((list) => {
        const titleEl = list.querySelector(".RaceList_DataTitle");
        const titleRaw = titleEl ? titleEl.textContent.trim() : "";

        const m = titleRaw
            .replace(/\s+/g, " ")
            .match(/(\d+)回\s*(.+?)\s*(\d+)日目/);
        const kai = m ? m[1] : "";
        const place = m ? m[2] : titleRaw;
        const nichime = m ? m[3] : "";

        const races = Array.from(list.querySelectorAll(".RaceList_DataItem"))
            .map((item) => {
                const anchor = item.querySelector('a[href*="race_id"]');
                if (!anchor) return null;

                const hrefMatch = anchor.href.match(/race_id=(\d+)/);
                const race_id = hrefMatch ? hrefMatch[1] : "";

                const rNumEl = item.querySelector(".Race_Num span");
                const rNumRaw = rNumEl
                    ? rNumEl.textContent.replace(/\s+/g, " ").trim()
                    : "";
                const rNumMatch = rNumRaw.match(/(\d+)R/);
                const race_num = rNumMatch ? parseInt(rNumMatch[1], 10) : null;

                const nameEl = item.querySelector(".ItemTitle");
                const race_name = nameEl ? nameEl.textContent.trim() : "";

                const timeEl = item.querySelector(".RaceList_Itemtime");
                const start_time = timeEl ? timeEl.textContent.trim() : "";

                const distEl = item.querySelector(".RaceList_ItemLong");
                const distance = distEl ? distEl.textContent.trim() : "";

                const countEl = item.querySelector(".RaceList_Itemnumber");
                const countRaw = countEl ? countEl.textContent.trim() : "";
                const countMatch = countRaw.match(/(\d+)/);
                const horse_count = countMatch
                    ? parseInt(countMatch[1], 10)
                    : null;

                const grade = item.classList.contains("bg_jyoken") ? "G" : "";

                return {
                    race_id,
                    race_num,
                    race_name,
                    start_time,
                    distance,
                    horse_count,
                    grade,
                };
            })
            .filter(Boolean);

        return { kai, place, nichime, races };
    });
}

(async () => {
    // ── 多重起動チェック ──────────────────────────────────────────
    if (existsSync(lockFile)) {
        console.error("[LOCK] 既に起動中のため終了します");
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;

    try {
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();

        // まずトップページで利用可能な日付一覧を取得
        await page.goto(TOP_URL, { waitUntil: "domcontentloaded", timeout: 60000 });
        await page.waitForSelector("#date_list_sub li", { timeout: 30000 });
        await page.waitForTimeout(1000);

        const allDates = await page.$$eval("#date_list_sub li", (els) =>
            els.map((el) => ({
                date: el.getAttribute("date"),
                label: el.textContent.trim(),
                active: el.classList.contains("ui-tabs-active"),
            })),
        );

        let targetDates;
        if (!arg || arg === "active") {
            const active = allDates.find((d) => d.active);
            targetDates = active
                ? [active.date]
                : [allDates[allDates.length - 1]?.date];
        } else if (arg === "all") {
            targetDates = allDates.map((d) => d.date);
        } else {
            targetDates = [arg];
        }

        const result = {};

        for (const date of targetDates) {
            if (!date) continue;

            // 日付ごとに専用URLへ直接アクセス（タブ切り替え不要）
            const url = `${SUB_URL}?kaisai_date=${date}`;
            await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
            await page.waitForSelector(".RaceList_DataList", { timeout: 30000 });
            await page.waitForTimeout(500);

            const places = await page.$$eval(".RaceList_DataList", extractPlaces);

            result[date] = places;
            console.log(
                `[${date}] ${places.map((p) => `${p.place}(${p.races.length}R)`).join(", ")}`,
            );
        }

        console.log("\n=== RESULT JSON ===");
        console.log(JSON.stringify(result, null, 2));

    } catch (err) {
        console.error(`致命的エラー: ${err.message}`);
        process.exitCode = 1;
    } finally {
        // ── 必ずブラウザを閉じ、ロックを解除する ────────────
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
    }
})();
