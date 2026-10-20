<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Models\Event;
use App\Models\Participant;
use App\Services\ParticipantImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ParticipantController extends Controller
{
    public function store(
        StoreParticipantRequest $request,
        Event $event,
        ParticipantImageStorage $images,
    ): RedirectResponse {
        $profilePicturePath = null;

        if ($request->hasFile('participant_image')) {
            $profilePicturePath = $images->store($request->file('participant_image'), $event);
        }

        try {
            $event->participants()->create([
                'name' => $request->validated('participant_name'),
                'reference_no' => $request->validated('participant_reference'),
                'parent_participant_id' => $request->validated('parent_participant_id'),
                'profile_picture_path' => $profilePicturePath,
            ]);
        } catch (Throwable $exception) {
            if (is_string($profilePicturePath)) {
                Storage::disk('public')->delete($profilePicturePath);
            }

            throw $exception;
        }

        return to_route('events.show', $event)
            ->with('status', 'Participant added successfully.');
    }

    public function update(
        UpdateParticipantRequest $request,
        Event $event,
        Participant $participant,
        ParticipantImageStorage $images,
    ): RedirectResponse {
        $oldProfilePicturePath = $participant->profile_picture_path;
        $newProfilePicturePath = null;

        if ($request->hasFile('participant_image')) {
            $newProfilePicturePath = $images->store($request->file('participant_image'), $event);
        }

        $profilePicturePath = $newProfilePicturePath
            ?? ($request->boolean('remove_profile_picture') ? null : $oldProfilePicturePath);

        try {
            $participant->update([
                'name' => $request->validated('participant_name'),
                'reference_no' => $request->validated('participant_reference'),
                'parent_participant_id' => $request->has('parent_participant_id')
                    ? $request->validated('parent_participant_id')
                    : $participant->parent_participant_id,
                'profile_picture_path' => $profilePicturePath,
            ]);
        } catch (Throwable $exception) {
            if (is_string($newProfilePicturePath)) {
                Storage::disk('public')->delete($newProfilePicturePath);
            }

            throw $exception;
        }

        if (
            is_string($oldProfilePicturePath)
            && $oldProfilePicturePath !== $profilePicturePath
        ) {
            Storage::disk('public')->delete($oldProfilePicturePath);
        }

        return to_route('events.show', $event)
            ->with('status', 'Participant updated successfully.');
    }

    public function destroy(Request $request, Event $event, Participant $participant): RedirectResponse
    {
        if ($participant->childTeams()->exists()) {
            throw ValidationException::withMessages([
                'participant' => 'Reassign or remove this team\'s subteams before deleting it.',
            ]);
        }

        if ($request->user()?->isAdmin() !== true) {
            abort_if($participant->competitionResults()
                ->whereHas('competition', static fn ($query) => $query->whereNotNull('scores_finalized_at'))
                ->exists(), 403);
        }

        $profilePicturePath = $participant->profile_picture_path;

        $participant->delete();

        if (is_string($profilePicturePath)) {
            Storage::disk('public')->delete($profilePicturePath);
        }

        return to_route('events.show', $event)
            ->with('status', 'Participant deleted successfully.');
    }
}
