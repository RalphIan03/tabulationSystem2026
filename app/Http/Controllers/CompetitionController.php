<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CompetitionScoringMethod;
use App\Http\Requests\StoreCompetitionRequest;
use App\Http\Requests\UpdateCompetitionJudgeCountRequest;
use App\Http\Requests\UpdateCompetitionScoringMethodRequest;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Services\CompetitionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CompetitionController extends Controller
{
    public function __construct(
        private readonly CompetitionManager $competitionManager,
    ) {}

    public function store(
        StoreCompetitionRequest $request,
        Event $event,
        Category $category,
    ): RedirectResponse {
        $scoringMethod = $request->validated('scoring_method', CompetitionScoringMethod::Criteria->value);

        $category->competitions()->create([
            'name' => $request->validated('competition_name'),
            'scoring_method' => $scoringMethod,
            'judge_count' => $scoringMethod === CompetitionScoringMethod::Criteria->value
                ? $request->validated('judge_count', 1)
                : 1,
        ]);

        return to_route('events.show', $event)
            ->with('status', 'Contest added successfully.');
    }

    public function show(
        Request $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): View {
        $competition->load([
            'criteria' => fn ($query) => $query->orderBy('name'),
            'rankScores' => fn ($query) => $query->orderBy('rank'),
        ]);

        return view('events.competitions.show', [
            'event' => $event,
            'category' => $category,
            'competition' => $competition,
            'canEditConfiguration' => $competition->canBeConfiguredBy($request->user()),
            'judgeCountLocked' => $competition->results()->whereHas('criterionScores')->exists(),
        ]);
    }

    public function updateJudgeCount(
        UpdateCompetitionJudgeCountRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $competition->update(['judge_count' => $request->validated('judge_count')]);

        return to_route('events.categories.competitions.show', [$event, $category, $competition])
            ->with('status', 'Number of judges updated successfully.');
    }

    public function updateScoringMethod(
        UpdateCompetitionScoringMethodRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $competition->update([
            'scoring_method' => $request->validated('scoring_method'),
        ]);

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Contest scoring method updated successfully.');
    }

    public function destroy(
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $competitionName = $competition->name;

        $this->competitionManager->delete($competition);

        return to_route('events.show', $event)
            ->with('status', "{$competitionName} was deleted successfully.");
    }
}
