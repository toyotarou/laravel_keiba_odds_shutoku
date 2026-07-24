/**
 * keibaOddsGetTanpuku.mjs
 *
 * 【概要】
 *   JRA公式サイトの「オッズ」→「単勝・複勝」ページから
 *   指定レースの全馬の単勝・複勝オッズを取得する。
 *
 * ════════════════════════════════════════════════════════════════
 * 【ブラウザで追える進行順路】
 *   ※ 実際に Chrome で同じ操作をすると、スクリプトの動きを確認できる
 *   ※ このスクリプトは「1レース分だけ」取得する。ループなし。
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
 *     「1回東京5日」「2回阪神3日」のような開催リンクが並ぶ。
 *
 * ▼ STEP C ── 指定の開催リンクをクリック → レース一覧へ
 *   操作:
 *     引数 kaisuu・basho・day から組み立てた開催名のリンクをクリック
 *     ・day あり → "kaisuu回bashoNameday日" で完全一致検索（例: "2回東京3日"）
 *     ・day なし → "kaisuu回bashoName" で部分一致検索（例: "2回東京"）
 *     ※ 土日で同じ開催が2日ある場合は day を指定しないと先にヒットした方が選ばれる
 *   変化:
 *     その開催のレース一覧ページへ遷移する。
 *     tbody の各 tr が 1 レースに対応した表が表示される。
 *
 * ▼ STEP D ── 指定レース番号の「単複」リンクをクリック → 単複オッズページへ
 *   操作:
 *     引数 raceNum（例: "1"）と一致するレース行の「単複」リンク（div.tanpuku a）をクリック
 *   リンク特定の優先順位:
 *     1. リンクの onclick / href 属性からレース番号を抽出して一致確認
 *        パターン1: "/N/" 形式（例: "/11/"）
 *        パターン2: カンマ・クォートで囲まれた数字（例: ",'11',"）
 *     2. フォールバック: 行インデックス（raceNum-1 番目の行）を直接使用
 *     ※ 11R・12R だけ表示されている場面でも正しいリンクを特定できる
 *   変化:
 *     単複オッズページへ遷移する。table.tanpuku が表示される。
 *
 * ▼ STEP E ── table.tanpuku から全馬のオッズを取得（操作なし）
 *   操作: なし（JavaScriptでDOMを読み取るだけ）
 *   対象要素:
 *     table.tanpuku > tbody > tr（1行＝1頭）
 *       td.num              ← 馬番
 *       td.odds_tan         ← 単勝オッズ（例: "3.5"）
 *       td.odds_fuku span.min ← 複勝オッズ下限（例: "1.2"）
 *       td.odds_fuku span.max ← 複勝オッズ上限（例: "2.1"）
 *   ※ 馬番と単勝オッズが両方取れた行のみ有効データとして収集する
 *
 * ▼ 終了 ── JSON を stdout に出力
 *   [ { num, tan, fuku_min, fuku_max }, ... ]
 *   ※ ループなし・1レース分のみ
 *
 * ════════════════════════════════════════════════════════════════
 *
 * 【引数】
 *   $1: date    - 日付（例: "2026-05-17"）
 *   $2: kaisuu  - 開催回数（例: "2"）
 *   $3: basho   - 場所コード（例: "05" = 東京）
 *   $4: raceNum - レース番号（例: "1"）
 *   $5: day     - 開催日次（例: "3"、省略可）
 *
 * 【使い方】
 *   node keibaOddsGetTanpuku.mjs 2026-05-17 2 05 1
 *   node keibaOddsGetTanpuku.mjs 2026-05-17 2 05 1 3  （日次指定）
 *
 * 【標準出力】
 *   [ { num: "1", tan: "3.5", fuku_min: "1.2", fuku_max: "2.1" }, ... ]
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
// ─────────────────────────────────────────────────────────────
import { chromium } from 'playwright';
import { createWriteStream, existsSync, writeFileSync, unlinkSync, readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】コマンドライン引数のパース
//   デフォルト値は開発・テスト用（実運用時は全引数を渡す）。
//   day は省略可能で、省略時は「kaisuu回bashoName」で部分一致検索し、
//   指定時は「kaisuu回bashoNameday日」で完全一致検索する。
//   ※ day 指定により土日の同一開催を区別できる
// ─────────────────────────────────────────────────────────────
const args    = process.argv.slice(2);
const date    = args[0] ?? '2026-05-17';  // 日付（YYYY-MM-DD 形式）
const kaisuu  = args[1] ?? '2';           // 開催回数
const basho   = args[2] ?? '05';          // 場所コード（2桁）
const raceNum = args[3] ?? '1';           // レース番号（文字列のまま扱う）
const day     = args[4] ?? null;          // 開催日次（null = 省略）

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】場所コード → 漢字名変換
//   JRAページ上のリンクテキストは漢字名なので、
//   引数の場所コードを漢字名に変換してからリンクを探す。
// ─────────────────────────────────────────────────────────────
const kaisaiMap = {
    '01': '札幌', '02': '函館', '03': '福島', '04': '新潟',
    '05': '東京', '06': '中山', '07': '中京', '08': '京都',
    '09': '阪神', '10': '小倉'
};
const bashoName = kaisaiMap[basho] ?? basho; // 未定義の場合はコードをそのまま使用

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】ログ設定
//   追記モード（flags: 'a'）で毎回ログを蓄積する。
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetTanpuku.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】ロックファイルの設定
//   date + kaisuu + basho + raceNum の組み合わせを鍵にする。
//   これにより、異なるレースの同時実行は許可しつつ、
//   同一レースの重複起動のみを防ぐ。
// ─────────────────────────────────────────────────────────────
const lockKey  = `${date}_${kaisuu}_${basho}_${raceNum}`;
const lockFile = join(__dirname, `keibaOddsGetTanpuku_${lockKey}.lock`);

// PID が現在も生きているかを確認する（スタールロック対策）
function isProcessAlive(pid) {
    try { process.kill(pid, 0); return true; } catch { return false; }
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 6】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    // ─────────────────────────────────────────────────────────
    // 【ブロック 7】二重起動防止（スタールロック考慮）
    // ─────────────────────────────────────────────────────────
    if (existsSync(lockFile)) {
        const storedPid = parseInt(readFileSync(lockFile, 'utf8'), 10);
        if (!isNaN(storedPid) && isProcessAlive(storedPid)) {
            log(`[LOCK] 既に同じ引数で起動中のため終了します: ${lockKey} (PID=${storedPid})`);
            console.log(JSON.stringify([]));
            logStream.end();
            process.exit(0);
        }
        // スタールロックを削除して続行
        log(`[LOCK] 古いロックファイルを削除します (PID=${storedPid} は存在しない)`);
        unlinkSync(lockFile);
    }
    writeFileSync(lockFile, String(process.pid));
    log(`[LOCK] ロックファイル作成: ${lockFile}`);

    let browser = null;

    try {
        log('================================================================');
        log('keibaOddsGetTanpuku 開始');
        log(`  date   : ${date}`);
        log(`  kaisuu : ${kaisuu}回`);
        log(`  basho  : ${basho} (${bashoName})`);
        log(`  race   : ${raceNum}R`);
        log(`  day    : ${day ?? '(未指定)'}日目`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 8】Step 1: ブラウザ起動
        // ─────────────────────────────────────────────────────
        log('[Step 1] ブラウザ（Playwright / Chromium）を起動中...');
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
        });
        const page = await browser.newPage();
        await page.setViewportSize({ width: 1280, height: 800 });
        log('[Step 1] ブラウザ起動完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 9】Step 2: JRAトップページへアクセス
        // ─────────────────────────────────────────────────────
        log('[Step 2] JRA トップページにアクセス中...');
        await page.goto('https://www.jra.go.jp/', { waitUntil: 'networkidle', timeout: 60000 });
        log('[Step 2] JRA トップページ ロード完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 10】Step 3: 「オッズ」リンクをクリック
        //   → 開催選択ページへ遷移
        // ─────────────────────────────────────────────────────
        log('[Step 3] 「オッズ」リンクをクリック中...');
        await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('a'));
            const target = links.find(a => a.textContent.trim() === 'オッズ');
            if (target) target.click();
        });
        await page.waitForSelector('a', { timeout: 15000 }).catch(() => {});
        await sleep(2000);
        log('[Step 3] オッズ開催選択ページへ遷移完了。');

        // ─────────────────────────────────────────────────────
        // 【ブロック 11】Step 4: 指定の開催リンクをクリック
        //   → レース一覧ページへ遷移
        //   day が渡されている場合: "kaisuu回bashoNameday日" で完全一致
        //   day が省略の場合      : "kaisuu回bashoName" で部分一致
        //   ※ 土日で同じ開催が2日あるとき day がないと最初にヒットした方が選ばれる
        // ─────────────────────────────────────────────────────
        const step4Label = day ? `${kaisuu}回${bashoName}${day}日` : `${kaisuu}回${bashoName}`;
        log(`[Step 4] 開催「${step4Label}」のリンクを探してクリック中...`);
        const clicked4 = await page.evaluate(({ kaisuu, bashoName, day }) => {
            const links = Array.from(document.querySelectorAll('a'));
            const searchText = day ? `${kaisuu}回${bashoName}${day}日` : `${kaisuu}回${bashoName}`;
            const target = links.find(a => a.textContent.includes(searchText));
            if (target) { target.click(); return true; }
            return false;
        }, { kaisuu, bashoName, day });

        if (!clicked4) {
            log(`[Step 4] WARNING: 「${step4Label}」のリンクが見つかりませんでした。`);
            console.log(JSON.stringify([]));
            return;
        }
        await page.waitForSelector('tbody tr', { timeout: 15000 }).catch(() => {});
        await sleep(1000);
        log(`[Step 4] ${step4Label} レース一覧ページへ遷移完了。`);

        // ─────────────────────────────────────────────────────
        // 【ブロック 12】Step 5: 指定レース番号の「単複」リンクをクリック
        //   → 単複オッズページへ遷移
        //
        //   リンク検索の優先順位:
        //     1. div.tanpuku a の onclick/href 属性からレース番号を抽出して一致確認
        //        - パターン1: "/0*N/" 形式（例: "/11/"）
        //        - パターン2: カンマ・クォート囲みの数字
        //     2. 行インデックス（raceNum-1 番目の行）でフォールバック
        //   これにより、11R・12R だけ表示されている場面でも正しいリンクを特定できる。
        // ─────────────────────────────────────────────────────
        log(`[Step 5] ${raceNum}R の単複リンクをクリック中...`);
        const clicked5 = await page.evaluate(({ targetRace }) => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            for (const row of rows) {
                const link = row.querySelector('div.tanpuku a');
                if (!link) continue;
                const attr = link.getAttribute('onclick') ?? link.getAttribute('href') ?? '';
                const patterns = [
                    new RegExp(`\\/0*${targetRace}\\/`),           // パターン1: "/N/" 形式
                    new RegExp(`[,']\\s*0*${targetRace}\\s*[,'"]`), // パターン2: クォート囲み
                ];
                if (patterns.some(p => p.test(attr))) {
                    link.click();
                    return { ok: true, method: 'attr' }; // 属性マッチで成功
                }
            }
            // フォールバック: 行インデックスによるアクセス
            const row = rows[parseInt(targetRace) - 1];
            if (row) {
                const target = row.querySelector('div.tanpuku a');
                if (target) { target.click(); return { ok: true, method: 'index' }; }
            }
            return { ok: false, method: 'none' };
        }, { targetRace: parseInt(raceNum) });

        log(`[Step 5] クリック結果: ok=${clicked5.ok} method=${clicked5.method}`);
        if (!clicked5.ok) {
            log(`[Step 5] WARNING: ${raceNum}R の単複リンクが見つかりませんでした。`);
            console.log(JSON.stringify([]));
            return;
        }
        // table.tanpuku が出るまで最大15秒待つ
        await page.waitForSelector('table.tanpuku', { timeout: 15000 }).catch(() => {});
        await sleep(500);
        log(`[Step 5] ${step4Label} ${raceNum}R 単複オッズページへ遷移完了。`);

        // ─────────────────────────────────────────────────────
        // 【ブロック 13】Step 6: オッズテーブルのスクレイピング
        //   table.tanpuku の列構成:
        //     td.num        : 馬番
        //     td.odds_tan   : 単勝オッズ
        //     td.odds_fuku  : 複勝オッズ
        //       span.min    : 複勝下限
        //       span.max    : 複勝上限
        //   num と tan が両方あれば有効なデータとして収集する。
        // ─────────────────────────────────────────────────────
        log('[Step 6] オッズテーブルをスクレイピング中...');
        const odds = await page.evaluate(() => {
            const table = document.querySelector('table.tanpuku');
            if (!table) return []; // テーブルが存在しない場合は空配列を返す
            const result = [];
            table.querySelectorAll('tbody tr').forEach(row => {
                const num     = row.querySelector('td.num')?.textContent.trim()                ?? '';
                const tan     = row.querySelector('td.odds_tan')?.textContent.trim()           ?? '';
                const fukuMin = row.querySelector('td.odds_fuku span.min')?.textContent.trim() ?? '';
                const fukuMax = row.querySelector('td.odds_fuku span.max')?.textContent.trim() ?? '';
                // 馬番と単勝オッズが揃っている行のみ有効データとして追加
                if (num && tan) {
                    result.push({ num, tan, fuku_min: fukuMin, fuku_max: fukuMax });
                }
            });
            return result;
        });

        log(`[Step 6] スクレイピング完了 ── ${odds.length} 頭分のオッズを取得しました。`);

        log('================================================================');
        log('keibaOddsGetTanpuku 完了');
        log(`  ${step4Label} ${raceNum}R  取得頭数: ${odds.length}`);
        log('================================================================');

        // ─────────────────────────────────────────────────────
        // 【ブロック 14】JSON 出力
        // ─────────────────────────────────────────────────────
        console.log(JSON.stringify(odds));

    } catch (err) {
        // ─────────────────────────────────────────────────────
        // 【ブロック 15】致命的エラーハンドリング
        // ─────────────────────────────────────────────────────
        log(`致命的エラー: ${err.message}`);
        log(err.stack ?? '');
        console.log(JSON.stringify([]));
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
