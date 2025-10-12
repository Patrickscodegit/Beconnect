<?php

use App\Http\Controllers\IntakeStatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Intake status API
Route::get('/intakes/{intake}/status', [IntakeStatusController::class, 'show'])->name('intakes.status');

// Robaws Webhook (no auth - verified by signature)
Route::post('/webhooks/robaws', [\App\Http\Controllers\RobawsWebhookController::class, 'handle'])
    ->name('webhooks.robaws');
