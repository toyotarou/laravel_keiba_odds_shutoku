// 20261116 なる11/16 仕上げ①の確認：合致数の表示文（AI予想画面・過去レース画面で共通の buildMatchResultText）を3例で確かめる。
// 試験のあとで消してよいファイル（宿題フォルダの試験用からコピーして使う）。
import 'package:flutter_test/flutter_test.dart';
import 'package:keiba_odds_finder/models/common/ai_response_recommend_horse_model.dart';
import 'package:keiba_odds_finder/utility/functions.dart';

AiResponseRecommendHorseModel h(int n, {bool fill = false, bool metrics = false}) => AiResponseRecommendHorseModel(
  num: n, name: '', popularity: '', odds: '', score: 0, reason: '', fillSlot: fill, metricsSlot: metrics);

void main() {
  // 3着以内：1番・4番・6番
  final Set<int> hit = <int>{1, 4, 6};
  test('通常だけ（指標反映の馬は通常として数える）', () {
    final String t = buildMatchResultText(<AiResponseRecommendHorseModel>[h(1), h(2), h(3), h(4, metrics: true), h(5)], hit);
    print('通常だけ　　　： $t');
    expect(t, '5頭中2頭が合致');
  });
  test('通常と補充の混在', () {
    final String t = buildMatchResultText(<AiResponseRecommendHorseModel>[h(1), h(2), h(3), h(4, metrics: true), h(5), h(6, fill: true), h(7, fill: true)], hit);
    print('混在　　　　　： $t');
    expect(t, '全体（補充含む）7頭中3頭が合致（通常5頭中2頭・補充2頭中1頭）');
  });
  test('全頭が補充', () {
    final String t = buildMatchResultText(<AiResponseRecommendHorseModel>[h(1, fill: true), h(2, fill: true), h(6, fill: true)], hit);
    print('全頭が補充　　： $t');
    expect(t, '参考表示 3頭中2頭が合致（全頭が補充）');
  });
}
