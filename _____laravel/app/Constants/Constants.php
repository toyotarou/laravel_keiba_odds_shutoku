<?php

namespace App\Constants;

class Constants
{
    const ODDS_GET_TIMING = [30, 21, 18, 15, 12, 9, 6, 3, 0];

    const ODDS_DB_FIRST = 999;   // diff=30（発走30分前ベースオッズ）のDB格納値
    const ODDS_DB_LAST  = -999;  // diff=0（発走直前確定オッズ）のDB格納値
}
