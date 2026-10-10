<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCriterionRequest extends FormRequest
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
            && $competition->canBeConfiguredBy($this->user());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $competition = $this->route('competition');
        $uniqueName = Rule::unique('criteria', 'name')
            ->where(fn (Builder $query): Builder => $query->where(
                'competition_id',
                $competition instanceof Competition ? $competition->getKey() : null,
            ));

        if ($this->has('criteria')) {
            return [
                'criteria' => ['required', 'array', 'min:1', 'max:50'],
                'criteria.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case', $uniqueName],
                'criteria.*.max_score' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            ];
        }

        return [
            'criterion_name' => [
                'required',
                'string',
                'max:255',
                $uniqueName,
            ],
            'max_score' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'criterion_name.unique' => 'This criterion already exists in the contest.',
            'criteria.*.name.unique' => 'This criterion already exists in the contest.',
            'criteria.*.name.distinct' => 'Criterion names must be different within this batch.',
        ];
    }
}
