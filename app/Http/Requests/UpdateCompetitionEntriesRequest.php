<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCompetitionEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $category = $this->route('category');
        $competition = $this->route('competition');

        return $event instanceof Event
            && $category instanceof Category
            && $competition instanceof Competition
            && (string) $category->event_id === (string) $event->getKey()
            && (string) $competition->category_id === (string) $category->getKey()
            && $competition->usesCriteriaScoring()
            && $competition->judge_count > 1
            && (! $competition->scoresAreFinalized() || $this->user()?->isAdmin() === true);
    }

    protected function prepareForValidation(): void
    {
        $event = $this->route('event');
        $entries = $this->input('entries', []);

        if (! $this->boolean('exclude_subteams') || ! $event instanceof Event || ! is_array($entries)) {
            return;
        }

        foreach ($event->participants()->whereNotNull('parent_participant_id')->pluck('id') as $participantId) {
            $entries[$participantId] = 0;
        }

        $this->merge(['entries' => $entries]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $participantIds = $this->route('event')->participants()->pluck('id')
            ->map(static fn (int|string $id): string => (string) $id)->all();

        return [
            'exclude_subteams' => ['sometimes', 'boolean'],
            'entries' => ['required', Rule::array($participantIds)],
            'entries.*' => ['boolean'],
        ];
    }
}
