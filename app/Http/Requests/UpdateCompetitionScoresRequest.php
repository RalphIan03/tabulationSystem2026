<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCompetitionScoresRequest extends FormRequest
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
            && (! $competition->scoresAreFinalized() || $this->user()?->isAdmin() === true);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('exclude_subteams')) {
            return;
        }

        $event = $this->route('event');
        $entries = $this->input('entries', []);

        if (! $event instanceof Event || ! is_array($entries)) {
            return;
        }

        $subteamIds = $event->participants()
            ->whereNotNull('parent_participant_id')
            ->pluck('id')
            ->all();

        $scores = $this->input('scores');
        $wins = $this->input('wins');
        $placements = $this->input('placements');

        foreach ($subteamIds as $participantId) {
            $entries[$participantId] = 0;

            if (is_array($scores)) {
                unset($scores[$participantId]);
            }

            if (is_array($wins)) {
                unset($wins[$participantId]);
            }

            if (is_array($placements)) {
                unset($placements[$participantId]);
            }
        }

        $this->merge([
            'entries' => $entries,
            'scores' => $scores,
            'wins' => $wins,
            'placements' => $placements,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $event = $this->route('event');
        $competition = $this->route('competition');

        if (! $event instanceof Event || ! $competition instanceof Competition) {
            return [];
        }

        $participantIds = $event->participants()
            ->pluck('id')
            ->map(static fn (int|string $id): string => (string) $id)
            ->all();

        if ($competition->usesRankingScoring()) {
            $rules = [
                'judge_number' => ['prohibited'],
                'exclude_subteams' => ['sometimes', 'boolean'],
                'entries' => ['sometimes', Rule::array($participantIds)],
                'entries.*' => ['boolean'],
                'placements' => ['required', Rule::array($participantIds)],
            ];

            foreach ($participantIds as $participantId) {
                $rules["placements.{$participantId}"] = [
                    Rule::excludeIf(! $this->boolean("entries.{$participantId}", true)),
                    'nullable',
                    'integer',
                    'min:1',
                    'max:'.count($participantIds),
                ];
            }

            return $rules;
        }

        if (! $competition->usesCriteriaScoring()) {
            return [
                'judge_number' => ['prohibited'],
                'exclude_subteams' => ['sometimes', 'boolean'],
                'entries' => ['sometimes', Rule::array($participantIds)],
                'entries.*' => ['boolean'],
                'wins' => ['required', Rule::array($participantIds)],
                'wins.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ];
        }

        $criteria = $competition->criteria()->orderBy('id')->get();
        $criterionIds = $criteria
            ->pluck('id')
            ->map(static fn (int|string $id): string => (string) $id)
            ->all();

        $rules = [
            'judge_number' => ['sometimes', 'integer', 'min:1', 'max:'.$competition->judge_count, Rule::prohibitedIf($competition->judge_count === 1)],
            'exclude_subteams' => ['sometimes', 'boolean', Rule::prohibitedIf($this->has('judge_number'))],
            'entries' => ['sometimes', Rule::array($participantIds), Rule::prohibitedIf($this->has('judge_number'))],
            'entries.*' => ['boolean'],
            'scores' => ['required', Rule::array($participantIds)],
        ];
        $judgeNumbers = $this->has('judge_number')
            ? [(string) $this->integer('judge_number')]
            : array_map('strval', range(1, $competition->judge_count));

        foreach ($participantIds as $participantId) {
            $rules["scores.{$participantId}"] = ['nullable', Rule::array($criterionIds)];

            foreach ($criteria as $criterion) {
                $field = "scores.{$participantId}.{$criterion->getKey()}";

                if ($competition->judge_count === 1) {
                    $rules[$field] = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$criterion->max_score];

                    continue;
                }

                $rules[$field] = ['nullable', Rule::array($judgeNumbers)];

                foreach ($judgeNumbers as $judgeNumber) {
                    $rules["{$field}.{$judgeNumber}"] = [
                        'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$criterion->max_score,
                    ];
                }
            }
        }

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        $competition = $this->route('competition');

        if (! $competition instanceof Competition || ! $competition->usesRankingScoring()) {
            return [];
        }

        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('placements', []) as $participantId => $placement) {
                if (! $this->boolean("entries.{$participantId}", true)
                    || $placement === null
                    || $placement === ''
                    || (! is_int($placement) && ! is_string($placement))) {
                    continue;
                }

                $rank = (string) $placement;

                if (isset($seen[$rank])) {
                    $validator->errors()->add("placements.{$participantId}", 'Each entered participant must have a unique rank.');
                }

                $seen[$rank] = true;
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scores.*.*.max' => 'A score cannot exceed the criterion maximum.',
            'scores.*.*.*.max' => 'A judge score cannot exceed the criterion maximum.',
            'wins.*.integer' => 'Wins must be a whole number.',
        ];
    }
}
