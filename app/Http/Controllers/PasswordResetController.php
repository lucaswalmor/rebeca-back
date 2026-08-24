<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\PasswordChangedMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::query()->where('email', $email)->first();

        if ($user && ! $user->is_blocked) {
            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'message' => 'Aguarde um minuto antes de solicitar outro e-mail.',
                ], 429);
            }
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá as instruções.',
        ]);
    }

    public function requestChange(Request $request): JsonResponse
    {
        $user = $request->user();

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Aguarde um minuto antes de solicitar outro e-mail.',
            ], 429);
        }

        return response()->json([
            'message' => 'Enviamos um e-mail com o link para trocar sua senha.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                ])->save();

                $user->tokens()->delete();

                Mail::to($user->email)->send(new PasswordChangedMail($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Este link de redefinição é inválido ou expirou.'],
            ]);
        }

        return response()->json([
            'message' => 'Senha redefinida com sucesso. Faça login com a nova senha.',
        ]);
    }
}
