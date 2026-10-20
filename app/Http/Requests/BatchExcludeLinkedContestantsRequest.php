<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BatchExcludeLinkedContestantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $category = $this->route('category');
        $competition = $this->route('competition');

        return $this->user()?->isAdmin() === true
            && $event instanceof Event
            && $category instanceof Category
            && $competition instanceof Competition
            && (string) $category->event_id === (string) $event->getKey()
            && (string) $competition->category_id === (string) $category->getKey();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $event = $this->route('event');

        return [
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Participant::class, 'id')
                    ->where('event_id', $event?->getKey())
                    ->whereNotNull('parent_participant_id'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'participant_ids.required' => 'Select at least one linked contestant to exclude.',
            'participant_ids.min' => 'Select at least one linked contestant to exclude.',
            'participant_ids.*.exists' => 'Only linked contestants from this event can be excluded.',
        ];
    }
}
