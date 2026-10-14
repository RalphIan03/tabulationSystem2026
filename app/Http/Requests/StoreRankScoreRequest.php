<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRankScoreRequest extends FormRequest
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
            && $competition->canBeConfiguredBy($this->user());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        if ($this->has('rank_scores')) {
            return [
                'rank_scores' => ['required', 'array', 'min:1', 'max:50'],
                'rank_scores.*.rank' => ['required', 'integer', 'min:1', 'max:1000', 'distinct'],
                'rank_scores.*.points' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            ];
        }

        return [
            'rank' => ['required', 'integer', 'min:1', 'max:1000'],
            'points' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rank_scores.*.rank.distinct' => 'Ranks must be different within this batch.',
        ];
    }
}
