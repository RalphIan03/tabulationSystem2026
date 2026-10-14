<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdateAuditorPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === AccountRole::Auditor;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
