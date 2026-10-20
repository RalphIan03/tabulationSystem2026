<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BatchExcludeLinkedContestantsRequest;
use App\Http\Requests\ImportCompetitionScoresRequest;
use App\Http\Requests\ResetCompetitionScoresRequest;
use App\Http\Requests\UpdateCompetitionDeductionsRequest;
use App\Http\Requests\UpdateCompetitionFinalizationRequest;
use App\Http\Requests\UpdateCompetitionScoresRequest;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Models\Participant;
use App\Services\CompetitionManager;
use App\Services\CompetitionScoreManager;
use App\Services\CompetitionScoreWorkbook;
use App\Services\LeaderboardCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class CompetitionScoreController extends Controller
{
    public function __construct(
        private readonly CompetitionScoreManager $scoreManager,
        private readonly CompetitionManager $competitionManager,
        private readonly LeaderboardCalculator $leaderboard,
        private readonly CompetitionScoreWorkbook $workbook,
    ) {}

    public function edit(
        Event $event,
        Category $category,
        Competition $competition,
    ): View {
        $event->load([
            'participants' => fn ($query) => $query->orderBy('name'),
        ]);
        $competition->load([
            'criteria' => fn ($query) => $query->orderBy('name'),
            'rankScores',
            'results.participant.parentTeam',
            'results.criterionScores',
        ]);
        $resultsByParticipant = $competition->results->keyBy('participant_id');
        $scoreParticipants = $event->participants
            ->sortBy(fn (Participant $participant): int => (bool) old(
                "entries.{$participant->id}",
                $resultsByParticipant->get($participant->id)?->has_entry ?? true,
            ) ? 0 : 1)
            ->values();

        return view('events.competitions.scores', [
            'event' => $event,
            'category' => $category,
            'competition' => $competition,
            'resultsByParticipant' => $resultsByParticipant,
            'scoreParticipants' => $scoreParticipants,
            'standings' => $this->leaderboard->competitionStandings($competition, $event),
            'criterionStandings' => $this->leaderboard->criterionStandings($competition, $event),
        ]);
    }

    public function template(Event $event, Category $category, Competition $competition): Response
    {
        abort_unless($competition->usesCriteriaScoring(), 404);

        return response($this->workbook->export($event, $competition), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="scoring-template-'.$competition->getKey().'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function import(
        ImportCompetitionScoresRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $scores = $this->workbook->import($request->file('score_file')->getRealPath(), $event, $competition);
        $this->scoreManager->save($competition, $event->participants, $scores);

        return to_route('events.categories.competitions.scores.edit', [$event, $category, $competition])
            ->with('status', 'Excel scores imported successfully. Review and finalize the contest when ready.');
    }

    public function update(
        UpdateCompetitionScoresRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $event->load([
            'participants' => fn ($query) => $query->orderBy('name'),
        ]);

        $this->scoreManager->save(
            $competition,
            $event->participants,
            $request->validated(),
        );

        return to_route('events.categories.competitions.scores.edit', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Participant scores saved successfully.');
    }

    public function excludeLinkedContestants(
        BatchExcludeLinkedContestantsRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $participants = $event->participants()
            ->whereNotNull('parent_participant_id')
            ->whereIn('id', $request->validated('participant_ids'))
            ->get();

        $count = $this->scoreManager->excludeLinkedContestants($competition, $participants);
        $label = $count === 1 ? 'contestant' : 'contestants';

        return to_route('events.categories.competitions.scores.edit', [$event, $category, $competition])
            ->with('status', "{$count} linked {$label} excluded from this contest.");
    }

    public function updateFinalization(
        UpdateCompetitionFinalizationRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $finalized = $this->scoreManager->toggleFinalization($competition);

        return to_route('events.categories.competitions.scores.edit', [
            $event,
            $category,
            $competition,
        ])->with(
            'status',
            $finalized
                ? 'Contest scores finalized successfully.'
                : 'Contest scores reopened for editing.',
        );
    }

    public function updateDeductions(
        UpdateCompetitionDeductionsRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $this->scoreManager->saveDeductions(
            $competition,
            $request->validated('deductions'),
        );

        return to_route('events.categories.competitions.scores.edit', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Participant deductions saved successfully.');
    }

    public function reset(
        ResetCompetitionScoresRequest $request,
        Event $event,
    ): RedirectResponse {
        $data = $request->validated();
        $count = $this->competitionManager->resetScores(
            $event,
            $data['reset_scope'],
            $data['competition_ids'] ?? [],
        );

        $label = $count === 1 ? 'contest' : 'contests';

        return to_route('events.show', $event)
            ->with('status', "Scores were reset for {$count} {$label}.");
    }
}
