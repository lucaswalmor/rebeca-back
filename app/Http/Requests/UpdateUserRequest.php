<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'nome' => 'sometimes|string|max:255',
            'sobrenome' => 'sometimes|string|max:255',
            'apelido' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => 'sometimes|string|min:6',
            'telefone' => 'sometimes|string|max:20',
            'data_nascimento' => 'sometimes|date',
            'is_admin' => 'sometimes|boolean',
            'instagram' => 'nullable|string|max:255',
            'telegram' => 'nullable|string|max:255',
            'whatsapp' => 'nullable|string|max:255',
            'x_twitter' => 'nullable|string|max:255',
            'tiktok' => 'nullable|string|max:255',
            'facebook' => 'nullable|string|max:255',
            'privacy' => 'nullable|string',
            'sobre' => 'nullable|string',
            'path_img_banner' => 'nullable|string|max:255',
            'path_img_avatar' => 'nullable|string|max:255',
            'valor_assinatura_mensal' => 'nullable|numeric|min:0',
            'valor_assinatura_trimestral' => 'nullable|numeric|min:0',
            'valor_assinatura_semestral' => 'nullable|numeric|min:0',
            'valor_desconto_trimestral' => 'nullable|numeric|min:0|max:100',
            'valor_desconto_semestral' => 'nullable|numeric|min:0|max:100',
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
            'email.email' => 'O email deve ser um endereço válido.',
            'email.unique' => 'Este email já está cadastrado.',
            'password.min' => 'A senha deve ter no mínimo 6 caracteres.',
            'data_nascimento.date' => 'A data de nascimento deve ser uma data válida.',
            'valor_assinatura_mensal.numeric' => 'O valor da assinatura mensal deve ser um número.',
            'valor_assinatura_mensal.min' => 'O valor da assinatura mensal não pode ser negativo.',
            'valor_assinatura_trimestral.numeric' => 'O valor da assinatura trimestral deve ser um número.',
            'valor_assinatura_trimestral.min' => 'O valor da assinatura trimestral não pode ser negativo.',
            'valor_assinatura_semestral.numeric' => 'O valor da assinatura semestral deve ser um número.',
            'valor_assinatura_semestral.min' => 'O valor da assinatura semestral não pode ser negativo.',
            'valor_desconto_trimestral.numeric' => 'O valor do desconto trimestral deve ser um número.',
            'valor_desconto_trimestral.min' => 'O valor do desconto trimestral não pode ser negativo.',
            'valor_desconto_trimestral.max' => 'O valor do desconto trimestral não pode ser maior que 100%.',
            'valor_desconto_semestral.numeric' => 'O valor do desconto semestral deve ser um número.',
            'valor_desconto_semestral.min' => 'O valor do desconto semestral não pode ser negativo.',
            'valor_desconto_semestral.max' => 'O valor do desconto semestral não pode ser maior que 100%.',
        ];
    }
}
