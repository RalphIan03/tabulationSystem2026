<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventDetailsRequest extends FormRequest
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
            'event_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The event end date must be on or after the start date.',
        ];
    }
}
