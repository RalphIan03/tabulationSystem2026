<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CompetitionScoringMethod;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompetitionBracketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            && $competition->scoring_method === CompetitionScoringMethod::Wins
            && $competition->canBeConfiguredBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'bracket_url' => ['present', 'nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }
}
