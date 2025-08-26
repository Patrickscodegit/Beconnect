<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\RobawsOfferController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // File management routes
    Route::post('/upload/csv', [FileController::class, 'uploadCsv'])->name('upload.csv');
    Route::post('/upload/vehicle-image', [FileController::class, 'uploadVehicleImage'])->name('upload.vehicle-image');
    Route::post('/upload/vehicle-document', [FileController::class, 'uploadVehicleDocument'])->name('upload.vehicle-document');
    Route::get('/files', [FileController::class, 'listFiles'])->name('files.list');
    Route::delete('/files', [FileController::class, 'deleteFile'])->name('files.delete');
    
    // Results and party assignment routes
    Route::get('/intakes/{intake}/results', [ResultsController::class, 'show'])->name('intakes.results');
    Route::post('/intakes/{intake}/parties/assign', [ResultsController::class, 'assignPartyRole'])->name('intakes.parties.assign');
    Route::post('/intakes/{intake}/push-robaws', [ResultsController::class, 'pushRobaws'])->name('intakes.push-robaws');
    
    // Robaws integration routes
    Route::post('/robaws/offers', [RobawsOfferController::class, 'store'])
        ->name('robaws.offers.store');
    Route::post('/documents/{document}/robaws-offer', [RobawsOfferController::class, 'createFromDocument'])
        ->name('documents.robaws-offer');
});

// Webhook endpoint (no auth required)
Route::post('/webhooks/robaws', [RobawsOfferController::class, 'webhook'])
    ->name('webhooks.robaws');

require __DIR__.'/auth.php';
