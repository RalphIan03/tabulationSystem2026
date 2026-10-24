<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AccountRole;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventDeductionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && ! $event->isArchived()
            && in_array($this->user()?->role, [AccountRole::Admin, AccountRole::Auditor], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $participantIds = $this->route('event')->participants()->whereNull('parent_participant_id')
            ->pluck('id')->map(static fn (int|string $id): string => (string) $id)->all();

        if ($participantIds === []) {
            return ['deductions' => ['required', 'prohibited']];
        }

        return [
            'deductions' => ['required', Rule::array($participantIds)],
            'deductions.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'deductions.prohibited' => 'Add a main team or participant before entering overall deductions.',
        ];
    }
}
