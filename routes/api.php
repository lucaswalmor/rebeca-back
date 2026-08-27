<?php

use App\Http\Controllers\AdminAssinantesController;
use App\Http\Controllers\AdminLiveController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AssinaturaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChamadaVideoController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatMediaPurchaseController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommentReplyController;
use App\Http\Controllers\ConteudoExclusivoController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\EvolutionWebhookController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PostCompraController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostLikeController;
use App\Http\Controllers\PresentinhoController;
use App\Http\Controllers\PurchasedGalleryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppInstanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'API is running',
    ]);
});

// Rotas de autenticação
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
Route::post('/password/change-request', [PasswordResetController::class, 'requestChange'])
    ->middleware(['auth:sanctum', 'throttle:5,1']);

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
    Route::post('/users/{id}/upload-chat-wallpaper', [UserController::class, 'uploadChatWallpaper']);
    Route::delete('/users/{id}/chat-wallpaper/{type}', [UserController::class, 'deleteChatWallpaper']);
    Route::post('/users/{id}/upload-welcome-media', [UserController::class, 'uploadWelcomeMedia']);
    Route::delete('/users/{id}/welcome-media/{type}', [UserController::class, 'deleteWelcomeMedia']);
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
        Route::post('/posts/{id}/repost', 'repost');
        Route::post('/posts/{id}/media', 'uploadMedia');
        Route::post('/posts/{id}/toggle-fixed', 'toggleFixed');
        Route::post('/posts/{id}/toggle-status', 'toggleStatus');
    });

// Compra de conteúdo avulso (agora via créditos)
Route::middleware('auth:sanctum')
    ->controller(PostCompraController::class)
    ->group(function () {
        Route::post('/posts/{id}/comprar', 'comprar');
    });

// Galeria de conteúdos comprados (posts + chat)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me/purchased-gallery', [PurchasedGalleryController::class, 'index']);
});

// Carteira de créditos
Route::middleware('auth:sanctum')
    ->controller(CreditController::class)
    ->group(function () {
        Route::get('/creditos', 'saldo');
        Route::get('/consultar-credito', 'saldo');
        Route::post('/creditos/gerar-link', 'gerarLinkRecarga');
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

// Retorno do checkout InfinitePay: precisa ser público.
// O usuário volta de um domínio externo e frequentemente perde o Bearer token
// (especialmente no mobile). A verificação usa order_nsu + payment_check da InfinitePay.
Route::post('/assinaturas/processar-checkout-success', [AssinaturaController::class, 'processarCheckoutSuccess']);

// Rotas de assinaturas (autenticadas)
Route::middleware('auth:sanctum')
    ->controller(AssinaturaController::class)
    ->group(function () {
        Route::post('/assinaturas/gerar-link-pagamento', 'gerarLinkPagamento');
        Route::post('/assinaturas/consultar-status', 'consultarStatus');
        Route::get('/assinaturas/minhas-assinaturas', 'minhasAssinaturas');
    });

// Webhook da InfinitePay (rota pública, sem autenticação)
Route::post('/webhooks/infinitepay', [AssinaturaController::class, 'webhookHandler']);

// Rota de teste (remover em produção)
Route::post('/assinaturas/testar-api', [AssinaturaController::class, 'testarApiInfinitePay']);
Route::post('/assinaturas/debug', [AssinaturaController::class, 'debugDados']);

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->controller(AdminAssinantesController::class)
    ->group(function () {
        Route::get('/assinantes', 'index');
        Route::post('/assinantes/{id}/revogar', 'revogar');
        Route::post('/assinantes/{id}/bloquear', 'bloquear');
        Route::post('/assinantes/{id}/desbloquear', 'desbloquear');
        Route::post('/assinantes/{id}/bloquear-chat', 'bloquearChat');
        Route::post('/assinantes/{id}/desbloquear-chat', 'desbloquearChat');
        Route::post('/assinantes/{id}/creditar', 'creditar');
        Route::delete('/assinantes/{id}', 'destroy');
    });

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->controller(AiChatController::class)
    ->group(function () {
        Route::get('/ai-chat', 'show');
        Route::put('/ai-chat', 'update');
        Route::get('/ai-chat/credits', 'credits');
        Route::post('/ai-chat/conversations/{id}/toggle', 'toggleConversation');
    });

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->controller(WhatsAppInstanceController::class)
    ->group(function () {
        Route::get('/whatsapp', 'show');
        Route::post('/whatsapp/conectar', 'connect');
        Route::get('/whatsapp/status', 'status');
        Route::put('/whatsapp/notify-number', 'updateNotifyNumber');
        Route::post('/whatsapp/desconectar', 'disconnect');
        Route::delete('/whatsapp', 'destroy');
    });

Route::post('/webhooks/evolution', EvolutionWebhookController::class);

Route::middleware('auth:sanctum')
    ->prefix('admin')
    ->controller(AdminLiveController::class)
    ->group(function () {
        Route::get('/lives/current', 'current');
        Route::post('/lives', 'store');
        Route::post('/lives/{id}/notify', 'notify');
        Route::post('/lives/{id}/start', 'start');
        Route::post('/lives/{id}/end', 'end');
        Route::post('/lives/{id}/toggle-chat', 'toggleChat');
        Route::post('/lives/{id}/goals', 'storeGoal');
        Route::post('/lives/{id}/goals/{goalId}', 'updateGoal');
        Route::post('/lives/{id}/goals/{goalId}/delete', 'destroyGoal');
        Route::get('/lives/{id}/participants', 'participants');
        Route::post('/lives/{id}/participants/{userId}/mute-chat', 'muteChatUser');
        Route::post('/lives/{id}/participants/{userId}/kick', 'kick');
        Route::post('/lives/{id}/participants/{userId}/moderator', 'setModerator');
    });

Route::middleware('auth:sanctum')
    ->controller(LiveController::class)
    ->group(function () {
        Route::get('/lives/{uuid}', 'show');
        Route::post('/lives/{uuid}/join', 'join');
        Route::post('/lives/{uuid}/token', 'token');
        Route::post('/lives/{uuid}/donate', 'donate');
    });

// Broadcasting auth (Sanctum Bearer)
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

// Chat em tempo real
Route::middleware('auth:sanctum')
    ->controller(ChatController::class)
    ->prefix('chat')
    ->group(function () {
        Route::post('/heartbeat', 'heartbeat');
        Route::get('/unread-count', 'unreadCount');
        Route::get('/media-package', 'mediaPackageInfo');
        Route::get('/conversations', 'index');
        Route::get('/conversations/export', 'export');
        Route::post('/conversations/open', 'openOrCreate');
        Route::post('/conversations/start', 'startWithSubscriber');
        Route::post('/broadcast', 'broadcast');
        Route::get('/users', 'searchableUsers');
        Route::post('/conversations/clear-all', 'clearAll');
        Route::get('/conversations/{id}', 'show');
        Route::get('/conversations/{id}/messages', 'messages');
        Route::get('/conversations/{id}/gallery', 'gallery');
        Route::post('/conversations/{id}/messages', 'store');
        Route::post('/conversations/{id}/pix-key', 'sendPixKey');
        Route::post('/conversations/{id}/read', 'markRead');
        Route::post('/conversations/{id}/clear', 'clear');
        Route::post('/conversations/{id}/bloquear-chat', 'bloquearChat');
        Route::post('/conversations/{id}/desbloquear-chat', 'desbloquearChat');
        Route::put('/messages/{messageId}', 'update');
        Route::delete('/messages/{messageId}', 'destroy');
        Route::post('/messages/{messageId}/like', 'toggleLike');
        Route::post('/messages/{messageId}/unlock', 'unlockMessage');
    });

Route::middleware('auth:sanctum')
    ->controller(ChatMediaPurchaseController::class)
    ->group(function () {
        Route::post('/chat/media-package/gerar-link', 'gerarLink');
    });

Route::middleware('auth:sanctum')
    ->controller(ChamadaVideoController::class)
    ->group(function () {
        Route::post('/chat/conversations/{id}/video-calls', 'store');
    });

Route::middleware('auth:sanctum')
    ->controller(PresentinhoController::class)
    ->group(function () {
        Route::post('/chat/conversations/{id}/presentinhos', 'store');
        Route::post('/chat/conversations/{id}/presentinho-offers', 'offer');
    });

Route::middleware('auth:sanctum')
    ->controller(ConteudoExclusivoController::class)
    ->group(function () {
        Route::post('/chat/conversations/{id}/conteudo-exclusivo', 'store');
    });
