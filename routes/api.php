<?php

use App\Http\Controllers\Api\ConversionApiController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [ConversionApiController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/company', [ConversionApiController::class, 'company']);
    Route::post('/gp/test', [ConversionApiController::class, 'testGp']);
    Route::post('/odoo/test', [ConversionApiController::class, 'testOdoo']);
    Route::post('/preflight', [ConversionApiController::class, 'preflight']);
    Route::get('/steps', [ConversionApiController::class, 'steps']);
    Route::post('/steps/{key}/confirm', [ConversionApiController::class, 'confirm']);
});
