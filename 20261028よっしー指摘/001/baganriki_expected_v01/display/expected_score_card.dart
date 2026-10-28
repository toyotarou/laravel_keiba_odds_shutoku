import 'package:flutter/material.dart';
import 'expected_score_view.dart';

/// Add to the comparison view; no network request or model recalculation occurs.
class ExpectedScoreCard extends StatelessWidget {
  final ExpectedScoreView data;
  final bool isWithdrawn;
  const ExpectedScoreCard({Key? key, required this.data, required this.isWithdrawn}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    // Use the latest cancellation state even when a previous response is still cached.
    final visible = isWithdrawn
        ? ExpectedScoreView.unavailable(data.horseNumber, data.popularityRank, withdrawn: true)
        : data;
    return SizedBox(
      width: 156,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(visible.popularityRank.toString() + '番人気'),
              Text('馬番 ' + visible.horseNumber.toString()),
              const SizedBox(height: 8),
              const Text('期待数値（5着内）', textAlign: TextAlign.center),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  visible.expectationText,
                  maxLines: 1,
                  style: const TextStyle(fontSize: 30, fontWeight: FontWeight.bold),
                ),
              ),
              Text('3着内 ' + visible.top3Text),
              const SizedBox(height: 8),
              Text(visible.statusText, textAlign: TextAlign.center),
              const Text('比較用・6分前基準', textAlign: TextAlign.center),
            ],
          ),
        ),
      ),
    );
  }
}

