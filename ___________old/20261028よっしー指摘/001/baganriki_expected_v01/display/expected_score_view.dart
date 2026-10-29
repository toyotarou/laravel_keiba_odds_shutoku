/// Numeric display contract. Uses no packages; Flutter renders the returned text.
class ExpectedScoreView {
  static const displayVersion = 'baganriki-expected-display-v0.1.2';
  static const formulaVersion = 'baganriki-expected-v0.1-draft-20261025';
  static const implementationVersion = 'baganriki-expected-impl-v0.1.4';
  final int horseNumber;
  final int popularityRank;
  final double? expectationValue;
  final double? top3ExpectationValue;
  final String expectationText;
  final String top3Text;
  final String supportStatus;
  final String scoreBasis;

  const ExpectedScoreView._({
    required this.horseNumber,
    required this.popularityRank,
    required this.expectationValue,
    required this.top3ExpectationValue,
    required this.expectationText,
    required this.top3Text,
    required this.supportStatus,
    required this.scoreBasis,
  });

  factory ExpectedScoreView.unavailable(int horseNumber, int popularityRank, {bool withdrawn = false}) {
    return ExpectedScoreView._(
      horseNumber: horseNumber, popularityRank: popularityRank,
      expectationValue: null, top3ExpectationValue: null,
      expectationText: '—', top3Text: '—',
      supportStatus: withdrawn ? 'WITHDRAWN' : 'INVALID_RESPONSE', scoreBasis: 'UNAVAILABLE',
    );
  }

  static bool _validFrozenAt(String value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+09:00$')
        .firstMatch(value);
    if (match == null || match.end != value.length) return false;
    final parts = List<int>.generate(6, (i) => int.parse(match.group(i + 1)!));
    if (parts[0] < 1 || parts[1] < 1 || parts[1] > 12 || parts[2] < 1 ||
        parts[3] > 23 || parts[4] > 59 || parts[5] > 59) return false;
    final day = DateTime.utc(parts[0], parts[1], parts[2]);
    return day.year == parts[0] && day.month == parts[1] && day.day == parts[2];
  }

  static double? _score(Map<String, dynamic> json, String key, String textKey) {
    if (!json.containsKey(key) || !json.containsKey(textKey)) {
      throw FormatException('Missing score field: ' + key);
    }
    final value = json[key];
    final text = json[textKey];
    if (value == null) {
      if (text != '—') throw const FormatException('Null score must display an em dash.');
      return null;
    }
    if (value is! num || !value.isFinite || value < 0 || value > 100) {
      throw const FormatException('Score must be numeric and within 0..100.');
    }
    final score = value.toDouble();
    if ((score * 10 - (score * 10).round()).abs() > 1e-7 ||
        text != score.toStringAsFixed(1)) {
      throw const FormatException('Score text or decimal precision is inconsistent.');
    }
    return score;
  }

  factory ExpectedScoreView.fromJson(
    Map<String, dynamic> json, {
    required String expectedRaceId,
    required String expectedHorseId,
    required String expectedSnapshotId,
    required int expectedHorseNumber,
    required int expectedPopularityRank,
    required String expectedFrozenAt,
    required String expectedRunnerStatus,
  }) {
    if ([expectedRaceId, expectedHorseId, expectedSnapshotId]
        .any((v) => v.trim().isEmpty || v.trim() != v) ||
        !_validFrozenAt(expectedFrozenAt) ||
        !['ACTIVE', 'WITHDRAWN'].contains(expectedRunnerStatus) ||
        json['frozen_at'] != expectedFrozenAt ||
        json['runner_status'] != expectedRunnerStatus ||
        json['implementation_version'] != implementationVersion ||
        json['display_schema_version'] != displayVersion ||
        json['formula_version'] != formulaVersion ||
        json['comparison_only'] != true || json['validated_for_live'] != false ||
        json['main_target'] != 'TOP5' || json['unit'] != 'score_0_100' ||
        json['source_minutes_before'] != 6 ||
        json['race_id'] != expectedRaceId ||
        json['horse_id'] != expectedHorseId ||
        json['snapshot_id'] != expectedSnapshotId ||
        json['horse_number'] is! int || json['popularity_rank'] is! int ||
        json['horse_number'] != expectedHorseNumber ||
        json['popularity_rank'] != expectedPopularityRank ||
        expectedHorseNumber < 1 || expectedHorseNumber > 99 ||
        expectedPopularityRank < 1 || expectedPopularityRank > 99 ||
        json['source_minutes_before'] is! int) {
      throw const FormatException('Snapshot identity or display contract mismatch.');
    }
    final top5 = _score(json, 'expectation_value', 'expectation_value_text');
    final top3 = _score(json, 'top3_expectation_value', 'top3_expectation_value_text');
    final status = json['support_status'];
    final basis = json['score_basis'];
    const available = ['COMPARISON_READY', 'LOW_SUPPORT', 'BASELINE_ONLY'];
    const unavailable = ['UNAVAILABLE_INPUT', 'UNAVAILABLE_BASELINE', 'OUT_OF_SCOPE', 'CALCULATION_ERROR', 'WITHDRAWN'];
    if ((status == 'WITHDRAWN') != (expectedRunnerStatus == 'WITHDRAWN')) {
      throw const FormatException('Cancellation state mismatch.');
    }
    if (top3 == null || top5 == null) {
      if (top3 != null || top5 != null || !unavailable.contains(status) || basis != 'UNAVAILABLE') {
        throw const FormatException('Inconsistent unavailable result.');
      }
    } else if (top3 > top5 || !available.contains(status) ||
        !['RAW_UNCALIBRATED', 'CALIBRATION_APPLIED'].contains(basis) ||
        (status == 'BASELINE_ONLY' && basis != 'RAW_UNCALIBRATED')) {
      throw const FormatException('Inconsistent available result.');
    }
    return ExpectedScoreView._(
      horseNumber: expectedHorseNumber, popularityRank: expectedPopularityRank,
      expectationValue: top5, top3ExpectationValue: top3,
      expectationText: json['expectation_value_text'] as String,
      top3Text: json['top3_expectation_value_text'] as String,
      supportStatus: status as String, scoreBasis: basis as String,
    );
  }

  String get statusText {
    if (supportStatus == 'WITHDRAWN') return '取消';
    if (expectationValue == null) return '未算出';
    final prefix = scoreBasis == 'RAW_UNCALIBRATED' ? '未校正' : '校正適用';
    if (supportStatus == 'BASELINE_ONLY') return prefix + '・基準値のみ';
    if (supportStatus == 'LOW_SUPPORT') return prefix + '・参考値（少数例）';
    return prefix;
  }
}

