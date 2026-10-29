#!/usr/bin/env bash
set -euo pipefail
baganriki_check_dir="$(cd -- "$(dirname -- "$0")" && pwd)"
cd -- "$baganriki_check_dir"
if ! command -v php >/dev/null 2>&1; then
  echo 'PHP is required. No PHP checks have run.'
  exit 2
fi
php verify.php
php display/verify_display.php
php audit/verify_weak_types.php
if ! command -v dart >/dev/null 2>&1; then
  echo 'PHP checks passed, but Dart checks are NOT RUN. Overall verification is incomplete.'
  exit 2
fi
dart run display/verify_display.dart
echo 'PHP and Dart reference checks passed. Laravel integration and Flutter UI still require project tests.'

