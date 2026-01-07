<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $postId = $this->route('id');
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $post = \App\Models\Post::find($postId);

        return $post && $post->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_post' => 'sometimes|integer|in:1,2',
            'description' => 'sometimes|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_post.integer' => 'O tipo do post deve ser um número inteiro.',
            'tipo_post.in' => 'O tipo do post deve ser 1 (simples) ou 2 (exclusivo).',
        ];
    }
}
