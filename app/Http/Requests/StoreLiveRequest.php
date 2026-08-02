<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date', 'after:now'],
            'is_private' => ['sometimes', 'boolean'],
            'price_credits' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'max_participants' => ['required', 'integer', 'min:1', 'max:1000'],
            'invite_ids' => ['nullable', 'array'],
            'invite_ids.*' => ['integer', 'exists:users,id'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'O título é obrigatório.',
            'starts_at.required' => 'A data/hora de início é obrigatória.',
            'starts_at.after' => 'A live deve ser agendada para o futuro.',
            'max_participants.required' => 'Informe o limite de participantes.',
        ];
    }
}
