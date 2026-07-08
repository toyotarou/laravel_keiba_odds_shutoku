/**
 * keibaOddsGetHorseDetail.mjs
 *
 * 【概要】
 *   JRA公式の馬詳細ページ（accessU.html）から、
 *   指定した CNAME（馬固有ID）の馬の詳細情報を取得して JSON で標準出力する。
 *
 * 【取得項目】
 *   - 馬名・英字馬名
 *   - プロフィール（父・母・性別・毛色・生産牧場・馬主・調教師など）
 *   - 賞金（総賞金・付加賞・地方賞金・海外賞金・収得賞金）
 *   - 出走レース履歴（日付・場所・距離・着順・騎手・馬体重など）
 *   - レース条件別成績（平地/障害合計・コース別・人気別・距離別・馬場状態別）
 *
 * 【使い方】
 *   node keibaOddsGetHorseDetail.mjs <CNAME>
 *   例: node keibaOddsGetHorseDetail.mjs pw01dud002020100209/2B
 *
 * 【標準出力】 JSON
 * 【標準エラー】 ログ
 *
 * 【注意】
 *   JRAサイトは Shift_JIS で返るため、fetch 後に TextDecoder で UTF-8 変換する。
 *   Playwright は使用せず、Node.js の fetch API + 正規表現でパースする。
 */

// ─────────────────────────────────────────────────────────────
// 【ブロック 1】モジュールインポート
//   ログファイル書き出し用の fs.createWriteStream と
//   ESModules で __dirname を再現するためのユーティリティのみ。
//   ブラウザを使わず fetch でHTMLを取得するため playwright は不要。
// ─────────────────────────────────────────────────────────────
import { createWriteStream } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// ─────────────────────────────────────────────────────────────
// 【ブロック 2】ログ設定
//   ログを .log ファイルと stderr の両方に出力する。
//   stdout は JSON 専用にするためログは stderr へ書く。
//   logStream の error イベントを無視するのは、
//   ディスク容量不足などでログが書けなくても処理を止めたくないから。
// ─────────────────────────────────────────────────────────────
const __dirname = dirname(fileURLToPath(import.meta.url)); // ESModules での __dirname 相当
const logStream = createWriteStream(join(__dirname, 'keibaOddsGetHorseDetail.log'), { flags: 'a' }); // 追記モード
logStream.on('error', () => {}); // ログ書き込みエラーは握り潰してメイン処理を継続

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 3】コマンドライン引数チェック
//   CNAME は JRA が馬ごとに付与する固有コード（URL クエリパラメータとして使用）
//   例: "pw01dud002020100209/2B"（スラッシュを含む場合がある）
// ─────────────────────────────────────────────────────────────
const cname = process.argv[2]; // 第1引数を CNAME として受け取る
if (!cname) {
    log('ERROR: CNAME引数が必要です');
    process.exit(1);
}

// ─────────────────────────────────────────────────────────────
// 【ブロック 4】マッピング定義群
//   JRAページの日本語キーを英語キーへ変換するための定数マップ。
//   英語キーにすることでDBカラム名と一致させやすくなる。
// ─────────────────────────────────────────────────────────────

// プロフィール dl > dt テキスト → 英語フィールド名
const PROFILE_KEY_MAP = {
    '父': 'father', '母': 'mother', '母の父': 'maternal_sire', '母の母': 'maternal_dam',
    '性別': 'sex', '馬齢': 'age', '毛色': 'coat_color', '生年月日': 'birth_date',
    '生産牧場': 'breeder', '産地': 'origin', '馬主名': 'owner', '調教師名': 'trainer',
    '馬名意味': 'name_meaning', '取引市場': 'market',
};

// プロフィールから除外するキー
// 凡例・略称・賞金情報は prize オブジェクトに別出しするため除外
const PROFILE_SKIP_KEYS = new Set([
    '総賞金', '内付加賞', '内地方賞金', '内海外賞金', '収得賞金（平地）', '収得賞金（障害）',
    // 以下は凡例（馬場・コース・国の略称）
    'S', 'C', 'T', 'H', 'F', '芝', 'ダ',
    '愛', '米', '英', '豪', '華', 'サウ', '新嘉', 'ア首', '巴', '仏', '韓', '香',
]);

// 成績テーブルの列ヘッダ → 英語フィールド名
const STAT_COL_MAP = {
    '1着': 'first', '2着': 'second', '3着': 'third',
    '4着以下': 'fourth_or_lower', '4着 以下': 'fourth_or_lower', // 改行で分かれるパターンを両方カバー
    '出走回数': 'starts', '出走 回数': 'starts',
    '勝率': 'win_rate', '連対率': 'place_rate',
    '3着内率': 'show_rate', '3着 内率': 'show_rate',
};

// コース別成績の行ラベル → 英語フィールド名
const COURSE_LABEL_MAP = {
    '芝・右': 'turf_right', '芝・左': 'turf_left',
    'ダート・右': 'dirt_right', 'ダート・左': 'dirt_left',
    '芝・直': 'turf_straight', 'ダート・直': 'dirt_straight',
    '障害': 'hurdle',
    '芝(地方)': 'turf_local', 'ダート(地方)': 'dirt_local',
    '芝(海外)': 'turf_overseas', 'ダート(海外)': 'dirt_overseas',
};

// 馬場状態別成績の行ラベル → 英語フィールド名
const CONDITION_LABEL_MAP = {
    '芝・良': 'turf_firm', '芝・稍重': 'turf_yielding', '芝・重': 'turf_soft', '芝・不良': 'turf_heavy',
    'ダート・良': 'dirt_firm', 'ダート・稍重': 'dirt_yielding', 'ダート・重': 'dirt_soft', 'ダート・不良': 'dirt_heavy',
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 5】ユーティリティ関数群
//   stripTags()              : HTMLタグを除去して空白を正規化する（正規表現ベースの簡易パーサー）
//   makeStatRow()            : 成績テーブルの1行を {英語キー: 値} オブジェクトに変換する
//   normalizeDistanceLabel() : 距離別ラベル（"芝1200〜1400m"等）を英語キーに変換する
//   normalizePopLabel()      : 人気別ラベル（"1番人気"等）を英語キーに変換する
// ─────────────────────────────────────────────────────────────

// HTMLタグを除去し、連続する空白を単一スペースに潰してトリムする
const stripTags = (s) => s.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();

// 成績テーブルの1行データを英語キーオブジェクトに変換する
// statHeaders: テーブルの th テキスト配列, values: 対応する td テキスト配列
const makeStatRow = (statHeaders, values) => {
    const obj = {};
    statHeaders.forEach((h, i) => {
        const key = STAT_COL_MAP[h] || h; // マップにあれば英語キー、なければ日本語のまま
        obj[key] = values[i] !== undefined ? values[i] : '';
    });
    return obj;
};

// "芝1200〜1400m" → "turf_1200_1400", "ダ1600m以上" → "dirt_1600_plus" など
const normalizeDistanceLabel = (label) => {
    const prefix = label.startsWith('芝') ? 'turf' : 'dirt'; // コース種別を判定
    const nums = label.match(/\d+/g); // ラベル内の数字をすべて抽出
    if (!nums) return label; // 数字が無い場合はそのまま返す
    if (label.includes('以上')) return `${prefix}_${nums[0]}_plus`; // "〇〇m以上" の場合
    if (nums.length >= 2) return `${prefix}_${nums[0]}_${nums[1]}`; // "〇〇〜△△m" の場合
    return `${prefix}_${nums[0]}`; // 単一距離の場合
};

// "1番人気" → "pop_1", "5番人気以下" → "pop_5_or_lower" など
const normalizePopLabel = (label) => {
    const num = label.match(/\d+/)?.[0]; // 数字部分を抽出
    if (!num) return label;
    return label.includes('以下') ? `pop_${num}_or_lower` : `pop_${num}`;
};

// ─────────────────────────────────────────────────────────────
// 【ブロック 6】メイン処理（即時実行非同期関数）
// ─────────────────────────────────────────────────────────────
(async () => {
    log('================================================================');
    log(`keibaOddsGetHorseDetail 開始 CNAME=${cname}`);
    log('================================================================');

    // ─────────────────────────────────────────────────────────
    // 【ブロック 7】HTMLの取得（Step 1）
    //   accessU.html は Shift_JIS で返るため ArrayBuffer で受け取り、
    //   TextDecoder で UTF-8 に変換してから文字列として扱う。
    //   CNAME に '/' が含まれる場合、encodeURIComponent は '%2F' に変換するが、
    //   JRAのサーバーは '/'' のまま渡す必要があるため元に戻す。
    // ─────────────────────────────────────────────────────────
    const url = `https://www.jra.go.jp/JRADB/accessU.html?CNAME=${encodeURIComponent(cname).replace(/%2F/gi, '/')}`;
    log(`[Step 1] URL: ${url}`);

    const res = await fetch(url, {
        headers: {
            'Accept': 'text/html,application/xhtml+xml',
            'User-Agent': 'Mozilla/5.0 (compatible; JRAOddsBot/1.0)',
        },
    });

    if (!res.ok) {
        log(`ERROR: HTTPエラー ${res.status}`);
        process.exit(1);
    }

    // Shift_JIS → UTF-8 変換（JRAサイトは Shift_JIS を使用）
    const buffer = await res.arrayBuffer();
    const html = new TextDecoder('shift_jis').decode(buffer);
    log(`[Step 1] HTML取得完了 (${html.length} chars)`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8】HTMLパース（Step 2）
    //   Playwright ではなく正規表現で直接パースする。
    //   理由: ページが静的HTMLで JavaScript レンダリングが不要なため。
    // ─────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8-1】馬名・英字馬名の抽出
    //   <span class="txt"> 内に日本語名と英字名が入っている。
    //   英字名は <span class="name_en"> で囲まれている。
    // ─────────────────────────────────────────────────────────
    const horseNameMatch = html.match(/<span class="txt">(?:<span class="opt">[^<]*<\/span>)?([^<]+)<span class="name_en">([^<]+)<\/span>/);
    const horse_name    = horseNameMatch ? horseNameMatch[1].trim() : '';
    const horse_name_en = horseNameMatch ? horseNameMatch[2].trim() : '';
    log(`[Step 2] 馬名: ${horse_name} / ${horse_name_en}`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8-2】プロフィールの抽出（dl > dt/dd 構造）
    //   PROFILE_SKIP_KEYS に含まれるキーは除外し、
    //   PROFILE_KEY_MAP で英語キーに変換してオブジェクトに格納する。
    //   dd 内に「産駒」という文字が混入することがあるため除去する。
    // ─────────────────────────────────────────────────────────
    const profile = {};
    const dtddRegex = /<dt>([^<]+)<\/dt>\s*<dd>([\s\S]*?)<\/dd>/g; // dt と dd のペアを取得
    let m;
    while ((m = dtddRegex.exec(html)) !== null) {
        const jaKey = m[1].trim();
        if (PROFILE_SKIP_KEYS.has(jaKey)) continue; // 凡例・賞金等はスキップ
        const enKey = PROFILE_KEY_MAP[jaKey] || jaKey; // 英語キーに変換（未定義なら日本語のまま）
        const val = stripTags(m[2]).replace(/産駒/g, '').trim(); // タグ除去 + "産駒" 文字を削除
        profile[enKey] = val;
    }
    log(`[Step 2] プロフィール項目数: ${Object.keys(profile).length}`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8-3】賞金の抽出
    //   「総賞金」〜「収得賞金（障害）」の区間を正規表現で切り出し、
    //   "円" の前にある金額（数字+カンマ）を順番に取り出す。
    //   金額の出現順が enLabels の定義順と一致することを前提としている。
    // ─────────────────────────────────────────────────────────
    const prize = {};
    const prizeSectionMatch = html.match(/総賞金([\s\S]{0,2000}?)収得賞金（障害）/);
    if (prizeSectionMatch) {
        const prizeHtml = prizeSectionMatch[0];
        const enLabels = [
            'total_prize',          // 総賞金
            'bonus_prize',          // 内付加賞
            'local_prize',          // 内地方賞金
            'overseas_prize',       // 内海外賞金
            'flat_earned_prize',    // 収得賞金（平地）
            'hurdle_earned_prize',  // 収得賞金（障害）
        ];
        // "円" の直前にある数字+カンマのパターンを全て取り出す
        const amounts = [...prizeHtml.matchAll(/[\d,]+(?=(?:<span>)?円)/g)];
        enLabels.forEach((key, i) => {
            prize[key] = amounts[i] ? amounts[i][0].replace(/,/g, '') : '0'; // カンマを除いた数字文字列
        });
    }
    log(`[Step 2] 賞金: ${JSON.stringify(prize)}`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8-4】出走レース履歴の抽出
    //   「出走レース」セクション以降の tbody 内の各 tr を解析する。
    //   日付列が "YYYY年M月D日" 形式の行のみをレースデータとして採用する。
    //   列順: [0]日付 [1]場所 [2]レース名 [3]距離 [4]馬場 [5]出走頭数
    //         [6]人気 [7]着順 [8]騎手 [9]負担重量 [10]馬体重 [11]タイム
    //         [12]上がり3F [13]差し馬 ...
    // ─────────────────────────────────────────────────────────
    const races = [];
    const raceSection = html.indexOf('出走レース'); // セクション開始位置を探す
    if (raceSection !== -1) {
        const racePart = html.slice(raceSection); // 出走レース以降のHTMLを切り出す
        const tbodyMatches = [...racePart.matchAll(/<tbody>([\s\S]*?)<\/tbody>/g)];
        for (const tbodyMatch of tbodyMatches) {
            const rows = [...tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)];
            for (const row of rows) {
                const allTds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                    .map(t => stripTags(t[1])); // 全セルのテキストを取得
                if (allTds.length < 10) continue; // 必要列数に満たない行はスキップ
                if (!allTds[0].match(/\d{4}年\d+月\d+日/)) continue; // 日付形式でない行はスキップ
                races.push({
                    date:       allTds[0],  // 日付
                    basho:      allTds[1],  // 開催場所
                    race_name:  allTds[2],  // レース名
                    distance:   allTds[3],  // 距離・コース種別
                    baba:       allTds[4],  // 馬場状態
                    num_horses: allTds[5],  // 出走頭数
                    ninki:      allTds[6],  // 人気
                    chakujun:   allTds[7],  // 着順
                    jockey:     allTds[8],  // 騎手
                    futan:      allTds[9],  // 負担重量（斤量）
                    bataiju:    allTds[10] ?? '', // 馬体重
                    time:       allTds[11] ?? '', // タイム
                    rt:         allTds[12] ?? '', // 上がり3F
                    chakuma:    allTds[13] ?? '', // 差し馬（着差）
                });
            }
        }
    }
    log(`[Step 2] 出走レース: ${races.length}件`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 8-5】レース条件別成績の抽出
    //   「result_unit」セクション以降の各テーブルをキャプション名で分類する。
    //   テーブルの caption に相当するテキストは <div class="main"> 内にある。
    //
    //   対応するテーブル:
    //     平地レース合計 / 障害レース合計 → stats.flat_total / stats.hurdle_total
    //     コース別成績              → stats.by_course
    //     人気順成績                → stats.by_popularity
    //     距離別成績                → stats.by_distance
    //     馬場状態別成績            → stats.by_track_condition
    // ─────────────────────────────────────────────────────────
    const stats = {};
    const resultUnitStart = html.indexOf('<li id="result_unit">'); // 成績セクション開始位置
    if (resultUnitStart !== -1) {
        const resultPart = html.slice(resultUnitStart);
        const tableMatches = [...resultPart.matchAll(/<table[^>]*>([\s\S]*?)<\/table>/g)];

        for (const tableMatch of tableMatches) {
            const tableHtml = tableMatch[1];

            // テーブルのキャプション（どの成績テーブルかを識別するラベル）を取得
            const captionMatch = tableHtml.match(/<div class="main">([^<]+)<\/div>/);
            if (!captionMatch) continue;
            const caption = captionMatch[1].trim();

            // テーブルヘッダ（列名）を取得
            const theadMatch = tableHtml.match(/<thead>([\s\S]*?)<\/thead>/);
            if (!theadMatch) continue;
            const allHeaders = [...theadMatch[1].matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
                .map(h => stripTags(h[1]));

            // テーブルボディを取得
            const tbodyMatch = tableHtml.match(/<tbody>([\s\S]*?)<\/tbody>/);
            if (!tbodyMatch) continue;
            const rowMatches = [...tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)];

            if (caption === '平地レース合計' || caption === '障害レース合計') {
                // 合計成績: 1行のみ → 直接オブジェクト化
                const enKey = caption === '平地レース合計' ? 'flat_total' : 'hurdle_total';
                if (rowMatches.length > 0) {
                    const tds = [...rowMatches[0][1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats[enKey] = makeStatRow(allHeaders, tds);
                }

            } else if (caption === 'コース別成績') {
                // コース別: 行ヘッダ(th)がコース名 → COURSE_LABEL_MAP で英語化
                const statHeaders = allHeaders.slice(1); // 先頭はコース名ラベル列なので除外
                stats.by_course = {};
                for (const row of rowMatches) {
                    const th = row[1].match(/<th[^>]*scope="row"[^>]*>([\s\S]*?)<\/th>/);
                    if (!th) continue;
                    const enLabel = COURSE_LABEL_MAP[stripTags(th[1])] || stripTags(th[1]);
                    const tds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats.by_course[enLabel] = makeStatRow(statHeaders, tds);
                }

            } else if (caption === '人気順成績') {
                // 人気別: normalizePopLabel で "pop_1" 等の英語キーに変換
                const statHeaders = allHeaders.slice(1);
                stats.by_popularity = {};
                for (const row of rowMatches) {
                    const th = row[1].match(/<th[^>]*scope="row"[^>]*>([\s\S]*?)<\/th>/);
                    if (!th) continue;
                    const enLabel = normalizePopLabel(stripTags(th[1]));
                    const tds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats.by_popularity[enLabel] = makeStatRow(statHeaders, tds);
                }

            } else if (caption === '距離別成績') {
                // 距離別: normalizeDistanceLabel で "turf_1200_1400" 等の英語キーに変換
                const statHeaders = allHeaders.slice(1);
                stats.by_distance = {};
                for (const row of rowMatches) {
                    const th = row[1].match(/<th[^>]*scope="row"[^>]*>([\s\S]*?)<\/th>/);
                    if (!th) continue;
                    const enLabel = normalizeDistanceLabel(stripTags(th[1]));
                    const tds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats.by_distance[enLabel] = makeStatRow(statHeaders, tds);
                }

            } else if (caption === '馬場状態別成績') {
                // 馬場状態別: CONDITION_LABEL_MAP で "turf_firm" 等の英語キーに変換
                const statHeaders = allHeaders.slice(1);
                stats.by_track_condition = {};
                for (const row of rowMatches) {
                    const th = row[1].match(/<th[^>]*scope="row"[^>]*>([\s\S]*?)<\/th>/);
                    if (!th) continue;
                    const enLabel = CONDITION_LABEL_MAP[stripTags(th[1])] || stripTags(th[1]);
                    const tds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats.by_track_condition[enLabel] = makeStatRow(statHeaders, tds);
                }
            }
        }
    }
    log(`[Step 2] レース条件別成績: ${Object.keys(stats).length}セクション`);

    // ─────────────────────────────────────────────────────────
    // 【ブロック 9】結果の JSON 出力
    //   全データをひとつのオブジェクトにまとめて stdout に出力する
    // ─────────────────────────────────────────────────────────
    const result = { cname, horse_name, horse_name_en, profile, prize, races, stats };

    log('================================================================');
    log(`完了: 馬名=${horse_name} 出走=${races.length}件 統計=${Object.keys(stats).length}セクション`);
    log('================================================================');

    logStream.end(); // ログファイルを閉じてから stdout に出力
    console.log(JSON.stringify(result, null, 2));

// ─────────────────────────────────────────────────────────────
// 【ブロック 10】致命的エラーハンドリング
//   非同期処理内でスローされた例外をここでキャッチする。
//   ログを閉じてから終了コード 1 で終了する。
// ─────────────────────────────────────────────────────────────
})().catch((err) => {
    log(`致命的エラー: ${err.message}`);
    logStream.end();
    process.exit(1);
});
