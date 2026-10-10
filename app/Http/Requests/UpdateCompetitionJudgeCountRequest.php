<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateCompetitionJudgeCountRequest extends FormRequest
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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'judge_count' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('judge_count')) {
                return;
            }

            $competition = $this->route('competition');

            if (! $competition instanceof Competition
                || $competition->judge_count === $this->integer('judge_count')) {
                return;
            }

            if ($competition->results()->whereHas('criterionScores')->exists()) {
                $validator->errors()->add('judge_count', 'Reset this contest’s scores before changing its number of judges.');
            }
        }];
    }
}
