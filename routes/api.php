<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rotas de autenticação
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rota pública para buscar usuário por apelido
Route::get('/users/apelido/{apelido}', [UserController::class, 'findByApelido']);

// Rotas de usuários
Route::middleware('auth:sanctum')
    ->controller(UserController::class)
    ->group(function () {
        Route::get('/users', 'index');
        Route::post('/users', 'store');
        Route::get('/users/{id}', 'show');
        Route::put('/users/{id}', 'update');
        Route::patch('/users/{id}', 'update');
        Route::delete('/users/{id}', 'destroy');
        Route::post('/users/{id}/upload-banner', 'uploadBanner');
        Route::post('/users/{id}/upload-avatar', 'uploadAvatar');
    });
