<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $participant = $this->route('participant');

        return $event instanceof Event
            && $participant instanceof Participant
            && (string) $participant->event_id === (string) $event->getKey();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $event = $this->route('event');
        $participant = $this->route('participant');

        return [
            'participant_name' => ['required', 'string', 'max:255'],
            'participant_reference' => ['nullable', 'string', 'max:100'],
            'participant_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
            'remove_profile_picture' => ['nullable', 'boolean'],
            'parent_participant_id' => [
                'nullable',
                'integer',
                Rule::exists('participants', 'id')
                    ->where('event_id', $event?->getKey())->whereNull('parent_participant_id'),
                Rule::notIn([$participant?->getKey()]),
                Rule::prohibitedIf($participant instanceof Participant && $participant->childTeams()->exists()),
            ],
        ];
    }
}
