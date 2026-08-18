<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiChatSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'scope' => ['required', 'in:selected,all'],
            'system_prompt' => ['nullable', 'string', 'max:50000'],
            'reply_delay_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'takeover_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    public function messages(): array
    {
        return [
            'system_prompt.max' => 'O prompt pode ter no máximo 50.000 caracteres.',
            'reply_delay_minutes.min' => 'O delay de resposta não pode ser negativo.',
            'takeover_minutes.min' => 'O tempo para a I.A reassumir deve ser de pelo menos 1 minuto.',
        ];
    }
}
