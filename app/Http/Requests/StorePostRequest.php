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
            'description' => 'required|string',
            'preco' => 'nullable|numeric|min:0',
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
            'description.required' => 'A descrição do post é obrigatória.',
            'preco.numeric' => 'O preço deve ser um número.',
            'preco.min' => 'O preço não pode ser negativo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->preco === null || $this->preco === '') {
            $this->merge(['preco' => 0]);
        }
    }
}
