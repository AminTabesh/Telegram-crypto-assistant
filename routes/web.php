<?php

use App\Http\Controllers\OpenAIController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::post('/telegram-webhook', [TelegramController::class, 'handleWebhook']);

Route::post('/api/telegram/webhook', [TelegramController::class, 'handleWebhook']);

Route::get('/set-webhook', [TelegramController::class, 'setWebhook']);
