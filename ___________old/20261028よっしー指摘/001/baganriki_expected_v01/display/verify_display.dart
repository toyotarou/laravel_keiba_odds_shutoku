import 'dart:convert';
import 'dart:io';
import 'expected_score_view.dart';

/// Run from the ZIP root: dart run display/verify_display.dart
void main() {
  final fixture = File.fromUri(Platform.script.resolve('display_test_vectors.json'));
  final document = jsonDecode(fixture.readAsStringSync()) as Map<String, dynamic>;
  var passed = 0;
  var failed = 0;
  for (final raw in document['client_cases'] as List<dynamic>) {
    final test = raw as Map<String, dynamic>;
    final identity = test['expected_identity'] as Map<String, dynamic>;
    var accepted = false;
    try {
      final view = ExpectedScoreView.fromJson(
        test['payload'] as Map<String, dynamic>,
        expectedRaceId: identity['race_id'] as String,
        expectedHorseId: identity['horse_id'] as String,
        expectedSnapshotId: identity['snapshot_id'] as String,
        expectedHorseNumber: identity['horse_number'] as int,
        expectedPopularityRank: identity['popularity_rank'] as int,
        expectedFrozenAt: identity['frozen_at'] as String,
        expectedRunnerStatus: identity['runner_status'] as String,
      );
      // Valid zero must stay visible; null must stay a dash.
      if (view.expectationValue == null && view.expectationText != '—') {
        throw StateError('Null score was not displayed as a dash.');
      }
      if (view.expectationValue == 0 && view.expectationText != '0.0') {
        throw StateError('Zero score was hidden or incorrectly formatted.');
      }
      accepted = true;
    } on FormatException {
      accepted = false;
    }
    if (accepted == test['expected_accept']) {
      passed++;
      stdout.writeln('PASS ' + (test['id'] as String));
    } else {
      failed++;
      stdout.writeln('FAIL ' + (test['id'] as String));
    }
  }
  stdout.writeln(passed.toString() + ' passed; ' + failed.toString() + ' failed.');
  exitCode = failed == 0 ? 0 : 1;
}

