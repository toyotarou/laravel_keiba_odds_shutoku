/**
 * keibaOddsGetHorseDetail.mjs
 *
 * 使い方:
 *   node keibaOddsGetHorseDetail.mjs <CNAME>
 *   例: node keibaOddsGetHorseDetail.mjs pw01dud002020100209/2B
 *
 * 標準出力: JSON
 * 標準エラー: ログ
 */

import { createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ── ログ設定 ─────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url));
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetHorseDetail.log'), { flags: 'a' });

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ── 引数チェック ──────────────────────────────────────────────────
const cname = process.argv[2];
if (!cname) {
    log('ERROR: CNAME引数が必要です');
    log('使い方: node keibaOddsGetHorseDetail.mjs <CNAME>');
    process.exit(1);
}

// ── メイン ────────────────────────────────────────────────────────
(async () => {
    log('================================================================');
    log(`keibaOddsGetHorseDetail 開始 CNAME=${cname}`);
    log('================================================================');

    // ── Step 1: HTML取得 ─────────────────────────────────────────
    const url = `https://www.jra.go.jp/JRADB/accessU.html?CNAME=${encodeURIComponent(cname)}`;
    log(`[Step 1] URL: ${url}`);

    const res = await fetch(url, {
        headers: {
            // Shift_JISページなのでAcceptを明示
            'Accept': 'text/html,application/xhtml+xml',
            'User-Agent': 'Mozilla/5.0 (compatible; JRAOddsBot/1.0)',
        },
    });

    if (!res.ok) {
        log(`ERROR: HTTPエラー ${res.status}`);
        process.exit(1);
    }

    // Shift_JIS → UTF-8 変換
    const buffer = await res.arrayBuffer();
    const html = new TextDecoder('shift_jis').decode(buffer);
    log(`[Step 1] HTML取得完了 (${html.length} chars)`);

    // ── Step 2: パース ───────────────────────────────────────────
    // Node.js 18+ の組み込みHTMLパーサは無いため、正規表現でパース
    // （cheerioをインストールせずに済む軽量実装）

    // タグを除去するユーティリティ
    const stripTags = (s) => s.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();

    // ── 馬名 ────────────────────────────────────────────────────
    const horseNameMatch = html.match(/<span class="txt">(?:<span class="opt">[^<]*<\/span>)?([^<]+)<span class="name_en">([^<]+)<\/span>/);
    const horse_name    = horseNameMatch ? horseNameMatch[1].trim() : '';
    const horse_name_en = horseNameMatch ? horseNameMatch[2].trim() : '';
    log(`[Step 2] 馬名: ${horse_name} / ${horse_name_en}`);

    // ── プロフィール (dt/dd ペア) ────────────────────────────────
    const profile = {};
    const dtddRegex = /<dt>([^<]+)<\/dt>\s*<dd>([\s\S]*?)<\/dd>/g;
    let m;
    while ((m = dtddRegex.exec(html)) !== null) {
        const key = m[1].trim();
        const val = stripTags(m[2]).replace(/産駒/g, '').trim();
        profile[key] = val;
    }
    log(`[Step 2] プロフィール項目数: ${Object.keys(profile).length}`);

    // ── 賞金テーブル ─────────────────────────────────────────────
    // 賞金セクションは th/td の組み合わせ
    const prize = {};
    const prizeSection = html.match(/<tbody>([\s\S]*?)<\/tbody>/);
    // 賞金は専用のテーブルを探す（「総賞金」が含まれるもの）
    const prizeSectionMatch = html.match(/総賞金([\s\S]{0,2000}?)収得賞金（障害）/);
    if (prizeSectionMatch) {
        const prizeHtml = prizeSectionMatch[0];
        const tdValues = [...prizeHtml.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
            .map(x => stripTags(x[1]));
        const thValues = [...prizeHtml.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
            .map(x => stripTags(x[1]));
        // th と td をペアにする（賞金テーブル構造: th=ラベル, td=金額が交互）
        const labels = ['総賞金', '内付加賞', '内地方賞金', '内海外賞金', '収得賞金（平地）', '収得賞金（障害）'];
        const amountMatches = [...prizeHtml.matchAll(/[\d,]+円/g)];
        labels.forEach((label, i) => {
            prize[label] = amountMatches[i] ? amountMatches[i][0].replace(/,/g, '').replace('円', '') : '0';
        });
    }
    log(`[Step 2] 賞金: ${JSON.stringify(prize)}`);

    // ── 出走レース (tbody rows) ──────────────────────────────────
    const races = [];

    // 出走レーステーブルのtbodyを抽出
    // 「出走レース」セクション以降のtbodyを対象にする
    const raceSection = html.indexOf('出走レース');
    if (raceSection !== -1) {
        const racePart = html.slice(raceSection);

        // tbodyブロックを全部抽出
        const tbodyMatches = [...racePart.matchAll(/<tbody>([\s\S]*?)<\/tbody>/g)];

        for (const tbodyMatch of tbodyMatches) {
            const tbodyHtml = tbodyMatch[1];
            const rows = [...tbodyHtml.matchAll(/<tr>([\s\S]*?)<\/tr>/g)];

            for (const row of rows) {
                const tds = [...row[1].matchAll(/<td[^>]*class="([^"]*)"[^>]*>([\s\S]*?)<\/td>/g)];
                if (tds.length < 5) continue;

                // classベースで各列を取得
                const getByClass = (cls) => {
                    const found = tds.find(t => t[1].includes(cls));
                    return found ? stripTags(found[2]) : '';
                };

                // class指定なしのtdも含めて順番に取得
                const allTds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                    .map(t => stripTags(t[1]));

                if (allTds.length < 10) continue;

                // レース行の判定（年月日が含まれているか）
                if (!allTds[0].match(/\d{4}年\d+月\d+日/)) continue;

                races.push({
                    date:          allTds[0],  // 年月日
                    basho:         allTds[1],  // 場
                    race_name:     allTds[2],  // レース名
                    distance:      allTds[3],  // 距離
                    baba:          allTds[4],  // 馬場
                    num_horses:    allTds[5],  // 頭数
                    ninki:         allTds[6],  // 人気
                    chakujun:      allTds[7],  // 着順
                    jockey:        allTds[8],  // 騎手名
                    futan:         allTds[9],  // 負担重量
                    bataiju:       allTds[10] ?? '', // 馬体重
                    time:          allTds[11] ?? '', // タイム
                    rt:            allTds[12] ?? '', // Rt
                    chakuma:       allTds[13] ?? '', // 1着馬(2着馬)
                });
            }
        }
    }
    log(`[Step 2] 出走レース: ${races.length}件`);

    // ── 結果出力 ─────────────────────────────────────────────────
    const result = {
        cname,
        horse_name,
        horse_name_en,
        profile,
        prize,
        races,
    };

    log('================================================================');
    log(`完了: 馬名=${horse_name} プロフィール=${Object.keys(profile).length}項目 出走=${races.length}件`);
    log('================================================================');

    logStream.end();
    console.log(JSON.stringify(result, null, 2));

})().catch((err) => {
    log(`致命的エラー: ${err.message}`);
    logStream.end();
    process.exit(1);
});
