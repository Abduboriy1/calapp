<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\GoogleOAuthController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::patch('/todos/{todo}', [TodoController::class, 'update']);
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);

    Route::get('/integrations/google/access-token', [GoogleOAuthController::class, 'accessToken']);
    Route::post('/integrations/google/revoke', [GoogleOAuthController::class, 'revoke']);
});
