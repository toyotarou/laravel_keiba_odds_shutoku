import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../const/const.dart';
import '../data/http/client.dart';
import '../data/http/path.dart';
import '../models/common/ai_response_recommend_horse_model.dart';
import '../models/horse_model.dart';
import '../models/odds_model.dart';
import '../models/popularity_rank_odds_median_model.dart';
import '../models/race_analysis_model.dart';
import '../models/race_introspection_model.dart';
import '../models/race_result_history_model.dart';
import '../models/race_result_payout_model.dart';

/// "kaisuu_basho_day" 形式の文字列を分解して named record で返す。
///
/// [kbd] は selectedScheduleKaisuuBashoDay の値で、"1_中京_1" のように
/// "回次_場所名_日目" を '_' 区切りで結合した文字列。
/// パーツが不足している場合は空文字を返す。
({String kaisuu, String basho, String day}) parseKbdParts(String kbd) {
  final List<String> parts = kbd.split('_');
  return (
    kaisuu: parts.isNotEmpty ? parts[0] : '',
    basho: parts.length > 1 ? parts[1] : '',
    day: parts.length > 2 ? parts[2] : '',
  );
}

/// races パラメータ（"date|kaisuu|basho|race" を "/" で複数結合）で払戻データを取得し
/// "${date}_${kaisuu}_${bashoCode}_${day}_${race}" をキーとする Map を返す。
///
/// ⚠️ マップキーには [RaceResultPayoutModel.bashoCode]（`json['basho_code']`）を使用する。
/// 呼び出し元で lookupKey を組み立てる際は同じフィールド値を使うこと。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<String, RaceResultPayoutModel>> fetchPayoutMap(WidgetRef ref, {required String racesParam}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(path: APIPath.getHorseOddsFinderRaceResultPayout, queryParameters: <String, dynamic>{'races': racesParam});
  final List<dynamic> dataList = (response as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
  final Map<String, RaceResultPayoutModel> map = <String, RaceResultPayoutModel>{};
  for (final dynamic item in dataList) {
    final RaceResultPayoutModel m = RaceResultPayoutModel.fromJson(item as Map<String, dynamic>);
    map['${m.date}_${m.kaisuu}_${m.bashoCode}_${m.day}_${m.race}'] = m;
  }
  return map;
}

/// races パラメータ（"date|kaisuu|basho|race" を "/" で複数結合）で払戻データを取得し
/// レスポンス順のまま List で返す。
///
/// キー引きが不要な呼び出し元（similar_races など）はこちらを使う。
/// キー引きが必要な場合は [fetchPayoutMap] を使うこと。
/// 通信エラーは呼び出し元に伝播する。
Future<List<RaceResultPayoutModel>> fetchPayoutList(WidgetRef ref, {required String racesParam}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(path: APIPath.getHorseOddsFinderRaceResultPayout, queryParameters: <String, dynamic>{'races': racesParam});
  final List<dynamic> dataList = (response as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
  return dataList.map((dynamic item) => RaceResultPayoutModel.fromJson(item as Map<String, dynamic>)).toList();
}

/// 馬名リストで過去戦績を取得し、馬名 → 戦績リスト の Map を返す。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<String, List<RaceResultHistoryModel>>> fetchBattleRecordsByName(
  WidgetRef ref, {
  required List<String> horseNames,
}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(
        path: APIPath.getHorseOddsFinderHorseBattleRecord,
        queryParameters: <String, dynamic>{'name': horseNames.join('/')},
      );
  final List<dynamic> dataList = (response as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
  final Map<String, List<RaceResultHistoryModel>> byName = <String, List<RaceResultHistoryModel>>{};
  for (final dynamic item in dataList) {
    final RaceResultHistoryModel m = RaceResultHistoryModel.fromJson(item as Map<String, dynamic>);
    byName.putIfAbsent(m.name, () => <RaceResultHistoryModel>[]).add(m);
  }
  return byName;
}

/// HorseModel リストと対象日付から、馬番 → 着順（3着以内のみ、圏外は null）の Map を返す。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<int, int?>> fetchFinishingPositionMap(
  WidgetRef ref, {
  required List<HorseModel> horses,
  required String date,
}) async {
  final List<String> horseNames = horses.map((HorseModel e) => e.name).where((String n) => n.isNotEmpty).toList();
  if (horseNames.isEmpty) {
    return <int, int?>{};
  }
  final Map<String, List<RaceResultHistoryModel>> byName = await fetchBattleRecordsByName(ref, horseNames: horseNames);
  final Map<int, int?> map = <int, int?>{};
  for (final HorseModel horse in horses) {
    final List<RaceResultHistoryModel> records = byName[horse.name] ?? <RaceResultHistoryModel>[];
    final RaceResultHistoryModel? record = records.where((RaceResultHistoryModel r) => r.date == date).firstOrNull;
    if (record != null) {
      final int pos = record.finishingPosition;
      map[horse.num] = pos >= 1 ? pos : null;
    }
  }
  return map;
}

/// date / kaisuu / basho / day / race で一致する RaceIntrospectionModel を返す。
///
/// 呼び出し元はすべて数値コード（ScheduleModel.basho / SummaryModel.basho 等）を渡すため、
/// RaceIntrospectionModel.bashoCode（数値コード）と比較する。
/// RaceIntrospectionModel.basho は場所名（例: "新潟"）なので混同しないこと。
RaceIntrospectionModel? findRaceIntrospection(
  Map<String, RaceIntrospectionModel> map, {
  required String date,
  required int kaisuu,
  required String basho,
  required int day,
  required int race,
}) {
  return map.values
      .where(
        (RaceIntrospectionModel e) =>
            e.date == date && e.kaisuu == kaisuu && e.bashoCode == basho && e.day == day && e.race == race,
      )
      .firstOrNull;
}

/// 着順に応じた色を返す。
Color raceRankColor(int? rank, {double alpha = 0.5, Color fallback = Colors.transparent}) => switch (rank) {
  1 => const Color(0xFFFFD700).withValues(alpha: alpha),
  2 => const Color(0xFFC0C0C0).withValues(alpha: alpha),
  3 => const Color(0xFFCD7F32).withValues(alpha: alpha),
  _ => fallback,
};

/// 人気順位に対応する過去中央値オッズを返す（rank は 1 始まり）。
String medianByRank(PopularityRankOddsMedianModel model, int rank) {
  final List<String> medians = <String>[
    model.median01,
    model.median02,
    model.median03,
    model.median04,
    model.median05,
    model.median06,
    model.median07,
    model.median08,
    model.median09,
    model.median10,
    model.median11,
    model.median12,
    model.median13,
    model.median14,
    model.median15,
    model.median16,
    model.median17,
    model.median18,
  ];
  if (rank < 1 || rank > medians.length) {
    return '';
  }
  return medians[rank - 1];
}

/// 6分前オッズを昇順に並べ、馬番 → 人気順位（1始まり）のマップを返す。
Map<int, int> buildSixMinRankMap(Map<int, String> sixMinOddsMap) {
  final List<MapEntry<int, double>> sorted =
      sixMinOddsMap.entries
          .map((MapEntry<int, String> e) => MapEntry<int, double>(e.key, double.tryParse(e.value) ?? double.infinity))
          .toList()
        ..sort((MapEntry<int, double> a, MapEntry<int, double> b) => a.value.compareTo(b.value));
  return <int, int>{for (int i = 0; i < sorted.length; i++) sorted[i].key: i + 1};
}

/// 期待数値スコア（過去中央値オッズ ÷ 現在オッズ）の上位 pickupCount 頭の
/// displayList 内インデックス（1始まり）を Set で返す。
///
/// sixMinOddsMap を渡すと 6分前オッズ基準でスコアを計算する（予想総括ダイアログ用）。
/// 省略した場合は displayList のオッズと順位をそのまま使う（メイン行・選択タイミング基準）。
/// score > 1.0 → 過去より割安、score < 1.0 → 過去より割高。
Set<int> calcPickupPopularitySet(
  List<OddsModel> displayList,
  PopularityRankOddsMedianModel medianModel,
  int pickupCount, {
  Map<int, String>? sixMinOddsMap,
}) {
  final Map<int, int> sixMinRankMap = (sixMinOddsMap != null && sixMinOddsMap.isNotEmpty)
      ? buildSixMinRankMap(sixMinOddsMap)
      : <int, int>{};

  final List<MapEntry<int, double>> scored = displayList.asMap().entries.map((MapEntry<int, OddsModel> e) {
    final int displayRank = e.key + 1;
    final int medianRank = sixMinRankMap[e.value.num] ?? displayRank;

    final double? parsedSixMin = double.tryParse(sixMinOddsMap?[e.value.num] ?? '');
    final double oddsVal = (parsedSixMin != null && parsedSixMin > 0)
        ? parsedSixMin
        : (double.tryParse(e.value.odds) ?? 0);

    double score = 0;
    if (oddsVal > 0) {
      final double median = double.tryParse(medianByRank(medianModel, medianRank)) ?? 0;
      if (median > 0) {
        score = median / oddsVal;
      }
    }
    return MapEntry<int, double>(displayRank, score);
  }).toList()..sort((MapEntry<int, double> a, MapEntry<int, double> b) => b.value.compareTo(a.value));

  return scored.take(pickupCount).map((MapEntry<int, double> e) => e.key).toSet();
}

/// getHorseOddsFinderHighProbabilityHorses API を呼び出し、
/// 人気順位 → analysis テキスト（空でないもののみ）の Map を返す。
///
/// レスポンスには同日の他レース・他開催分も含まれるため、
/// race / kaisuu / basho / day の全条件で絞り込んでから返す。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<int, String>> fetchHighProbabilityAnalysis(
  WidgetRef ref, {
  required String date,
  required String kaisuu,
  required String basho,
  required String day,
  required int race,
}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(
        path: APIPath.getHorseOddsFinderHighProbabilityHorses,
        queryParameters: <String, dynamic>{
          'date': date,
          'kaisuu': kaisuu,
          'basho': basho,
          'day': day,
          'race': race.toString(),
        },
      );
  final List<dynamic> dataList = (response as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
  final Map<int, String> result = <int, String>{};
  for (final dynamic item in dataList) {
    final RaceAnalysisModel m = RaceAnalysisModel.fromJson(item as Map<String, dynamic>);
    // 同日他レース・他開催のデータが混入しないよう全キーで絞り込む
    if (m.race == race && m.kaisuu == kaisuu && m.basho == basho && m.day == day) {
      for (final HorseOddsFinderSimilarRaceHorseModel horse in m.horses) {
        if (horse.analysis.isNotEmpty) {
          result[horse.popularityRank] = horse.analysis;
        }
      }
    }
  }
  return result;
}

/// AI分析APIを呼び出し、レスポンスの data フィールドを返す。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<String, dynamic>> fetchAiAnalysisData(
  WidgetRef ref, {
  required String date,
  required String kaisuu,
  required String basho,
  required String day,
  required int race,
  required List<int> gapHorseNums,
  required List<int> upsetPickupHorseNums,
}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(
        path: APIPath.getHorseOddsFinderAiAnalysis,
        queryParameters: <String, dynamic>{
          'date': date,
          'kaisuu': kaisuu,
          'basho': basho,
          'day': day,
          'race': race.toString(),
          'gapHorseNums': gapHorseNums.join('|'),
          'upsetPickupHorseNums': upsetPickupHorseNums.join('|'),
        },
      );
  return (response as Map<String, dynamic>)['data'] as Map<String, dynamic>? ?? <String, dynamic>{};
}

/// DeepSeek（第2AI）分析APIを呼び出し、レスポンスの data フィールドを返す。
/// data が Map の場合はそのまま返し、List の場合は先頭要素を返す。
Future<Map<String, dynamic>> fetchSecondAiOpinionData(
  WidgetRef ref, {
  required String date,
  required String kaisuu,
  required String basho,
  required String day,
  required int race,
}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(
        path: APIPath.getHorseOddsFinderSecondAiOpinion,
        queryParameters: <String, dynamic>{
          'date': date,
          'kaisuu': kaisuu,
          'basho': basho,
          'day': day,
          'race': race.toString(),
        },
      );
  final dynamic data = (response as Map<String, dynamic>)['data'];
  if (data is Map<String, dynamic>) {
    return data;
  }
  if (data is List<dynamic> && data.isNotEmpty) {
    return data.first as Map<String, dynamic>? ?? <String, dynamic>{};
  }
  return <String, dynamic>{};
}

/// analysis_text から「厳選穴レース」の値を取得する。
/// テキストの先頭行が "厳選穴レース|{数値}" の形式であれば {数値} を返す。
/// 該当しない場合は null を返す。
int? parseUpsetRaceValue(String text) {
  for (final String line in text.split('\n')) {
    final String trimmed = line.trim();
    if (trimmed.startsWith('厳選穴レース|')) {
      return int.tryParse(trimmed.substring('厳選穴レース|'.length).trim());
    }
  }
  return null;
}

/// analysis_text を解析して推奨馬リストを返す。
/// analysis_text から波乱度・下位進入度・大穴進入度を取得する。
/// 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」の行をパースして返す。
/// 該当行がない・パース不能な場合は null を返す。
Map<String, int>? parseRaceMetrics(String text) {
  for (final String line in text.split('\n')) {
    final String trimmed = line.trim();
    if (trimmed.startsWith('レース指標|')) {
      final List<String> parts = trimmed.split('|');
      int? extract(String key) {
        for (final String p in parts) {
          if (p.startsWith(key)) {
            return int.tryParse(p.substring(key.length).trim());
          }
        }
        return null;
      }

      final int? upset = extract('波乱度: ');
      final int? lower = extract('下位進入度: ');
      final int? longShot = extract('大穴進入度: ');
      if (upset != null && lower != null && longShot != null) {
        return <String, int>{'波乱度': upset, '下位進入度': lower, '大穴進入度': longShot};
      }
    }
  }
  return null;
}

/// API 応答の race_metrics（{"波乱度":x,"下位進入度":y,"大穴進入度":z}）を Map<String,int> に変換する。
///
/// 1st AI テキストを再パースする parseRaceMetrics と違い、
/// サーバーが確定させた値をそのまま受け取るために使う。
/// 3項目すべてが数値で揃っていない場合は null を返す。
Map<String, int>? parseRaceMetricsJson(dynamic raw) {
  if (raw is! Map) {
    return null;
  }

  int? pick(String key) {
    final dynamic v = raw[key];
    if (v is num) {
      return v.toInt();
    }
    if (v is String) {
      return int.tryParse(v);
    }
    return null;
  }

  final int? upset = pick('波乱度');
  final int? lower = pick('下位進入度');
  final int? longShot = pick('大穴進入度');
  if (upset == null || lower == null || longShot == null) {
    return null;
  }
  return <String, int>{'波乱度': upset, '下位進入度': lower, '大穴進入度': longShot};
}

/// \n\n 区切りでも \n 区切りでも動作するよう、馬番：の出現位置でブロックを分割する。
List<AiResponseRecommendHorseModel> parseAnalysisText(String text) {
  final RegExp horseStart = RegExp(r'馬番：\d+');
  final List<RegExpMatch> matches = horseStart.allMatches(text).toList();
  if (matches.isEmpty) {
    return <AiResponseRecommendHorseModel>[];
  }

  final List<String> blocks = <String>[];
  for (int i = 0; i < matches.length; i++) {
    final int start = matches[i].start;
    final int end = i + 1 < matches.length ? matches[i + 1].start : text.length;
    blocks.add(text.substring(start, end).trim());
  }

  return blocks.map((String block) {
    final int reasonIdx = block.indexOf('選出理由：');
    final String reason = reasonIdx != -1 ? block.substring(reasonIdx + '選出理由：'.length).trim() : '';
    final String meta = reasonIdx != -1 ? block.substring(0, reasonIdx) : block;

    String extract(String key) {
      final int idx = meta.indexOf(key);
      if (idx == -1) {
        return '';
      }
      final int start = idx + key.length;
      final int end = meta.indexOf('、', start);
      return (end == -1 ? meta.substring(start) : meta.substring(start, end)).trim();
    }

    return AiResponseRecommendHorseModel(
      num: int.tryParse(extract('馬番：')) ?? 0,
      name: extract('馬名：'),
      popularity: extract('人気順: '),
      odds: extract('6分前オッズ: '),
      score: int.tryParse(extract('おすすめ度: ')) ?? 0,
      reason: reason,
    );
  }).toList();
}

/// 払戻データから入賞3頭の馬番セットを返す（三連単 → 三連複の順で試みる）。
Set<int> extractResultNumsFromPayout(RaceResultPayoutModel payout) {
  for (final String raw in <String>[payout.trifecta, payout.trio]) {
    final String numsPart = raw.split('/').first.split('|').first;
    if (numsPart.contains('-')) {
      final Set<int> nums = numsPart.split('-').map((String s) => int.tryParse(s.trim())).whereType<int>().toSet();
      if (nums.length == 3) {
        return nums;
      }
    }
  }
  return <int>{};
}

/// 払戻の組み合わせ（"1-5-14" など）の馬番がすべて [hitNums] に含まれるか。順序は問わない。
///
/// [hitNums] が空、または馬番として読めない要素がある場合は false。
bool isPayoutComboHit(String combo, Set<int> hitNums) {
  if (hitNums.isEmpty) {
    return false;
  }

  final List<int?> nums = combo.split('-').map((String e) => int.tryParse(e.trim())).toList();
  if (nums.isEmpty || nums.contains(null)) {
    return false;
  }

  return nums.every((int? n) => hitNums.contains(n));
}

/// 馬のリストから、馬番 → 枠番の Map を作る。枠連の判定（[isWakuComboHit] / [findMaxHitPayout]）に使う。
Map<int, int> buildNumToWakuMap(Iterable<HorseModel> horses) => <int, int>{
  for (final HorseModel h in horses) h.num: h.waku,
};

/// 枠連の組み合わせ（"3-6" など。枠番）が、合致した馬 [hitNums] の枠番で成り立つか。
///
/// [numToWaku] は馬番 → 枠番。合致した馬のうち 2頭（別の馬）の枠番が、組み合わせの 2つの枠と一致すれば true
/// （同枠の組み合わせ "3-3" は、その枠の馬が 2頭合致している場合のみ）。
/// 枠番が分からない（[numToWaku] が空など）ときは false。
bool isWakuComboHit(String combo, Set<int> hitNums, Map<int, int> numToWaku) {
  final List<int?> frames = combo.split('-').map((String e) => int.tryParse(e.trim())).toList();
  if (frames.length != 2 || frames.contains(null)) {
    return false;
  }

  final List<int> hitFrames = <int>[
    for (final int n in hitNums)
      if (numToWaku[n] != null) numToWaku[n]!,
  ];

  final int first = frames[0]!;
  final int second = frames[1]!;
  if (first == second) {
    return hitFrames.where((int w) => w == first).length >= 2;
  }
  return hitFrames.contains(first) && hitFrames.contains(second);
}

/// 合致した馬番 [hitNums] だけで成り立つ払戻のうち、最高金額とその券種名を返す。
///
/// 枠連は枠番で払い戻されるため、馬番 → 枠番の [numToWaku] があるときだけ対象にする。成り立つ払戻が無ければ null。
({String name, int amount})? findMaxHitPayout(
  RaceResultPayoutModel payout,
  Set<int> hitNums, {
  Map<int, int> numToWaku = const <int, int>{},
}) {
  final List<(String, String)> tickets = <(String, String)>[
    ('単勝', payout.tan),
    ('複勝', payout.fuku),
    ('枠連', payout.waku),
    ('馬連', payout.umaren),
    ('ワイド', payout.wide),
    ('馬単', payout.umatan),
    ('三連複', payout.trio),
    ('三連単', payout.trifecta),
  ];

  ({String name, int amount})? best;
  for (final (String name, String raw) in tickets) {
    for (final String entry in raw.split('/')) {
      final List<String> parts = entry.split('|');
      final bool isHit = name == '枠連'
          ? isWakuComboHit(parts.first, hitNums, numToWaku)
          : isPayoutComboHit(parts.first, hitNums);
      if (parts.length < 2 || !isHit) {
        continue;
      }

      final int amount = int.tryParse(parts[1].trim().replaceAll(',', '')) ?? 0;
      if (best == null || amount > best.amount) {
        best = (name: name, amount: amount);
      }
    }
  }
  return best;
}

/// そのレースの全券種の払戻のうち、最高金額とその券種名を返す（的中・合致は問わない）。
///
/// 通常は三連単になる。払戻データが空などで金額が読めなければ null。
({String name, int amount})? findMaxPayout(RaceResultPayoutModel payout) {
  final List<(String, String)> tickets = <(String, String)>[
    ('単勝', payout.tan),
    ('複勝', payout.fuku),
    ('枠連', payout.waku),
    ('馬連', payout.umaren),
    ('ワイド', payout.wide),
    ('馬単', payout.umatan),
    ('三連複', payout.trio),
    ('三連単', payout.trifecta),
  ];

  ({String name, int amount})? best;
  for (final (String name, String raw) in tickets) {
    for (final String entry in raw.split('/')) {
      final List<String> parts = entry.split('|');
      if (parts.length < 2) {
        continue;
      }

      final int amount = int.tryParse(parts[1].trim().replaceAll(',', '')) ?? 0;
      if (amount > 0 && (best == null || amount > best.amount)) {
        best = (name: name, amount: amount);
      }
    }
  }
  return best;
}

/// 振り返りテキストの "## ピックアップ" セクションから、ピックアップした馬の馬番を返す。
///
/// 行の形式: "○14番 タガノゲイル" / "○4 マーノマエストロ" / "5番 ブライトスクリプト"
/// セクションが無い場合は空の Set を返す。
Set<int> extractPickupNums(String introspection) {
  final Set<int> nums = <int>{};
  bool inPickup = false;
  for (final String line in introspection.split('\n')) {
    final String trimmed = line.trim();
    if (trimmed == '## ピックアップ') {
      inPickup = true;
      continue;
    }
    if (!inPickup) {
      continue;
    }
    if (trimmed.startsWith('## ')) {
      break;
    }
    final RegExpMatch? m = RegExp(r'^[○◯]?\s*(\d+)').firstMatch(trimmed);
    if (m != null) {
      nums.add(int.parse(m.group(1)!));
    }
  }
  return nums;
}

/// 振り返りテキストから "## 結果" セクションの最初の非空行を返す。
///
/// 振り返りテキストは "## セクション名\n内容" の形式で構成されている。
/// "## 結果" が見つかれば次の "## " まで走査し、最初の非空行を返す。
/// セクションが存在しない場合や空の場合は null を返す。
String? extractResultLine(String introspection) {
  final List<String> lines = introspection.split('\n');
  bool inResult = false;
  for (final String line in lines) {
    final String trimmed = line.trim();
    if (trimmed == '## 結果') {
      inResult = true;
      continue;
    }
    if (inResult) {
      if (trimmed.startsWith('## ')) {
        break;
      }
      if (trimmed.isNotEmpty) {
        return trimmed;
      }
    }
  }
  return null;
}

/// 馬眼力指数APIを呼び出し、馬番 → baganriki_index の Map を返す。
/// 通信エラーは呼び出し元に伝播する。
Future<Map<int, double?>> fetchBaganrikiIndexData(
  WidgetRef ref, {
  required String date,
  required String kaisuu,
  required String basho,
  required String day,
  required int race,
}) async {
  final dynamic response = await ref
      .read(httpClientProvider)
      .get(
        path: APIPath.getHorseOddsFinderBaganrikiIndex,
        queryParameters: <String, dynamic>{
          'date': date,
          'kaisuu': kaisuu,
          'basho_code': basho,
          'day': day,
          'race': race.toString(),
        },
      );
  final List<dynamic> dataList = (response as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
  return <int, double?>{
    for (final dynamic item in dataList)
      (item as Map<String, dynamic>)['num'] as int: (item['baganriki_index'] as num?)?.toDouble(),
  };
}

// ============================================================
// オッズ計算ユーティリティ（複数画面から共用）
// ============================================================

/// selectedTiming と オッズリストから表示に使うminutesBeforeStartを解決する。
int? resolveFilterMinutes(String selectedTiming, List<OddsModel> oddsModelList, int firstTiming) {
  if (selectedTiming.isNotEmpty) {
    if (selectedTiming == kOddsTimingLastLabel) {
      return kOddsTimingLast;
    }
    final int parsed = int.tryParse(selectedTiming) ?? 0;
    if (parsed == firstTiming) {
      return oddsModelList.any((OddsModel e) => e.minutesBeforeStart == firstTiming) ? firstTiming : kOddsTimingFirst;
    }
    return parsed;
  }
  if (oddsModelList.any((OddsModel e) => e.minutesBeforeStart == kOddsTimingLast)) {
    return kOddsTimingLast;
  }
  if (oddsModelList.isNotEmpty && oddsModelList.every((OddsModel e) => e.minutesBeforeStart == kOddsTimingFirst)) {
    return kOddsTimingFirst;
  }
  final List<int> validValues =
      oddsModelList.map((OddsModel e) => e.minutesBeforeStart).where((int v) => v >= 0).toList()..sort();
  return validValues.isNotEmpty ? validValues.first : null;
}

/// 特定レースのオッズリストを selectedTiming に基づいて絞り込み・ソートして返す。
List<OddsModel> buildOddsDisplayList({
  required List<OddsModel> oddsForRace,
  required String selectedTiming,
  required String configFirstKey,
}) {
  final int firstTiming = int.tryParse(configFirstKey) ?? 0;
  final int? filterMinutes = resolveFilterMinutes(selectedTiming, oddsForRace, firstTiming);
  return (filterMinutes != null
          ? oddsForRace.where((OddsModel e) => e.minutesBeforeStart == filterMinutes).toList()
          : oddsForRace)
      .where((OddsModel e) => (double.tryParse(e.odds) ?? 0) > 0)
      .toList()
    ..sort((OddsModel a, OddsModel b) => (double.tryParse(a.odds) ?? 0).compareTo(double.tryParse(b.odds) ?? 0));
}

/// オッズの大きな断絶がある馬番リストを返す。
List<int> calcOddsGapHorseNums(List<OddsModel> oddsForRace) {
  if (!oddsForRace.any((OddsModel e) => e.minutesBeforeStart == kOddsTimingFirst) ||
      !oddsForRace.any((OddsModel e) => e.minutesBeforeStart == kOddsJudgeTiming)) {
    return <int>[];
  }
  final List<OddsModel> sixMinList =
      oddsForRace
          .where((OddsModel e) => e.minutesBeforeStart == kOddsJudgeTiming && (double.tryParse(e.odds) ?? 0) > 0)
          .toList()
        ..sort((OddsModel a, OddsModel b) => (double.tryParse(a.odds) ?? 0).compareTo(double.tryParse(b.odds) ?? 0));
  final List<int> gapHorseNums = <int>[];
  for (int i = 0; i < sixMinList.length - 1; i++) {
    final double oddsA = double.tryParse(sixMinList[i].odds) ?? 0;
    final double oddsB = double.tryParse(sixMinList[i + 1].odds) ?? 0;
    if (oddsA <= 0) {
      continue;
    }
    if (oddsB / oddsA > 2.0) {
      gapHorseNums.add(sixMinList[i].num);
    }
  }
  return gapHorseNums;
}

/// 波乱馬ピックアップの馬番リストを返す。
List<int> calcUpsetPickupHorseNums({
  required List<OddsModel> oddsForRace,
  required PopularityRankOddsMedianModel? medianModel,
  required List<OddsModel> displayList,
}) {
  if (!oddsForRace.any((OddsModel e) => e.minutesBeforeStart == kOddsTimingFirst) ||
      !oddsForRace.any((OddsModel e) => e.minutesBeforeStart == kOddsJudgeTiming)) {
    return <int>[];
  }
  if (medianModel == null) {
    return <int>[];
  }
  final Map<int, String> sixMinOddsMap = <int, String>{
    for (final OddsModel o in oddsForRace.where((OddsModel e) => e.minutesBeforeStart == kOddsJudgeTiming))
      o.num: o.odds,
  };
  final List<OddsModel> sixMinSortedList = sixMinOddsMap.isEmpty
      ? displayList
      : (List<OddsModel>.from(displayList)..sort((OddsModel a, OddsModel b) {
          final double aOdds = double.tryParse(sixMinOddsMap[a.num] ?? '') ?? double.infinity;
          final double bOdds = double.tryParse(sixMinOddsMap[b.num] ?? '') ?? double.infinity;
          return aOdds.compareTo(bOdds);
        }));
  final int pickupCount = sixMinSortedList.length <= 8
      ? 4
      : sixMinSortedList.length <= 13
      ? 5
      : 6;
  final Set<int> pickupPopularitySet = calcPickupPopularitySet(
    sixMinSortedList,
    medianModel,
    pickupCount,
    sixMinOddsMap: sixMinOddsMap,
  );
  return <int>[
    for (int i = 0; i < sixMinSortedList.length; i++)
      if (pickupPopularitySet.contains(i + 1)) sixMinSortedList[i].num,
  ];
}

/// oddsMap から特定レースのオッズリストを返す。
List<OddsModel> buildOddsForRace(Map<String, List<OddsModel>> oddsMap, String mapKey, int race) =>
    (oddsMap[mapKey] ?? <OddsModel>[]).where((OddsModel e) => e.race == race).toList();

/// horseMap から特定レースの 馬番→HorseModel マップを返す。
Map<int, HorseModel> buildHorseModelMap(Map<String, List<HorseModel>> horseMap, String mapKey, int race) =>
    <int, HorseModel>{
      for (final HorseModel e in (horseMap[mapKey] ?? <HorseModel>[]).where((HorseModel e) => e.race == race)) e.num: e,
    };

/// oddsForRace から 6分前オッズのリスト（オッズ昇順）を返す。データがない場合は fallbackList を返す。
List<OddsModel> buildSixMinDisplayList(List<OddsModel> oddsForRace, List<OddsModel> fallbackList) {
  final List<OddsModel> list = oddsForRace.where((OddsModel e) => e.minutesBeforeStart == kOddsJudgeTiming).toList()
    ..sort((OddsModel a, OddsModel b) {
      final double aOdds = double.tryParse(a.odds) ?? double.infinity;
      final double bOdds = double.tryParse(b.odds) ?? double.infinity;
      return aOdds.compareTo(bOdds);
    });
  return list.isNotEmpty ? list : fallbackList;
}

/// keepPopularityRankOddsMedianMap から特定レースの中央値モデルを返す。
PopularityRankOddsMedianModel? lookupMedianModel(
  Map<String, List<PopularityRankOddsMedianModel>> medianMap,
  String mapKey,
  int race,
) {
  final List<PopularityRankOddsMedianModel> list = (medianMap[mapKey] ?? <PopularityRankOddsMedianModel>[])
      .where((PopularityRankOddsMedianModel e) => e.race == race)
      .toList();
  return list.isNotEmpty ? list.first : null;
}

/// merged_horses JSON リストを AiResponseRecommendHorseModel リストに変換する。
/// PHP の _mergeAiResults() が返す構造に対応する。
/// num が 0 以下の不正な要素は除外する。
List<AiResponseRecommendHorseModel> parseMergedHorses(List<dynamic> list) {
  // 【型に寛容にしている理由】
  //   PHP 側の統合おすすめ度は round(min(100.0, 両AI平均 + 5), 1) で算出される float。
  //   json_encode は 85.0 を 85（整数）、97.5 を 97.5（小数）として出力するため、
  //   JSON 上の型は一致馬のスコア次第で int にも double にもなる。
  //   以前は `as int?` で受けており、小数になった瞬間に TypeError が飛んで
  //   parseMergedHorses ごと失敗していた。例外は呼び出し元の catch で握り潰されるため、
  //   統合表示（merged_horses）が丸ごと捨てられ、フォールバック表示のままになっていた。
  //   数値はすべて num で受けてから丸める。
  int? asInt(dynamic v) {
    if (v is num) {
      return v.round();
    }
    if (v is String) {
      return int.tryParse(v) ?? double.tryParse(v)?.round();
    }
    return null;
  }

  return list
      .whereType<Map<String, dynamic>>()
      .map((Map<String, dynamic> m) {
        final dynamic odds6 = m['odds_6'];
        return AiResponseRecommendHorseModel(
          num: asInt(m['num']) ?? 0,
          name: (m['name'] as String?) ?? '',
          popularity: asInt(m['popularity'])?.toString() ?? '',
          odds: odds6 is num ? odds6.toStringAsFixed(1) : '',
          score: asInt(m['score']) ?? 0,
          reason: (m['reason'] as String?) ?? '',
          category: (m['category'] as String?) ?? 'first_only',
          score1st: asInt(m['score_1st']),
          score2nd: asInt(m['score_2nd']),
          reasonSecond: m['reason_2nd'] as String?,
          // 補充枠と購入推奨（サーバーが merged_horses に載せる）。
          //   古いサーバー応答にはキーが無いので、無ければ
          //   「補充枠ではない・購入推奨の対象」として扱う（従来どおりの表示）。
          fillSlot: m['fill_slot_unqualified'] == true,
          purchaseRecommended: m['purchase_recommended'] != false,
        );
      })
      .where((AiResponseRecommendHorseModel h) => h.num > 0)
      .toList();
}

/// 1st AI / 2nd AI のリストを1本に統合する。重複（同じ馬番）は 1st AI を優先。
List<AiResponseRecommendHorseModel> mergeAiHorseLists(
  List<AiResponseRecommendHorseModel> first,
  List<AiResponseRecommendHorseModel> second,
) {
  final Set<int> firstNums = first.map((AiResponseRecommendHorseModel h) => h.num).toSet();
  return <AiResponseRecommendHorseModel>[
    ...first,
    ...second.where((AiResponseRecommendHorseModel h) => !firstNums.contains(h.num)),
  ];
}
