<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEventDeductionsRequest;
use App\Models\Event;
use App\Services\LeaderboardCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class EventLeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardCalculator $leaderboard,
    ) {}

    public function show(Event $event): View
    {
        $event->load([
            'categories' => fn ($query) => $query->orderBy('name'),
            'categories.competitions' => fn ($query) => $query->orderBy('name'),
            'categories.competitions.criteria',
            'categories.competitions.rankScores',
            'categories.competitions.results.participant.parentTeam',
            'categories.competitions.results.criterionScores',
        ]);

        $standingsByCompetition = [];

        foreach ($event->categories as $category) {
            foreach ($category->competitions as $competition) {
                $standingsByCompetition[$competition->getKey()] = $this->leaderboard
                    ->competitionStandings($competition, $event);
            }
        }

        $overallStandings = $this->leaderboard->overallStandings($event);

        return view('events.leaderboard', [
            'event' => $event,
            'overallStandings' => $overallStandings,
            'overallPointsByParticipant' => $overallStandings->keyBy(fn (array $row): int => $row['participant']->getKey()),
            'deductionParticipants' => $event->participants()->whereNull('parent_participant_id')->orderBy('name')->get(),
            'standingsByCompetition' => $standingsByCompetition,
        ]);
    }

    public function updateDeductions(UpdateEventDeductionsRequest $request, Event $event): RedirectResponse
    {
        $deductions = $request->validated('deductions');

        DB::transaction(static function () use ($event, $deductions): void {
            $participants = $event->participants()->whereNull('parent_participant_id')
                ->whereIn('id', array_keys($deductions))->orderBy('id')->get();

            foreach ($participants as $participant) {
                $participant->update(['overall_deduction' => $deductions[$participant->getKey()] ?? 0]);
            }
        });

        return to_route('events.leaderboard', $event)
            ->with('status', 'Overall event deductions saved successfully.');
    }
}
