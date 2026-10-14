<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAuditorDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === AccountRole::Auditor;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($this->user()?->getKey()),
            ],
        ];
    }
}
