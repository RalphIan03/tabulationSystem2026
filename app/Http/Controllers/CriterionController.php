<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCriterionRequest;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Criterion;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CriterionController extends Controller
{
    public function store(
        StoreCriterionRequest $request,
        Event $event,
        Category $category,
        Competition $competition,
    ): RedirectResponse {
        $criteria = $request->validated('criteria');

        if ($criteria === null) {
            $criteria = [[
                'name' => $request->validated('criterion_name'),
                'max_score' => $request->validated('max_score'),
            ]];
        }

        DB::transaction(fn () => $competition->criteria()->createMany(array_map(
            fn (array $criterion): array => [
                'name' => $criterion['name'],
                'max_score' => $criterion['max_score'],
            ],
            $criteria,
        )));

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', count($criteria) === 1 ? 'Criterion added successfully.' : 'Criteria added successfully.');
    }

    public function destroy(
        Request $request,
        Event $event,
        Category $category,
        Competition $competition,
        Criterion $criterion,
    ): RedirectResponse {
        abort_unless(
            $competition->usesCriteriaScoring(),
            403,
            'Criteria can only be managed for criteria-based contests.',
        );
        abort_unless($competition->canBeConfiguredBy($request->user()), 403);

        $criterion->delete();

        return to_route('events.categories.competitions.show', [
            $event,
            $category,
            $competition,
        ])->with('status', 'Criterion deleted successfully.');
    }
}
