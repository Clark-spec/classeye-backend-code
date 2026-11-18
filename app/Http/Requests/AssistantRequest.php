<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The length cap is configuration-driven so a deployment can tighten it
     * without a code change.
     */
    public function rules(): array
    {
        $max = max(20, (int) config('ai.question_max_length', 500));

        return [
            'question' => ['required', 'string', 'min:3', "max:{$max}"],
            'history' => ['sometimes', 'array', 'max:14'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ];
    }
}
