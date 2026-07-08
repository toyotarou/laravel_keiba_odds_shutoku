/**
 * keibaOddsGetFinishingPosition.mjs
 *
 * 【概要】
 *   JRA「過去レース結果」ページから、指定した年月・開催の
 *   全レースの「着順・馬番・馬名」を取得してJSONで標準出力する。
 *
 * 【スクレイピングフロー】
 *   accessS.html（JRAデータベース）
 *     → 「過去のレース結果」リンクをクリック
 *     → 年月セレクトボックスを操作して検索
 *     → 指定開催のボタン（例: 1回中山1日）をクリック
 *     → 「全てのレースを表示」ボタンをクリック
 *     → ページ内の全レース結果テーブルを一括パース
 *
 * 【ページ構造（JRA調査済み）】
 *   ・各レースは <div class="race_result_unit" id="race_result_5R"> で1単位
 *   ・id="race_result_<N>R" の N がレース番号
 *   ・結果テーブルは table.basic.narrow-xy.striped
 *   ・列順は固定: [0]着順 [1]枠 [2]馬番 [3]馬名 ... （14〜15列）
 *
 * 【使い方】
 *   node keibaOddsGetFinishingPosition.mjs --yearmonth=2023-01 --kaisai=1回中山1日
 *
 * 【標準出力 (JSON)】
 *   {
 *     "yearmonth": "2023-01",
 *     "kaisai": "1回中山1日",
 *     "races": [
 *       {
 *         "race": 1,
 *         "horses": [
 *           { "chakujun": 1, "num": 14, "name": "シュバルツガイスト" },
 *           ...
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 *   ※ chakujun は数字ならその整数、「中止」「除外」等は元の文字列のまま
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
//   - chromium: Playwright のヘッドレスブラウザ本体
//   - fs 系: ロックファイル・ログファイルの読み書き
//   - url/path: __dirname を ESModules で再現するためのユーティリティ
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { existsSync, writeFileSync, unlinkSync, readFileSync, createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】コマンドライン引数のパース
//   --yearmonth=YYYY-MM  : 取得対象の年月（必須）
//   --kaisai=X回場所Y日  : 取得対象の開催名（必須）
//   どちらか欠けていたら使い方を表示して即終了する
// ─────────────────────────────────────────────────────────────
const args = process.argv.slice(2); // node と スクリプト名を除いた引数配列
const yearmonthArg = args.find(a => a.startsWith('--yearmonth='));
const kaisaiArg    = args.find(a => a.startsWith('--kaisai='));

if (!yearmonthArg || !kaisaiArg) {
    process.stderr.write('Usage: node keibaOddsGetFinishingPosition.mjs --yearmonth=2023-01 --kaisai=1回中山1日\n');
    process.exit(1);
}

// '=' 以降を値として取り出す
const yearmonth     = yearmonthArg.split('=')[1]; // 例: "2023-01"
const kaisaiFilter  = kaisaiArg.split('=')[1];    // 例: "1回中山1日"
const [year, month] = yearmonth.split('-');        // 年と月を分割

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】ロックファイル・ログストリームの設定
//   ロックファイル:
//     同じ yearmonth + kaisai の二重起動を防ぐ。
//     ファイル名に引数を含めることで、別の開催を同時実行しても
//     互いに干渉しないようにする。
//   ログ:
//     stderr と .log ファイルの両方に同じ内容を書き出す。
//     stdout は JSON 専用にしているため、ログは必ず stderr へ。
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url)); // ESModules での __dirname 相当
const lockKey   = `${yearmonth}_${kaisaiFilter}`.replace(/\s/g, ''); // スペースを除去してファイル名に使用
const lockFile  = join(__dirname, `keibaOddsGetFinishingPosition_${lockKey}.lock`);
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetFinishingPosition.log'), { flags: 'a' }); // 追記モード

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ユーティリティ関数群
//   log()             : タイムスタンプ付きでログを stderr とファイルに書き出す
//   sleep()           : 指定ミリ秒だけ非同期で待機する（サーバー負荷軽減・DOM安定待ち）
//   isProcessAlive()  : PID が現在も生きているかを確認する（スタールロック対策）
//                       process.kill(pid, 0) はシグナル送信ではなく存在チェックのみ
// ─────────────────────────────────────────────────────────────
const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function isProcessAlive(pid) {
    // シグナル 0 は「プロセスへの到達確認」に使われる特殊シグナル
    // 生きていれば true を返し、存在しなければ例外がスローされる
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】開催一覧ページへの遷移ヘルパー関数
//   JRAデータベースの過去レース結果ページを開き、
//   年月セレクトを操作して対象の開催一覧を表示させる。
//
//   手順:
//     (5-1) accessS.html を開く
//     (5-2) 「過去のレース結果」リンクをクリック → セレクトが出現
//     (5-3) 年・月セレクトに値をセットして change イベントを発火
//     (5-4) 検索リンクをクリックして開催一覧を読み込む
// ─────────────────────────────────────────────────────────────
async function navigateToKaisaiList(page, year, month) {
    // (5-1) JRAデータベーストップへアクセス
    await page.goto('https://www.jra.go.jp/JRADB/accessS.html', {
        waitUntil: 'networkidle', // ネットワークが静止するまで待つ
        timeout: 60000,
    });
    await sleep(1000); // DOM が安定するまで少し待つ

    // (5-2) 「過去のレース結果」テキストのリンクを探してクリック
    await page.evaluate(() => {
        Array.from(document.querySelectorAll('a'))
            .find(el => el.textContent.trim() === '過去のレース結果')?.click();
    });
    // セレクトボックスが現れるまで最大15秒待つ
    await page.waitForSelector('select', { timeout: 15000 }).catch(() => {});
    await sleep(1500);

    // (5-3) ページ内の最初のセレクト=年、2番目=月 として値をセット
    //        月は 0 埋め2桁（"01"〜"12"）にする
    await page.evaluate(({ y, m }) => {
        const selects = document.querySelectorAll('select');
        if (selects[0]) { selects[0].value = y; selects[0].dispatchEvent(new Event('change')); }
        if (selects[1]) { selects[1].value = m.padStart(2, '0'); selects[1].dispatchEvent(new Event('change')); }
    }, { y: year, m: month });
    await sleep(500);

    // (5-4) onclick="getSelectData();" のリンクをクリックして検索実行
    //        見つからない場合はグローバル関数を直接呼ぶフォールバック
    await page.evaluate(() => {
        const searchLink = Array.from(document.querySelectorAll('a'))
            .find(a => a.getAttribute('onclick') === 'getSelectData();');
        if (searchLink) { searchLink.click(); } else { getSelectData(); }
    });
    // 開催一覧のリンクが出るまで最大15秒待つ
    await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
    await sleep(2000);
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 6】メイン処理（即時実行非同期関数）
//   全体の流れを制御し、エラー発生時は必ずブラウザを閉じて
//   ロックファイルを削除してから終了する。
// ─────────────────────────────────────────────────────────────
(async () => {

    // ─────────────────────────────────────────────────────────
    // 【ブロック 7】二重起動防止（スタールロック考慮）
    //   同じ引数の組み合わせで既に起動中なら空の結果を返して終了する。
    //   ただし、ロックファイルが残っていても PID が存在しない場合は
    //   「スタールロック（幽霊ロック）」なので削除して続行する。
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            // まだ同じプロセスが動いている → 空の結果を返して即終了
            log(`[LOCK] 既に起動中のため終了します (PID=${storedPid})`);
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races: [] }));
            logStream.end();
            process.exit(0);
        }
        // PID が死んでいる → スタールロックなので削除する
        unlinkSync(lockFile);
    }
    // 現在の PID をロックファイルに書き込む
    writeFileSync(lockFile, String(process.pid));

    let browser = null;
    let races = [];

    try {
        log('================================================================');
        log(`keibaOddsGetFinishingPosition 開始 yearmonth=${yearmonth} kaisai=${kaisaiFilter}`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】ブラウザ起動
        //   headless: true   = ウィンドウを表示しないモード（サーバー環境向け）
        //   --no-sandbox     = Docker/Linux 環境でサンドボックスエラーを回避
        //   ビューポートを 1600px 高にするのはページ全体を一画面に収めて
        //   動的コンテンツの遅延レンダリングを防ぐため
        // ─────────────────────────────────────────────────────
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 1600 });

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】開催一覧ページへ遷移
        //   ブロック 5 のヘルパーを呼び出す
        // ─────────────────────────────────────────────────────
        await navigateToKaisaiList(page, year, month);

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】指定開催ボタンをクリック
        //   kaisaiFilter（例: "1回中山1日"）と完全一致するリンクを探す。
        //   テキストに空白が混入する場合があるため replace(/\s+/g, '') で正規化。
        // ─────────────────────────────────────────────────────
        const kaisaiText = kaisaiFilter.replace(/\s+/g, ''); // 空白を除去して比較用テキストを作成
        const clickedKaisai = await page.evaluate(({ kaisaiText }) => {
            const target = Array.from(document.querySelectorAll('a'))
                .find(a => a.textContent.replace(/\s+/g, '').trim() === kaisaiText);
            if (target) { target.click(); return true; }
            return false;
        }, { kaisaiText });

        if (!clickedKaisai) {
            // 指定された開催名がページ内に存在しない場合は空の結果を返す
            log(`ERROR: 開催「${kaisaiText}」が見つかりませんでした。`);
            console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races: [] }));
            return;
        }
        log(`開催ボタン「${kaisaiText}」クリック OK`);
        await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
        await sleep(2000);

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】「全てのレースを表示」ボタンをクリック
        //   このボタンをクリックすると 1R〜最終Rの全結果が
        //   1ページに展開される。ボタンが存在しない場合は
        //   既に全レース表示済みなので続行する。
        // ─────────────────────────────────────────────────────
        const clickedAll = await page.evaluate(() => {
            const target = Array.from(document.querySelectorAll('a, button'))
                .find(el => el.textContent.includes('全てのレースを表示'));
            if (target) { target.click(); return true; }
            return false;
        });
        log(`「全てのレースを表示」クリック ${clickedAll ? 'OK' : '（ボタン無し→続行）'}`);
        // テーブルが全レース分出揃うまで待つ（最大15秒）
        await page.waitForSelector('div.race_result_unit table.basic.narrow-xy.striped', { timeout: 15000 }).catch(() => {});
        await sleep(2500); // ページの非同期レンダリングが完了するまで追加で待機

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】全レース結果を一括パース（page.evaluate 内）
        //   DOM 操作は page.evaluate 内で行い、結果のみを Node.js 側へ返す。
        //
        //   DOM 構造:
        //     div.race_result_unit[id="race_result_5R"]
        //       └─ table.basic.narrow-xy.striped
        //            └─ tbody
        //                 └─ tr（1頭1行）
        //                      ├─ td[0] 着順
        //                      ├─ td[1] 枠
        //                      ├─ td[2] 馬番
        //                      └─ td[3] 馬名
        //
        //   parseChakujun():
        //     着順が純粋な数字なら整数に変換。
        //     「中止」「除外」「取消」「失格」「降着」などの
        //     特殊文字列はそのまま文字列として保持する。
        // ─────────────────────────────────────────────────────
        races = await page.evaluate(() => {
            // 文字列正規化: 前後空白・連続空白を単一スペースに潰してトリム
            const norm = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

            // 着順テキストを整数または文字列に変換
            const parseChakujun = (raw) => {
                const t = norm(raw);
                return /^\d+$/.test(t) ? parseInt(t, 10) : (t || null);
            };

            const out = [];
            // ページ内の全レース単位 div を取得
            const units = Array.from(document.querySelectorAll('div.race_result_unit'));

            units.forEach((unit) => {
                // id 属性からレース番号を抽出 ("race_result_5R" → 5)
                const m = (unit.id || '').match(/race_result_(\d+)R/);
                if (!m) return; // id が期待形式でない場合はスキップ
                const raceNum = parseInt(m[1], 10);

                // レース結果テーブルを取得
                const table = unit.querySelector('table.basic.narrow-xy.striped');
                if (!table) return; // テーブルが存在しない（未確定レース等）

                const horses = [];
                table.querySelectorAll('tbody tr').forEach((row) => {
                    const cells = Array.from(row.querySelectorAll('td'));
                    if (cells.length < 4) return; // 列数が足りない行はヘッダ等→スキップ

                    // 列順固定: [0]着順 [1]枠 [2]馬番 [3]馬名
                    const chakujun = parseChakujun(cells[0].textContent);
                    const numText  = norm(cells[2].textContent); // 馬番テキスト
                    const name     = norm(cells[3].textContent); // 馬名テキスト

                    // 馬番が数字でない行（区切り行・注記行等）は除外
                    if (!/^\d+$/.test(numText)) return;
                    if (!name) return; // 馬名が空の行もスキップ

                    horses.push({
                        chakujun,
                        num: parseInt(numText, 10),
                        name,
                    });
                });

                if (horses.length > 0) {
                    out.push({ race: raceNum, horses });
                }
            });

            // レース番号の昇順でソート（ページ内の順序が保証されないため）
            out.sort((a, b) => a.race - b.race);
            return out;
        });

        // ─────────────────────────────────────────────────────
        // 【ブロック 13】取得結果のログ出力
        //   全レース・全馬の集計とレースごとの1着馬をログに記録する
        // ─────────────────────────────────────────────────────
        log(`取得完了: ${races.length} レース / 合計 ${races.reduce((s, r) => s + r.horses.length, 0)} 頭`);
        races.forEach(r => {
            const top = r.horses.find(h => h.chakujun === 1); // 1着馬を探す
            log(`  ${r.race}R: ${r.horses.length}頭 (1着 ${top ? `${top.num} ${top.name}` : '不明'})`);
        });

        // ─────────────────────────────────────────────────────
        // 【ブロック 14】JSON を stdout に出力
        //   Laravel 等の呼び出し元プロセスがこの出力を受け取って処理する
        // ─────────────────────────────────────────────────────
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races }, null, 2));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 15】致命的エラーハンドリング
        //   エラーが発生しても空の races を含む JSON を出力して
        //   呼び出し元がエラーを検知できるようにする
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}\n${err.stack}`);
        console.log(JSON.stringify({ yearmonth, kaisai: kaisaiFilter, races, error: err.message }));
        process.exitCode = 1; // 終了コード 1 でエラーを通知（finally は必ず実行される）
    } finally {
        // ─────────────────────────────────────────────────────
        // 【ブロック 16】後処理（必ず実行）
        //   成功・失敗に関わらずブラウザとロックファイルを解放する。
        //   これを finally に入れることでリソースリークを防ぐ。
        // ─────────────────────────────────────────────────────
        if (browser) await browser.close();
        if (existsSync(lockFile)) unlinkSync(lockFile); // ロック解除
        logStream.end(); // ログファイルを閉じる
    }
})();
