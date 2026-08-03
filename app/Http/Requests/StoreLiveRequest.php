<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('instant')) {
            return;
        }

        // Live instantânea: título/horário genéricos se o admin não enviou
        $this->merge([
            'instant' => true,
            'titulo' => filled($this->input('titulo'))
                ? $this->input('titulo')
                : ('Live ao vivo — '.now()->timezone(config('app.timezone'))->format('d/m/Y H:i')),
            'descricao' => $this->has('descricao')
                ? $this->input('descricao')
                : 'Live iniciada agora.',
            'starts_at' => $this->input('starts_at') ?: now()->toIso8601String(),
        ]);
    }

    public function rules(): array
    {
        return [
            'titulo' => [
                Rule::requiredIf(fn () => ! $this->boolean('instant')),
                'nullable',
                'string',
                'max:200',
            ],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'instant' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'is_private' => ['sometimes', 'boolean'],
            'price_credits' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'max_participants' => ['required', 'integer', 'min:1', 'max:1000'],
            'invite_ids' => ['nullable', 'array'],
            'invite_ids.*' => ['integer', 'exists:users,id'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $instant = $this->boolean('instant');
            $startsAt = $this->input('starts_at');

            if (! $instant && blank($startsAt)) {
                $validator->errors()->add('starts_at', 'Informe a data/hora ou marque como live instantânea.');
            }

            if (! $instant && $startsAt && strtotime((string) $startsAt) < now()->subMinute()->timestamp) {
                $validator->errors()->add('starts_at', 'A live agendada deve ser no futuro.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'O título é obrigatório.',
            'max_participants.required' => 'Informe o limite de participantes.',
        ];
    }
}
