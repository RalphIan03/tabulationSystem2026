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
use Illuminate\Support\Facades\DB;

final class RankScoreController extends Controller
{
    public function store(
        StoreRankScoreRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $rankScores = $request->validated('rank_scores');

        if ($rankScores === null) {
            $rankScores = [[
                'rank' => $request->validated('rank'),
                'points' => $request->validated('points'),
            ]];
        }

        DB::transaction(function () use ($competition, $rankScores): void {
            foreach ($rankScores as $rankScore) {
                $competition->rankScores()->updateOrCreate(
                    ['rank' => $rankScore['rank']],
                    ['points' => $rankScore['points']],
                );
            }
        });

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', count($rankScores) === 1 ? 'Rank scoring saved successfully.' : 'Rank scores saved successfully.');
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
