<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\gatewayController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::controller(gatewayController::class)->group(function () {
    Route::post('/v2/external/log', 'log');
    Route::post('/v2/external/webhook', 'webhook');
    Route::get('/v2/external/webhook', 'webhook');
    Route::post('/v2/external/send_message', 'sendMessage');
});
