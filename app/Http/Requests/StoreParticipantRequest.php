<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $event = $this->route('event');

        return [
            'participant_name' => ['required', 'string', 'max:255'],
            'participant_reference' => ['nullable', 'string', 'max:100'],
            'participant_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
            'parent_participant_id' => ['nullable', 'integer', Rule::exists('participants', 'id')
                ->where('event_id', $event?->getKey())->whereNull('parent_participant_id')],
        ];
    }
}
