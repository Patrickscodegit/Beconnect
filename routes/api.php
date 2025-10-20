<?php

use App\Http\Controllers\Api\RobawsWebhookController;
use App\Http\Controllers\IntakeStatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Intake status API
Route::get('/intakes/{intake}/status', [IntakeStatusController::class, 'show'])->name('intakes.status');

// Robaws Webhooks (no auth - verified by signature)
Route::post('/webhooks/robaws/articles', [RobawsWebhookController::class, 'handleArticle'])
    ->middleware('throttle:60,1') // 60 requests per minute
    ->name('webhooks.robaws.articles');
