<?php

use App\Http\Controllers\AssinaturaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommentReplyController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostLikeController;
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
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/upload-banner', [UserController::class, 'uploadBanner']);
    Route::post('/users/{id}/upload-avatar', [UserController::class, 'uploadAvatar']);
});

// Rotas de posts (públicas para leitura - apenas posts ativos)
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{id}', [PostController::class, 'show']);
Route::get('/posts/{id}/like-status', [PostController::class, 'getLikeStatus']);

// Rotas de posts (autenticadas)
Route::middleware('auth:sanctum')
    ->controller(PostController::class)
    ->group(function () {
        Route::get('/posts/admin/all', 'indexAdmin'); // Rota para admins verem todos os posts (ativos e inativos)
        Route::post('/posts', 'store');
        Route::put('/posts/{id}', 'update');
        Route::patch('/posts/{id}', 'update');
        Route::delete('/posts/{id}', 'destroy');
        Route::post('/posts/{id}/media', 'uploadMedia');
        Route::post('/posts/{id}/toggle-fixed', 'toggleFixed');
        Route::post('/posts/{id}/toggle-status', 'toggleStatus');
    });

// Rotas de likes
Route::middleware('auth:sanctum')
    ->controller(PostLikeController::class)
    ->group(function () {
        Route::post('/posts/{id}/like', 'toggle');
    });

// Rotas de comentários
Route::middleware('auth:sanctum')
    ->controller(CommentController::class)
    ->group(function () {
        Route::get('/posts/{postId}/comments', 'index');
        Route::post('/posts/{postId}/comments', 'store');
        Route::delete('/comments/{id}', 'destroy');
    });

// Rotas de respostas de comentários
Route::middleware('auth:sanctum')
    ->controller(CommentReplyController::class)
    ->group(function () {
        Route::post('/comments/{commentId}/replies', 'store');
        Route::delete('/comment-replies/{id}', 'destroy');
    });

// Rotas de assinaturas
Route::middleware('auth:sanctum')
    ->controller(AssinaturaController::class)
    ->group(function () {
        Route::post('/assinaturas/gerar-link-pagamento', 'gerarLinkPagamento');
        Route::post('/assinaturas/consultar-status', 'consultarStatus');
        Route::post('/assinaturas/processar-checkout-success', 'processarCheckoutSuccess');
        Route::get('/assinaturas/minhas-assinaturas', 'minhasAssinaturas');
    });

// Webhook da InfinitePay (rota pública, sem autenticação)
Route::post('/webhooks/infinitepay', [AssinaturaController::class, 'webhookHandler']);

// Rota de teste (remover em produção)
Route::post('/assinaturas/testar-api', [AssinaturaController::class, 'testarApiInfinitePay']);
Route::post('/assinaturas/debug', [AssinaturaController::class, 'debugDados']);
