<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * ApiController - リファクタリング済み
 *
 * 各機能は以下のコントローラに分割されています：
 *   - AuthController     : 認証（signup / signin / verify）
 *   - RaceController     : レース基本データ（schedules / races / horses / odds / summary 等）
 *   - HistoryController  : 履歴・戦績（race history / horse records / introspection 等）
 *   - AnalysisController : 統計分析（high probability / course stats / kitaichi 等）
 *   - AiController       : AI分析（Claude 1st AI / DeepSeek 2nd AI / AI recovery 等）
 *   - AdminController    : 管理機能（users / push notifications / table count 等）
 *
 * @deprecated このクラスは空シェルです。新しいコントローラを参照してください。
 */
class ApiController extends Controller
{
}
