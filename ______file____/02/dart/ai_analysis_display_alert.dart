import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../controllers/controllers_mixin.dart';
import '../../controllers/race_introspection/race_introspection.dart';
import '../../extensions/extensions.dart';
import '../../models/common/ai_response_recommend_horse_model.dart';
import '../../models/race_introspection_model.dart';
import '../../models/race_result_payout_model.dart';
import '../../utility/functions.dart';
import '../parts/odds_finder_dialog.dart';
import 'payout_data_display_alert.dart';

// import '../parts/odds_finder_dialog.dart';
// import 'ai_analysis_payout_result_alert.dart';
//
//

class AiAnalysisDisplayAlert extends ConsumerStatefulWidget {
  const AiAnalysisDisplayAlert({
    this.overrideDate,
    this.overrideKaisuuBashoDay,
    super.key,
    required this.raceNumber,
    required this.numToRankMap,
    required this.aiHorseList,
    required this.secondAiHorseList,
    this.mergedHorseList,
    this.upsetRaceValue,
    this.raceMetrics,
  });

  final int raceNumber;
  final Map<int, int> numToRankMap;
  final String? overrideDate;
  final String? overrideKaisuuBashoDay;
  final List<AiResponseRecommendHorseModel> aiHorseList;
  final List<AiResponseRecommendHorseModel> secondAiHorseList;
  final List<AiResponseRecommendHorseModel>? mergedHorseList;
  final int? upsetRaceValue;
  final Map<String, int>? raceMetrics;

  @override
  ConsumerState<AiAnalysisDisplayAlert> createState() => _AiAnalysisDisplayAlertState();
}

class _AiAnalysisDisplayAlertState extends ConsumerState<AiAnalysisDisplayAlert>
    with ControllersMixin<AiAnalysisDisplayAlert> {
  final Map<String, RaceResultPayoutModel> _payoutMap = <String, RaceResultPayoutModel>{};
  Map<int, double?> _baganrikiIndexMap = <int, double?>{};

  /// 過去レースから呼ばれた場合は override 値を、そうでなければ appParamState の値を使う
  String get _effectiveDate => widget.overrideDate ?? appParamState.selectedScheduleDate;

  String get _effectiveKbd => widget.overrideKaisuuBashoDay ?? appParamState.selectedScheduleKaisuuBashoDay;

  List<AiResponseRecommendHorseModel> get _mergedHorses => widget.mergedHorseList ?? <AiResponseRecommendHorseModel>[];

  /// 画面に並べる候補リスト（表示・集計・払戻計算で共通の基準）。
  ///
  /// どちらのAIが選んだかで列を分けたりはしない。統合結果をおすすめ度順のまま1本で出す。
  /// 統合結果が無いとき（2nd AI 未取得・通信失敗）は、1st AI の選出馬に
  /// 1st AI が選ばなかった 2nd AI の馬を後ろへ足したものを使う。
  List<AiResponseRecommendHorseModel> get _displayHorses {
    // 統合結果を受け取っていれば 0頭でもそれを正とする（サーバーが除外した馬を出さない）
    if (widget.mergedHorseList != null) {
      return _mergedHorses;
    }
    return mergeAiHorseLists(widget.aiHorseList, widget.secondAiHorseList);
  }

  /// この馬が「2nd AI だけが選んだ馬」か。
  /// 選出理由を枠線付きで出すかどうかの判定にだけ使う（順番や集計には影響しない）。
  bool _isSecondAiOnly(AiResponseRecommendHorseModel h) {
    if (widget.mergedHorseList != null) {
      return h.category == 'second_only';
    }
    return !widget.aiHorseList.any((AiResponseRecommendHorseModel a) => a.num == h.num);
  }

  ///
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _fetchPayout();
      _fetchBaganrikiIndex();

      // 20260924: 振り返りは起動時に取得しなくなったため、未取得ならここで取得する
      if (ref.read(raceIntrospectionProvider).raceIntrospectionMap.isEmpty) {
        raceIntrospectionNotifier.getAllRaceIntrospectionData();
      }
    });
  }

  ///
  Future<void> _fetchPayout() async {
    final String date = _effectiveDate;
    final (:String kaisuu, :String basho, day: _) = parseKbdParts(_effectiveKbd);
    if (kaisuu.isEmpty || basho.isEmpty) {
      return;
    }

    try {
      final Map<String, RaceResultPayoutModel> result = await fetchPayoutMap(
        ref,
        racesParam: '$date|$kaisuu|$basho|${widget.raceNumber}',
      );
      if (mounted) {
        setState(() => _payoutMap.addAll(result));
      }
    } catch (_) {}
  }

  ///
  Future<void> _fetchBaganrikiIndex() async {
    final String date = _effectiveDate;
    final (:String kaisuu, :String basho, :String day) = parseKbdParts(_effectiveKbd);
    try {
      final Map<int, double?> result = await fetchBaganrikiIndexData(
        ref,
        date: date,
        kaisuu: kaisuu,
        basho: basho,
        day: day,
        race: widget.raceNumber,
      );
      if (mounted) {
        setState(() {
          _baganrikiIndexMap = result;
        });
      }
    } catch (_) {}
  }

  ///
  @override
  Widget build(BuildContext context) {
    final (:String kaisuu, :String basho, day: String dayStr) = parseKbdParts(_effectiveKbd);

    final int kaisuuInt = int.tryParse(kaisuu) ?? 0;

    final int day = int.tryParse(dayStr) ?? 0;

    final String lookupKey = '${_effectiveDate}_${kaisuuInt}_${basho}_${day}_${widget.raceNumber}';

    final RaceResultPayoutModel? payout = _payoutMap[lookupKey];

    final RaceIntrospectionModel? introspectionModel = findRaceIntrospection(
      raceIntrospectionState.raceIntrospectionMap,
      date: _effectiveDate,
      kaisuu: kaisuuInt,
      basho: basho,
      day: day,
      race: widget.raceNumber,
    );

    final String? resultText = introspectionModel != null ? extractResultLine(introspectionModel.introspection) : null;

    // 表示用: 2nd AI 取得後は統合結果に切り替わる
    final List<AiResponseRecommendHorseModel> displayHorses = _displayHorses;

    // 画面に並んでいる馬のうち 3着以内に入った頭数を numToRankMap から直接計算
    final int matchedCount = displayHorses
        .where((AiResponseRecommendHorseModel h) => (widget.numToRankMap[h.num] ?? 99) <= 3)
        .length;

    // 画面に並んでいる馬で 1着・2着・3着 がすべて揃っているか
    final Set<int> hitRankSet = displayHorses
        .map((AiResponseRecommendHorseModel h) => widget.numToRankMap[h.num] ?? 99)
        .where((int r) => r <= 3)
        .toSet();

    // 20261005: 各馬カードで着順バッジ（rank <= 3）が付く馬の馬番。払戻金画面の「獲得」判定に渡す
    final Set<int> hitHorseNums = displayHorses
        .where((AiResponseRecommendHorseModel h) => (widget.numToRankMap[h.num] ?? 99) <= 3)
        .map((AiResponseRecommendHorseModel h) => h.num)
        .toSet();

    // DB の resultText は振り返りAIのピックアップ頭数ベースで生成されているため、
    // 実際に画面へ並んでいる本候補の「頭数」と「合致数」で上書きして表示する
    // 例: "6頭中2頭が合致" → "5頭中1頭が合致"
    String? adjustedResultText = resultText;
    if (resultText != null && displayHorses.isNotEmpty) {
      final RegExp pattern = RegExp(r'\d+頭中\d+頭が合致');
      if (pattern.hasMatch(resultText)) {
        adjustedResultText = resultText.replaceFirst(pattern, '${displayHorses.length}頭中$matchedCount頭が合致');
      }
    }

    // // 結果ボタンの主数字: 画面に並んでいる馬の合致数（numToRankMap ベース）
    // final String matchCount = matchedCount > 0 ? matchedCount.toString() : '';
    //
    // final bool showResultButton = payout != null && resultText != null && matchedCount > 0;
    //
    //
    //
    //

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: SafeArea(
        child: DefaultTextStyle(
          style: const TextStyle(color: Colors.white),
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Stack(
                  children: <Widget>[
                    const Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: <Widget>[
                        Text('馬眼力ピックアップ', style: TextStyle(fontSize: 12)),

                        SizedBox.shrink(),

                        // Column(
                        //   crossAxisAlignment: CrossAxisAlignment.start,
                        //   children: <Widget>[
                        //     const Text('馬眼力ピックアップ', style: TextStyle(fontSize: 12)),
                        //     if (adjustedResultText != null)
                        //       Text(
                        //         adjustedResultText,
                        //         style: const TextStyle(
                        //           fontSize: 11,
                        //           color: Colors.yellowAccent,
                        //           fontWeight: FontWeight.bold,
                        //         ),
                        //       ),
                        //   ],
                        // ),
                        // if (showResultButton) _buildResultButton(matchCount: matchCount, displayHorses: displayHorses),
                      ],
                    ),

                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: <Widget>[
                        const SizedBox.shrink(),

                        if (adjustedResultText != null)
                          Text(
                            adjustedResultText,
                            style: const TextStyle(
                              fontSize: 11,
                              color: Colors.yellowAccent,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),

                Divider(color: Colors.white.withValues(alpha: 0.4), thickness: 5),
                if (widget.upsetRaceValue != null) ...<Widget>[
                  Center(
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        if (widget.upsetRaceValue == 0) ...<Widget>[
                          Container(width: 20, height: 1, color: Colors.white),
                        ],
                        Text(
                          '厳選穴レース',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: widget.upsetRaceValue == 1
                                ? const Color(0xFFFBB6CE)
                                : Colors.white.withValues(alpha: 0.4),
                            decoration: widget.upsetRaceValue == 0 ? TextDecoration.lineThrough : TextDecoration.none,
                            decorationColor: Colors.white,
                          ),
                        ),
                        if (widget.upsetRaceValue == 0) ...<Widget>[
                          Container(width: 20, height: 1, color: Colors.white),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 6),
                ],
                if (widget.raceMetrics != null) ...<Widget>[
                  _buildRaceMetrics(widget.raceMetrics!),
                  const SizedBox(height: 6),
                ],

                // 未発走レースは払戻データが無いので、払戻データがあるときだけ表示する
                if (payout != null) ...<Widget>[
                  const SizedBox(height: 10),

                  Align(
                    child: Column(
                      children: <Widget>[
                        // 各ブロック左下のバッジ（$rank位）と同じものを、合致した着順だけ昇順で並べる
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <int>[1, 2, 3]
                              .where(hitRankSet.contains)
                              .map(
                                (int rank) => Container(
                                  width: 32,
                                  margin: const EdgeInsets.symmetric(horizontal: 2),
                                  alignment: Alignment.center,
                                  decoration: BoxDecoration(
                                    color: raceRankColor(rank, fallback: Colors.grey.withValues(alpha: 0.6)),
                                    borderRadius: BorderRadius.circular(4),
                                  ),
                                  child: Text('$rank位', style: const TextStyle(fontSize: 12, color: Colors.white)),
                                ),
                              )
                              .toList(),
                        ),

                        const SizedBox(height: 6),

                        GestureDetector(
                          onTap: () {
                            // 20261005: 払戻金画面を開く。着順バッジが付いた馬の馬番を渡し、合致した払戻にだけ「獲得」を出す
                            OddsFinderDialog(
                              context: context,
                              hideRaceOverlay: true,
                              widget: PayoutDataDisplayAlert(
                                raceNumber: widget.raceNumber,
                                overrideDate: widget.overrideDate,
                                overrideKaisuuBashoDay: widget.overrideKaisuuBashoDay,
                                hitHorseNums: hitHorseNums,
                                numToRankMap: widget.numToRankMap,
                                aiHorseNums: displayHorses.map((AiResponseRecommendHorseModel h) => h.num).toList(),
                              ),
                              paddingLeft: context.screenSize.width * 0.1,
                              paddingTop: context.screenSize.height * 0.05,
                            );
                          },
                          child: Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(vertical: 3),
                            decoration: BoxDecoration(
                              color: const Color(0xFFFBB6CE).withValues(alpha: 0.4),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            alignment: Alignment.center,
                            child: const Text('払戻金', style: TextStyle(fontSize: 10, color: Colors.white)),
                          ),
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 10),
                ],

                Expanded(child: _buildHorseList(displayHorses)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ///
  // Widget _buildResultButton({required String matchCount, required List<AiResponseRecommendHorseModel> displayHorses}) {
  //   return Stack(
  //     children: <Widget>[
  //       Positioned(
  //         right: 0,
  //         bottom: 0,
  //         child: Row(
  //           crossAxisAlignment: CrossAxisAlignment.end,
  //           children: <Widget>[
  //             Transform(
  //               alignment: Alignment.centerLeft,
  //               transform: Matrix4.identity()..setEntry(0, 1, -0.8),
  //               child: Text(
  //                 matchCount,
  //                 style: const TextStyle(fontSize: 20, color: Color(0xFFFBB6CE), fontWeight: FontWeight.bold),
  //               ),
  //             ),
  //           ],
  //         ),
  //       ),
  //       Column(
  //         children: <Widget>[
  //           const SizedBox(height: 10),
  //           Material(
  //             color: Colors.transparent,
  //             borderRadius: BorderRadius.circular(10),
  //             child: InkWell(
  //               onTap: () {
  //                 OddsFinderDialog(
  //                   context: context,
  //                   widget: AiAnalysisPayoutResultAlert(
  //                     aiRecommendHorses: displayHorses,
  //                     raceNumber: widget.raceNumber,
  //                   ),
  //                   paddingLeft: context.screenSize.width * 0.2,
  //                 );
  //               },
  //               borderRadius: BorderRadius.circular(10),
  //               splashColor: const Color(0xFFFFD700).withValues(alpha: 0.35),
  //               highlightColor: const Color(0xFFFFD700).withValues(alpha: 0.1),
  //               child: Container(
  //                 padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 10),
  //                 decoration: BoxDecoration(
  //                   border: Border.all(color: const Color(0xFFFFD700)),
  //                   borderRadius: BorderRadius.circular(10),
  //                 ),
  //                 child: const Text(
  //                   '合致結果',
  //                   style: TextStyle(fontSize: 10, color: Color(0xFFFFD700), fontWeight: FontWeight.bold),
  //                 ),
  //               ),
  //             ),
  //           ),
  //           const SizedBox(height: 10),
  //         ],
  //       ),
  //     ],
  //   );
  // }

  ///
  Widget _buildRaceMetrics(Map<String, int> metrics) {
    String stars(int v) => '★' * v + '☆' * (5 - v);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.05),
        border: Border.all(color: Colors.white.withValues(alpha: 0.2)),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: <Widget>[
          _buildMetricItem('波乱度', metrics['波乱度']!, stars(metrics['波乱度']!)),
          _buildMetricItem('下位進入度', metrics['下位進入度']!, stars(metrics['下位進入度']!)),
          _buildMetricItem('大穴進入度', metrics['大穴進入度']!, stars(metrics['大穴進入度']!)),
        ],
      ),
    );
  }

  ///
  Widget _buildMetricItem(String label, int value, String stars) {
    return Column(
      children: <Widget>[
        Text(label, style: const TextStyle(fontSize: 9, color: Colors.white70)),
        const SizedBox(height: 2),
        Text(stars, style: const TextStyle(fontSize: 10, color: Colors.yellowAccent, letterSpacing: 1)),
        Text(value.toString(), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
      ],
    );
  }

  /// 補充枠で入った馬に付ける印。
  ///
  /// 目標頭数に届かないとき、人気薄の資格を満たしていない7番人気以下を
  /// おすすめ度の順で補うことがある。その馬は通常の選出とは性質が違うので、
  /// 候補としては出しつつ「買う対象には入れていない」ことがわかるようにする。
  /// 補充枠でなければ何も出さない（表示は従来どおり）。
  Widget _buildFillSlotBadge(AiResponseRecommendHorseModel h) {
    if (!h.fillSlot) {
      return const SizedBox.shrink();
    }
    return _buildBadge(
      '補充枠 / 買い目には入れていません',
      Colors.cyanAccent,
    );
  }

  /// 公開候補ではあるが、買い目には入れていない馬に付ける印。
  ///
  /// 20261014（7回目ご指摘⑦）「公開候補と購入推奨の区別が画面上でも
  /// 明確になっているか確認してください」への対応。
  ///
  /// 補充枠（上の印）以外にも、買い目から外れる場合がある。
  ///   ・最低基準点に届かない
  ///   ・人気薄で回収率が低い帯に入る
  ///   ・低配当で妙味が小さい
  ///   ・過去回収率が低い
  /// これらはいずれも「候補としては出すが、買う対象にはしない」扱いで、
  /// サーバ側は purchase_recommended = false で返してくる。
  /// 補充枠の印が出ない馬でも買い目に入っていないことがあるため、
  /// その場合もはっきり分かるようにする。
  /// 買い目に入っている馬には何も出さない（表示は従来どおり）。
  Widget _buildNotRecommendedBadge(AiResponseRecommendHorseModel h) {
    // 補充枠の印を出している馬は、そちらに「買い目には入れていません」と
    // 書いてあるので二重に出さない。
    if (h.fillSlot || h.purchaseRecommended) {
      return const SizedBox.shrink();
    }
    return _buildBadge(
      '公開候補のみ / 買い目には入れていません',
      Colors.orangeAccent,
    );
  }

  /// 印の見た目をそろえるための共通部品。
  Widget _buildBadge(String label, Color color) {
    return Container(
      margin: const EdgeInsets.only(left: 6),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        border: Border.all(color: color.withValues(alpha: 0.7)),
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        label,
        style: TextStyle(color: color, fontSize: 9, fontWeight: FontWeight.bold),
      ),
    );
  }

  ///
  Widget _buildHorseCard(AiResponseRecommendHorseModel h) {
    // 2nd AI だけが選んだ馬は reason 自体が 2nd AI の文章なので、枠線ボックス側へ回す。
    // 両AIが選んだ馬は reason が 1st AI・reasonSecond が 2nd AI の文章。
    final bool isSecondAiOnly = _isSecondAiOnly(h);
    final String? secondAiReason = isSecondAiOnly
        ? h.reason
        : widget.mergedHorseList != null
        ? h.reasonSecond
        : widget.secondAiHorseList.where((AiResponseRecommendHorseModel s) => s.num == h.num).firstOrNull?.reason;

    final int? rank = widget.numToRankMap[h.num];

    return Stack(
      children: <Widget>[
        Positioned(
          right: 15,
          bottom: 10,
          child: Stack(
            children: <Widget>[
              Builder(
                builder: (BuildContext context) {
                  final double? idx = _baganrikiIndexMap[h.num];
                  String? activeLabel;
                  if (idx != null) {
                    if (idx >= 150) {
                      activeLabel = '◎有力';
                    } else if (idx >= 120) {
                      activeLabel = '○注目';
                    } else if (idx >= 100) {
                      activeLabel = '△様子見';
                    } else {
                      activeLabel = '✕妙味薄';
                    }
                  }
                  const List<(String, String)> labels = <(String, String)>[
                    ('◎有力', '150↑'),
                    ('○注目', '120↑'),
                    ('△様子見', '100↑'),
                    ('✕妙味薄', '~99'),
                  ];

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: <Widget>[
                      Stack(
                        children: <Widget>[
                          const Text('馬眼力指数', style: TextStyle(fontSize: 8, color: Colors.white)),
                          Container(
                            margin: const EdgeInsets.only(top: 10),
                            child: Transform(
                              alignment: Alignment.centerLeft,
                              transform: Matrix4.identity()..setEntry(0, 1, -0.8),
                              child: Text(
                                idx?.toStringAsFixed(1) ?? '',
                                style: const TextStyle(fontSize: 25, color: Colors.white),
                              ),
                            ),
                          ),
                        ],
                      ),

                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: labels.map(((String, String) entry) {
                          final String label = entry.$1;
                          final String range = entry.$2;
                          final bool isActive = label == activeLabel;
                          final Color col = isActive ? Colors.greenAccent : Colors.white;
                          return Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 2),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: <Widget>[
                                Text(
                                  label,
                                  style: TextStyle(
                                    fontSize: 10,
                                    color: col,
                                    fontWeight: isActive ? FontWeight.bold : FontWeight.normal,
                                  ),
                                ),
                                Text(range, style: TextStyle(fontSize: 10, color: col)),
                              ],
                            ),
                          );
                        }).toList(),
                      ),
                    ],
                  );
                },
              ),
            ],
          ),
        ),
        Container(
          margin: const EdgeInsets.symmetric(vertical: 4),
          padding: const EdgeInsets.all(5),
          decoration: BoxDecoration(
            color: Colors.black.withValues(alpha: 0.4),
            border: Border.all(color: Colors.white.withValues(alpha: 0.5)),
            borderRadius: BorderRadius.circular(6),
          ),
          child: DefaultTextStyle(
            style: const TextStyle(fontSize: 12, color: Colors.white),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Stack(
                  children: <Widget>[
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: <Widget>[
                        const SizedBox.shrink(),
                        Container(
                          decoration: BoxDecoration(
                            border: Border(bottom: BorderSide(color: Colors.orangeAccent.withValues(alpha: 0.5))),
                          ),
                          child: DefaultTextStyle(
                            style: const TextStyle(color: Colors.orangeAccent, fontSize: 10),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.baseline,
                              textBaseline: TextBaseline.alphabetic,
                              children: <Widget>[
                                const SizedBox(width: 10),
                                const Text('6分前オッズ'),
                                Container(width: 40, alignment: Alignment.topRight, child: Text(h.odds)),
                                const SizedBox(width: 10),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: <Widget>[
                        Row(
                          children: <Widget>[
                            Container(
                              decoration: BoxDecoration(
                                border: Border(bottom: BorderSide(color: Colors.greenAccent.withValues(alpha: 0.5))),
                              ),
                              child: DefaultTextStyle(
                                style: const TextStyle(color: Colors.greenAccent, fontSize: 10),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.baseline,
                                  textBaseline: TextBaseline.alphabetic,
                                  children: <Widget>[
                                    const SizedBox(width: 10),
                                    Container(width: 20, alignment: Alignment.topLeft, child: Text(h.popularity)),
                                    const Text('番人気'),
                                    const SizedBox(width: 10),
                                  ],
                                ),
                              ),
                            ),
                            // 補充枠で入った馬だけ、通常の選出と区別する印を出す
                            _buildFillSlotBadge(h),
                            // 補充枠ではないが買い目に入れていない馬にも印を出す
                            // （20261014 7回目ご指摘⑦）
                            _buildNotRecommendedBadge(h),
                          ],
                        ),
                        const SizedBox.shrink(),
                      ],
                    ),
                  ],
                ),
                DefaultTextStyle(
                  style: const TextStyle(color: Color(0xFFFBB6CE), fontSize: 12),
                  child: Stack(
                    children: <Widget>[
                      Padding(
                        padding: const EdgeInsets.only(top: 15, bottom: 10),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.baseline,
                          textBaseline: TextBaseline.alphabetic,
                          children: <Widget>[
                            Container(
                              width: 40,
                              padding: const EdgeInsets.all(2),
                              decoration: BoxDecoration(
                                border: Border.all(color: const Color(0xFFFBB6CE).withValues(alpha: 0.5)),
                              ),
                              alignment: Alignment.center,
                              child: Text(h.num.toString()),
                            ),
                            const SizedBox(width: 10),
                            Text(h.name),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                if (!isSecondAiOnly)
                  Text(
                    h.reason.replaceAll(RegExp(r'\n?[─]+\n?'), '').trim(),
                    style: const TextStyle(letterSpacing: 0.4, height: 1.7),
                  ),
                // 2nd AI が書いた選出理由は枠線で囲って出す（ラベルは付けない）
                if (secondAiReason != null && secondAiReason.isNotEmpty) ...<Widget>[
                  if (!isSecondAiOnly) const SizedBox(height: 8),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white.withValues(alpha: 0.35)),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      secondAiReason.replaceAll(RegExp(r'\n?[─]+\n?'), '').trim(),
                      style: const TextStyle(letterSpacing: 0.4, height: 1.7),
                    ),
                  ),
                ],
                const SizedBox(height: 60),
              ],
            ),
          ),
        ),
        if (rank != null && rank <= 3)
          Positioned(
            bottom: 10,
            left: 10,
            child: Container(
              width: 32,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: raceRankColor(rank, fallback: Colors.grey.withValues(alpha: 0.6)),
                borderRadius: BorderRadius.circular(4),
              ),
              child: Text('$rank位', style: const TextStyle(fontSize: 12, color: Colors.white)),
            ),
          ),
      ],
    );
  }

  ///
  Widget _buildHorseList(List<AiResponseRecommendHorseModel> displayHorses) {
    // 統合の結果、基準を満たす馬が1頭もいなかったレース（無理に候補を埋めない仕様）
    if (displayHorses.isEmpty && widget.mergedHorseList != null) {
      return const Center(
        child: Text('このレースは基準を満たす候補馬がいませんでした', style: TextStyle(fontSize: 12, color: Colors.white70)),
      );
    }
    // どちらのAIが選んだかで列を分けず、おすすめ度順のまま1本で並べる
    return ListView(children: displayHorses.map((AiResponseRecommendHorseModel h) => _buildHorseCard(h)).toList());
  }
}
