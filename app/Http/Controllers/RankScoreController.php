<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRankScoreRequest;
use App\Http\Requests\UpdateParticipationPointsRequest;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Models\RankScore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RankScoreController extends Controller
{
    public function store(
        StoreRankScoreRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $competition->rankScores()->updateOrCreate(
            ['rank' => $request->integer('rank')],
            ['points' => $request->validated('points')],
        );

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Rank scoring saved successfully.');
    }

    public function updateParticipationPoints(
        UpdateParticipationPointsRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $competition->update([
            'non_podium_points' => $request->validated('non_podium_points'),
        ]);

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Non-podium participation points updated successfully.');
    }

    public function destroy(
        Request $request,
        Event $event,
        Category $category,
        Competition $competition,
        RankScore $rankScore,
    ): RedirectResponse {
        abort_unless($competition->canBeConfiguredBy($request->user()), 403);

        $rankScore->delete();

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Rank scoring removed successfully.');
    }
}
