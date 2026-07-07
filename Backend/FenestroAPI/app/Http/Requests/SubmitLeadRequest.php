<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name'  => ['required', 'string', 'max:255'],
            'client_phone' => ['required', 'digits:10'],
            'type'         => ['required', 'in:B2B,B2C'],
            'notes'        => ['nullable', 'string'],
        ];
    }
}
