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
logStream.on('error', () => {});

const log = (msg) => {
    const line = `[${new Date().toLocaleString('ja-JP')}] ${msg}\n`;
    logStream.write(line);
    process.stderr.write(line);
};

// ── 引数チェック ──────────────────────────────────────────────────
const cname = process.argv[2];
if (!cname) {
    log('ERROR: CNAME引数が必要です');
    process.exit(1);
}

// ── マッピング定義 ────────────────────────────────────────────────

const PROFILE_KEY_MAP = {
    '父': 'father', '母': 'mother', '母の父': 'maternal_sire', '母の母': 'maternal_dam',
    '性別': 'sex', '馬齢': 'age', '毛色': 'coat_color', '生年月日': 'birth_date',
    '生産牧場': 'breeder', '産地': 'origin', '馬主名': 'owner', '調教師名': 'trainer',
    '馬名意味': 'name_meaning', '取引市場': 'market',
};

// 除外キー（凡例・略称・賞金はprizeに別出し）
const PROFILE_SKIP_KEYS = new Set([
    '総賞金', '内付加賞', '内地方賞金', '内海外賞金', '収得賞金（平地）', '収得賞金（障害）',
    'S', 'C', 'T', 'H', 'F', '芝', 'ダ',
    '愛', '米', '英', '豪', '華', 'サウ', '新嘉', 'ア首', '巴', '仏', '韓', '香',
]);

const STAT_COL_MAP = {
    '1着': 'first', '2着': 'second', '3着': 'third',
    '4着以下': 'fourth_or_lower', '4着 以下': 'fourth_or_lower',
    '出走回数': 'starts', '出走 回数': 'starts',
    '勝率': 'win_rate', '連対率': 'place_rate',
    '3着内率': 'show_rate', '3着 内率': 'show_rate',
};

const COURSE_LABEL_MAP = {
    '芝・右': 'turf_right', '芝・左': 'turf_left',
    'ダート・右': 'dirt_right', 'ダート・左': 'dirt_left',
    '芝・直': 'turf_straight', 'ダート・直': 'dirt_straight',
    '障害': 'hurdle',
    '芝(地方)': 'turf_local', 'ダート(地方)': 'dirt_local',
    '芝(海外)': 'turf_overseas', 'ダート(海外)': 'dirt_overseas',
};

const CONDITION_LABEL_MAP = {
    '芝・良': 'turf_firm', '芝・稍重': 'turf_yielding', '芝・重': 'turf_soft', '芝・不良': 'turf_heavy',
    'ダート・良': 'dirt_firm', 'ダート・稍重': 'dirt_yielding', 'ダート・重': 'dirt_soft', 'ダート・不良': 'dirt_heavy',
};

// ── ユーティリティ ────────────────────────────────────────────────

const stripTags = (s) => s.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();

const makeStatRow = (statHeaders, values) => {
    const obj = {};
    statHeaders.forEach((h, i) => {
        const key = STAT_COL_MAP[h] || h;
        obj[key] = values[i] !== undefined ? values[i] : '';
    });
    return obj;
};

const normalizeDistanceLabel = (label) => {
    const prefix = label.startsWith('芝') ? 'turf' : 'dirt';
    const nums = label.match(/\d+/g);
    if (!nums) return label;
    if (label.includes('以上')) return `${prefix}_${nums[0]}_plus`;
    if (nums.length >= 2) return `${prefix}_${nums[0]}_${nums[1]}`;
    return `${prefix}_${nums[0]}`;
};

const normalizePopLabel = (label) => {
    const num = label.match(/\d+/)?.[0];
    if (!num) return label;
    return label.includes('以下') ? `pop_${num}_or_lower` : `pop_${num}`;
};

// ── メイン ────────────────────────────────────────────────────────
(async () => {
    log('================================================================');
    log(`keibaOddsGetHorseDetail 開始 CNAME=${cname}`);
    log('================================================================');

    // ── Step 1: HTML取得 ─────────────────────────────────────────
    // encodeURIComponent は '/' を '%2F' にするため、スラッシュは元に戻す
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

    // Shift_JIS → UTF-8 変換
    const buffer = await res.arrayBuffer();
    const html = new TextDecoder('shift_jis').decode(buffer);
    log(`[Step 1] HTML取得完了 (${html.length} chars)`);

    // ── Step 2: パース ───────────────────────────────────────────

    // ── 馬名 ────────────────────────────────────────────────────
    const horseNameMatch = html.match(/<span class="txt">(?:<span class="opt">[^<]*<\/span>)?([^<]+)<span class="name_en">([^<]+)<\/span>/);
    const horse_name    = horseNameMatch ? horseNameMatch[1].trim() : '';
    const horse_name_en = horseNameMatch ? horseNameMatch[2].trim() : '';
    log(`[Step 2] 馬名: ${horse_name} / ${horse_name_en}`);

    // ── プロフィール (英語キー・凡例除外) ────────────────────────
    const profile = {};
    const dtddRegex = /<dt>([^<]+)<\/dt>\s*<dd>([\s\S]*?)<\/dd>/g;
    let m;
    while ((m = dtddRegex.exec(html)) !== null) {
        const jaKey = m[1].trim();
        if (PROFILE_SKIP_KEYS.has(jaKey)) continue;
        const enKey = PROFILE_KEY_MAP[jaKey] || jaKey;
        const val = stripTags(m[2]).replace(/産駒/g, '').trim();
        profile[enKey] = val;
    }
    log(`[Step 2] プロフィール項目数: ${Object.keys(profile).length}`);

    // ── 賞金 (英語キー) ─────────────────────────────────────────
    const prize = {};
    const prizeSectionMatch = html.match(/総賞金([\s\S]{0,2000}?)収得賞金（障害）/);
    if (prizeSectionMatch) {
        const prizeHtml = prizeSectionMatch[0];
        const enLabels = [
            'total_prize', 'bonus_prize', 'local_prize',
            'overseas_prize', 'flat_earned_prize', 'hurdle_earned_prize',
        ];
        const amounts = [...prizeHtml.matchAll(/[\d,]+(?=(?:<span>)?円)/g)];
        enLabels.forEach((key, i) => {
            prize[key] = amounts[i] ? amounts[i][0].replace(/,/g, '') : '0';
        });
    }
    log(`[Step 2] 賞金: ${JSON.stringify(prize)}`);

    // ── 出走レース ───────────────────────────────────────────────
    const races = [];
    const raceSection = html.indexOf('出走レース');
    if (raceSection !== -1) {
        const racePart = html.slice(raceSection);
        const tbodyMatches = [...racePart.matchAll(/<tbody>([\s\S]*?)<\/tbody>/g)];
        for (const tbodyMatch of tbodyMatches) {
            const rows = [...tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)];
            for (const row of rows) {
                const allTds = [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                    .map(t => stripTags(t[1]));
                if (allTds.length < 10) continue;
                if (!allTds[0].match(/\d{4}年\d+月\d+日/)) continue;
                races.push({
                    date:       allTds[0],
                    basho:      allTds[1],
                    race_name:  allTds[2],
                    distance:   allTds[3],
                    baba:       allTds[4],
                    num_horses: allTds[5],
                    ninki:      allTds[6],
                    chakujun:   allTds[7],
                    jockey:     allTds[8],
                    futan:      allTds[9],
                    bataiju:    allTds[10] ?? '',
                    time:       allTds[11] ?? '',
                    rt:         allTds[12] ?? '',
                    chakuma:    allTds[13] ?? '',
                });
            }
        }
    }
    log(`[Step 2] 出走レース: ${races.length}件`);

    // ── レース条件別成績 ─────────────────────────────────────────
    const stats = {};
    const resultUnitStart = html.indexOf('<li id="result_unit">');
    if (resultUnitStart !== -1) {
        const resultPart = html.slice(resultUnitStart);
        const tableMatches = [...resultPart.matchAll(/<table[^>]*>([\s\S]*?)<\/table>/g)];

        for (const tableMatch of tableMatches) {
            const tableHtml = tableMatch[1];

            const captionMatch = tableHtml.match(/<div class="main">([^<]+)<\/div>/);
            if (!captionMatch) continue;
            const caption = captionMatch[1].trim();

            const theadMatch = tableHtml.match(/<thead>([\s\S]*?)<\/thead>/);
            if (!theadMatch) continue;
            const allHeaders = [...theadMatch[1].matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
                .map(h => stripTags(h[1]));

            const tbodyMatch = tableHtml.match(/<tbody>([\s\S]*?)<\/tbody>/);
            if (!tbodyMatch) continue;
            const rowMatches = [...tbodyMatch[1].matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)];

            if (caption === '平地レース合計' || caption === '障害レース合計') {
                const enKey = caption === '平地レース合計' ? 'flat_total' : 'hurdle_total';
                if (rowMatches.length > 0) {
                    const tds = [...rowMatches[0][1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)]
                        .map(t => stripTags(t[1]));
                    stats[enKey] = makeStatRow(allHeaders, tds);
                }

            } else if (caption === 'コース別成績') {
                const statHeaders = allHeaders.slice(1);
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

    // ── 結果出力 ─────────────────────────────────────────────────
    const result = { cname, horse_name, horse_name_en, profile, prize, races, stats };

    log('================================================================');
    log(`完了: 馬名=${horse_name} 出走=${races.length}件 統計=${Object.keys(stats).length}セクション`);
    log('================================================================');

    logStream.end();
    console.log(JSON.stringify(result, null, 2));

})().catch((err) => {
    log(`致命的エラー: ${err.message}`);
    logStream.end();
    process.exit(1);
});
