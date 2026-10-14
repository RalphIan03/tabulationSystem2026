<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

final class ImportCompetitionScoresRequest extends FormRequest
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
            && (! $competition->scoresAreFinalized() || $this->user()?->isAdmin() === true);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'score_file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:5120'],
        ];
    }
}
