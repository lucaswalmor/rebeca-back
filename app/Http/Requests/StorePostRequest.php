<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_post' => 'required|integer|in:1,2',
            'description' => 'required|string',
            'status' => 'sometimes|string|in:ativo,inativo',
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
            'tipo_post.required' => 'O tipo do post é obrigatório.',
            'tipo_post.integer' => 'O tipo do post deve ser um número inteiro.',
            'tipo_post.in' => 'O tipo do post deve ser 1 (simples) ou 2 (exclusivo).',
            'description.required' => 'A descrição do post é obrigatória.',
        ];
    }
}
