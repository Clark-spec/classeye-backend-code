<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Standard Laravel validation => automatic 422 JSON response with a
     * field-keyed "errors" object that the React form can display.
     */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
