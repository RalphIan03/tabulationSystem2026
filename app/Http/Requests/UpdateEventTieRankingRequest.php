<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TieRankingMethod;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventTieRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && ($this->user()?->isAdmin() === true
                || ! $event->competitions()->whereNotNull('scores_finalized_at')->exists());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tie_ranking_method' => ['required', Rule::enum(TieRankingMethod::class)],
        ];
    }
}
