<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CompleteCredentialSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->must_change_credentials === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::notIn([$this->user()?->username]),
                Rule::unique('users', 'username')->ignore($this->user()?->getKey()),
            ],
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['bail', 'required', 'string', 'different:current_password', 'confirmed', Password::min(8)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.not_in' => 'Choose a username different from the one assigned to you.',
            'password.different' => 'Choose a password different from your current password.',
        ];
    }
}
