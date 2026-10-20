<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BatchExcludeLinkedContestantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true
            && $this->route('event') instanceof Event;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $event = $this->route('event');

        return [
            'competition_ids' => ['required', 'array', 'min:1'],
            'competition_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Competition::class, 'id')
                    ->whereIn('category_id', $event->categories()->pluck('id')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'competition_ids.required' => 'Select at least one contest to exclude linked participants from.',
            'competition_ids.min' => 'Select at least one contest to exclude linked participants from.',
            'competition_ids.*.exists' => 'Every selected contest must belong to this event.',
        ];
    }
}
