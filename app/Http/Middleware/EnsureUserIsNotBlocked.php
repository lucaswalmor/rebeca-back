<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: Auth::guard('sanctum')->user();

        if ($user && $user->is_blocked) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Sua conta está bloqueada.',
                'blocked' => true,
            ], 403);
        }

        return $next($request);
    }
}
