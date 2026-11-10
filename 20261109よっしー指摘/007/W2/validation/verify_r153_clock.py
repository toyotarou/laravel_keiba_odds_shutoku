"""Compare the PHP receipt decision with an independent integer-microsecond rule."""
import argparse
from datetime import datetime, timedelta, timezone
import itertools
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
require $argv[1].'/php/ExpectedBridgeW2.php';
use App\Services\Baganriki\AttemptWindowW2;
use App\Services\Baganriki\ExpectedPacketW2;
while (($line=fgets(STDIN))!==false) {
    $times=array_map([ExpectedPacketW2::class,'instant'],json_decode($line,true,512,JSON_THROW_ON_ERROR));
    echo AttemptWindowW2::receiveTimeIssue(...$times)===null ? "1\n" : "0\n";
}
"""


def run(php):
    base = datetime(2026, 10, 17, 5, 54, 30, tzinfo=timezone.utc)
    zones = [timezone.utc, timezone(timedelta(hours=9))]
    offsets = [-1_000_000, -1, 0, 1, 1_000_000]
    rows = []
    expected = []
    for case, values in enumerate(itertools.product(offsets, repeat=5)):
        sent, got, ready, clock, deadline = values
        expected.append("1" if ready <= sent <= got <= min(clock, deadline) else "0")
        stamps = [(base + timedelta(microseconds=v)).astimezone(zones[(case + i) % 2])
                  for i, v in enumerate(values)]
        rows.append(json.dumps([t.isoformat(timespec="microseconds") for t in stamps]))
    result = subprocess.run([php, "-r", PHP, str(ROOT)], input="\n".join(rows) + "\n",
                            text=True, capture_output=True, check=True)
    observed = result.stdout.splitlines()
    if result.stderr or len(observed) != len(expected):
        raise RuntimeError(result.stderr or "incomplete PHP output")
    for row, want, actual in zip(rows, expected, observed):
        if want != actual:
            raise ValueError(f"{row} expected {want} got {actual}")
    print(f"PASS {len(expected)} independent clock cases, {expected.count('1')} accepted, "
          f"{expected.count('0')} rejected (UTC/JST).")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    run(parser.parse_args().php)
