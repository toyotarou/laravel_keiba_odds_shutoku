/**
 * keibaOddsGetRaceList.mjs
 *
 * 【概要】
 *   レース一覧ページからレース情報を取得する。
 *   指定した日付のレース一覧、または表示されている全日付分を取得する。
 *
 * 【使い方】
 *   node keibaOddsGetRaceList.mjs 20260531   # 指定日のレース一覧
 *   node keibaOddsGetRaceList.mjs all         # 表示されている全開催日
 *   node keibaOddsGetRaceList.mjs             # 引数なし: アクティブな日付のみ
 *
 * 【取得項目（レースごと）】
 *   race_id, race_num, race_name, start_time, distance, horse_count, grade
 *
 * 【標準出力】
 *   進捗ログ（各日付の開催サマリー）
 *   最終的な JSON （=== RESULT JSON === の後）
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
//   playwright の chromium のみ使用。
//   ロックファイルは fs モジュールで管理する。
// ─────────────────────────────────────────────────────────────
import { chromium } from "playwright";
import { existsSync, writeFileSync, unlinkSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】定数・設定
//   BASE_URL : レース一覧トップページ
//   SUB_URL  : 日付を指定した際の直接アクセス URL
//   TOP_URL  : ナビゲーションパラメータ付きのトップ URL
//   arg      : コマンドライン引数（日付 or "all" or なし）
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const lockFile  = join(__dirname, "keibaOddsGetRaceList.lock"); // 二重起動防止ファイル

const BASE_URL = "https://race.netkeiba.com/top/";
const SUB_URL  = "https://race.netkeiba.com/top/race_list_sub.html"; // 日付指定アクセス用
const TOP_URL  = `${BASE_URL}?rf=navi`;                              // 利用可能な日付一覧を取得するためのトップ
const arg      = process.argv[2] ?? null;                            // "20260531", "all", または null

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】開催場所ブロックの抽出関数（extractPlaces）
//   page.$$eval の第2引数として渡す関数。
//   ブラウザのコンテキストで実行されるため Node.js の変数には
//   直接アクセスできない点に注意。
//
//   入力: .RaceList_DataList 要素の配列
//   出力: [ { kai, place, nichime, races: [...] } ] の配列
//
//   各要素の構造:
//     kai      : 開催回数（"1" 等）
//     place    : 開催場所（"東京" 等）
//     nichime  : 開催日次（"3" 等）
//     races    : そのボール場のレース情報の配列
// ─────────────────────────────────────────────────────────────
function extractPlaces(lists) {
    return lists.map((list) => {
        // タイトル要素（例: "1回東京3日目"）からの情報抽出
        const titleEl  = list.querySelector(".RaceList_DataTitle");
        const titleRaw = titleEl ? titleEl.textContent.trim() : "";

        // 正規表現で "X回 場所 Y日目" をパース（スペースが入る場合あり）
        const m = titleRaw
            .replace(/\s+/g, " ")
            .match(/(\d+)回\s*(.+?)\s*(\d+)日目/);
        const kai     = m ? m[1] : "";          // 開催回数
        const place   = m ? m[2] : titleRaw;   // 開催場所（マッチしない場合はタイトル全体）
        const nichime = m ? m[3] : "";          // 開催日次

        // ─────────────────────────────────────────────────
        // 各レースの情報を収集
        // ─────────────────────────────────────────────────
        const races = Array.from(list.querySelectorAll(".RaceList_DataItem"))
            .map((item) => {
                // race_id を含む href を持つアンカーを取得
                const anchor = item.querySelector('a[href*="race_id"]');
                if (!anchor) return null; // race_id なし → スキップ

                // href から race_id を抽出（例: "?race_id=202605020901"）
                const hrefMatch = anchor.href.match(/race_id=(\d+)/);
                const race_id = hrefMatch ? hrefMatch[1] : "";

                // レース番号（例: "9R" → 9）
                const rNumEl  = item.querySelector(".Race_Num span");
                const rNumRaw = rNumEl
                    ? rNumEl.textContent.replace(/\s+/g, " ").trim()
                    : "";
                const rNumMatch = rNumRaw.match(/(\d+)R/);
                const race_num  = rNumMatch ? parseInt(rNumMatch[1], 10) : null;

                // レース名（例: "東京ダービー"）
                const nameEl   = item.querySelector(".ItemTitle");
                const race_name = nameEl ? nameEl.textContent.trim() : "";

                // 発走時刻（例: "15:40"）
                const timeEl     = item.querySelector(".RaceList_Itemtime");
                const start_time = timeEl ? timeEl.textContent.trim() : "";

                // 距離・コース種別（例: "芝1600m"）
                const distEl   = item.querySelector(".RaceList_ItemLong");
                const distance = distEl ? distEl.textContent.trim() : "";

                // 出走頭数（例: "16頭" → 16）
                const countEl  = item.querySelector(".RaceList_Itemnumber");
                const countRaw = countEl ? countEl.textContent.trim() : "";
                const countMatch  = countRaw.match(/(\d+)/);
                const horse_count = countMatch
                    ? parseInt(countMatch[1], 10)
                    : null;

                // グレード判定: クラス名に "bg_jyoken" があればグレードレース
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
            .filter(Boolean); // null（race_id なしの要素）を除去

        return { kai, place, nichime, races };
    });
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    // ─────────────────────────────────────────────────────────
    // 【ブロック 5】二重起動チェック（シンプル版）
    //   ロックファイルが存在すれば即終了する。
    //   PID の生存確認は行わないシンプルな実装。
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        console.error("[LOCK] 既に起動中のため終了します");
        process.exit(0);
    }
    writeFileSync(lockFile, String(process.pid));

    let browser = null;

    try {
        // ─────────────────────────────────────────────────────
        // 【ブロック 6】ブラウザ起動・トップページアクセス
        //   まず TOP_URL を開いて、利用可能な日付のリストを取得する。
        //   日付は #date_list_sub の li 要素に含まれている。
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();

        // トップページを開いて日付タブ一覧を取得する
        await page.goto(TOP_URL, { waitUntil: "domcontentloaded", timeout: 60000 });
        await page.waitForSelector("#date_list_sub li", { timeout: 30000 });
        await page.waitForTimeout(1000); // タブの描画が完了するまで待機

        // 日付タブの一覧を全て取得（date属性・ラベル・アクティブ状態）
        const allDates = await page.$$eval("#date_list_sub li", (els) =>
            els.map((el) => ({
                date:   el.getAttribute("date"),       // 日付（例: "20260531"）
                label:  el.textContent.trim(),          // 表示テキスト（例: "5/31"）
                active: el.classList.contains("ui-tabs-active"), // 現在表示中かどうか
            })),
        );

        // ─────────────────────────────────────────────────────
        // 【ブロック 7】取得対象日付の決定
        //   引数なし or "active" : アクティブ（現在表示中）の日付のみ
        //   "all"               : 全ての利用可能な日付
        //   YYYYMMDD 形式       : その日付のみ（直接指定）
        // ─────────────────────────────────────────────────────
        let targetDates;
        if (!arg || arg === "active") {
            // アクティブな日付を探し、なければ最後の日付を使用
            const active = allDates.find((d) => d.active);
            targetDates = active
                ? [active.date]
                : [allDates[allDates.length - 1]?.date];
        } else if (arg === "all") {
            targetDates = allDates.map((d) => d.date); // 全日付
        } else {
            targetDates = [arg]; // 直接指定された日付のみ
        }

        const result = {}; // { "20260531": [ { kai, place, nichime, races } ], ... } 形式

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】各日付のレース一覧取得ループ
        //   タブをクリックする方式ではなく、日付ごとの専用 URL に
        //   直接アクセスすることで安定した取得を実現する。
        //   URL 形式: /race_list_sub.html?kaisai_date=YYYYMMDD
        // ─────────────────────────────────────────────────────
        for (const date of targetDates) {
            if (!date) continue; // 日付が null の場合はスキップ

            // 日付指定 URL に直接アクセス（タブ切り替えより安定）
            const url = `${SUB_URL}?kaisai_date=${date}`;
            await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
            await page.waitForSelector(".RaceList_DataList", { timeout: 30000 });
            await page.waitForTimeout(500); // DOM 描画が完了するまで待機

            // extractPlaces 関数を使って全開催場所のデータを取得
            const places = await page.$$eval(".RaceList_DataList", extractPlaces);

            result[date] = places;

            // 進捗ログ: 各日付の開催場所とレース数のサマリーを出力
            console.log(
                `[${date}] ${places.map((p) => `${p.place}(${p.races.length}R)`).join(", ")}`,
            );
        }

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】JSON 出力
        //   "=== RESULT JSON ===" の行の後に JSON を出力することで、
        //   呼び出し元が JSON 部分だけを抽出しやすくしている。
        // ─────────────────────────────────────────────────────
        console.log("\n=== RESULT JSON ===");
        console.log(JSON.stringify(result, null, 2));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 10】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        console.error(`致命的エラー: ${err.message}`);
        process.exitCode = 1;
    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 11】後処理（必ず実行）
        //   ブラウザクローズとロックファイル削除を確実に行う。
        // ─────────────────────────────────────────────────────
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile);
    }
})();
