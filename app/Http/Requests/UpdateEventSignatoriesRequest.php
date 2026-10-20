<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventSignatoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true
            && $this->route('event') instanceof Event;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'report_signatories' => ['nullable', 'string', 'max:2000'],
            'include_team_signatories' => ['required', 'boolean'],
        ];
    }
}
