@extends('layouts.admin')

@section('title', $competition->name)
@section('heading', 'Contest configuration')

@section('content')
    <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('events.index') }}" class="font-medium transition hover:text-maroon-700">Events</a>
        <span>/</span>
        <a href="{{ route('events.show', $event) }}" class="font-medium transition hover:text-maroon-700">{{ $event->name }}</a>
        <span>/</span>
        <span>{{ $category->name }}</span>
        <span>/</span>
        <span class="font-medium text-slate-800">{{ $competition->name }}</span>
    </nav>

    <section class="rounded-2xl bg-gradient-to-br from-maroon-700 to-maroon-950 p-7 text-white shadow-xl shadow-maroon-950/10 sm:p-9">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-maroon-200">{{ $category->name }}</p>
                <h2 class="mt-2 text-3xl font-semibold tracking-tight">{{ $competition->name }}</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-maroon-100/80">{{ $competition->scoring_method->description() }} Configure the points awarded to each final rank.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('events.show', $event) }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Back to event</a>
                <a href="{{ route('events.categories.competitions.scores.edit', [$event, $category, $competition]) }}" class="inline-flex h-10 items-center justify-center rounded-xl bg-white px-4 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-50">Enter scores</a>
                @if (auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('events.categories.competitions.destroy', [$event, $category, $competition], false) }}" data-loading-text="Deleting contest…" onsubmit="return confirm('Delete this contest? Its configuration and scores will be permanently removed.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="h-10 rounded-xl border border-red-200/60 bg-red-600 px-4 text-sm font-semibold text-white transition hover:bg-red-700">Delete contest</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    @if (! $canEditConfiguration)
        <p class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-medium text-amber-900">This contest is finalized. Configuration is read-only for auditors; an administrator can still make corrections.</p>
    @endif

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-6 py-5">
            <h3 class="text-lg font-semibold text-slate-950">Winner determination</h3>
            <p class="mt-1 text-sm text-slate-500">Choose how winners are determined for this contest.</p>
        </div>

        @if ($canEditConfiguration)
        <form method="POST" action="{{ route('events.categories.competitions.scoring-method.update', [$event, $category, $competition], false) }}" class="p-5">
            @csrf
            @method('PATCH')

            <div class="grid gap-3 md:grid-cols-2">
                @foreach (\App\Enums\CompetitionScoringMethod::cases() as $method)
                    <label @class([
                        'relative flex cursor-pointer gap-3 rounded-xl border p-4 transition',
                        'border-maroon-600 bg-maroon-50 ring-2 ring-maroon-600/10' => old('scoring_method', $competition->scoring_method->value) === $method->value,
                        'border-slate-200 hover:border-maroon-300 hover:bg-maroon-50/40' => old('scoring_method', $competition->scoring_method->value) !== $method->value,
                    ])>
                        <input name="scoring_method" type="radio" value="{{ $method->value }}" @checked(old('scoring_method', $competition->scoring_method->value) === $method->value) class="mt-1 border-slate-300 text-maroon-700 focus:ring-maroon-600">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">{{ $method->label() }}</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $method->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @error('scoring_method')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">
                    @if ($competition->usesCriteriaScoring())
                        Criteria configuration is active for this contest.
                    @elseif ($competition->usesRankingScoring())
                        Auditors enter finishing positions directly. Rank points are awarded from this configuration.
                    @else
                        Wins-based standings are active. Existing criteria are preserved but paused.
                    @endif
                </p>
                <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save scoring method</button>
            </div>
        </form>
        @else
            <p class="p-5 text-sm text-slate-600">Current method: <span class="font-semibold text-slate-900">{{ $competition->scoring_method->label() }}</span></p>
        @endif
    </section>

    @if ($competition->supportsExternalBracket())
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-5">
                <div>
                    <h3 class="text-lg font-semibold text-slate-950">External bracket</h3>
                    <p class="mt-1 text-sm text-slate-500">Optionally link to this contest’s bracket on another website. Scores are still entered in this system.</p>
                </div>
                @if ($competition->bracket_url)
                    <a href="{{ $competition->bracket_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-11 items-center justify-center rounded-xl border border-maroon-200 px-4 text-sm font-semibold text-maroon-700 transition hover:bg-maroon-50">Open bracket</a>
                @endif
            </div>
            @if ($canEditConfiguration)
                <form method="POST" action="{{ route('events.categories.competitions.bracket.update', [$event, $category, $competition], false) }}" class="space-y-4 p-6" data-loading-text="Saving bracket link…">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="bracket_url" class="mb-1.5 block text-sm font-semibold text-slate-700">Bracketing URL (optional)</label>
                        <input id="bracket_url" name="bracket_url" type="url" maxlength="2048" value="{{ old('bracket_url', $competition->bracket_url) }}" placeholder="https://example.com/your-bracket" aria-describedby="bracket_url_help" class="h-11 w-full rounded-xl border border-slate-300 px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <p id="bracket_url_help" class="mt-1 text-xs text-slate-500">Use a full HTTP or HTTPS URL, preferably HTTPS. Leave blank and save to remove the link.</p>
                        @error('bracket_url')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save bracket link</button>
                </form>
            @else
                <p class="p-6 text-sm text-slate-600">{{ $competition->bracket_url ? 'The bracket link is read-only for this finalized contest.' : 'No bracket link configured.' }}</p>
            @endif
        </section>
    @endif

    <div @class(['mt-6 grid gap-6', 'xl:grid-cols-2' => $competition->usesCriteriaScoring()])>
        @if ($competition->usesCriteriaScoring())
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-950">Judging criteria</h3>
                        <p class="mt-1 text-sm text-slate-500">Define the items judges will score.</p>
                    </div>
                    <span class="rounded-full bg-maroon-50 px-2.5 py-1 text-xs font-semibold text-maroon-700">{{ $competition->criteria->count() }}</span>
                </div>
            </div>

            <div class="border-b border-slate-200 bg-slate-50/70 p-5">
                <p class="text-sm font-semibold text-slate-900">Number of judges</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">Each judge scores every criterion. The overall average is the combined total of all judges divided by the number of judges, rounded once to two decimals.</p>
                @if ($canEditConfiguration && ! $judgeCountLocked)
                    <form method="POST" action="{{ route('events.categories.competitions.judge-count.update', [$event, $category, $competition], false) }}" data-loading-text="Saving judge count…" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="judge_count" class="mb-1 block text-xs font-medium text-slate-600">Judges (1–10)</label>
                            <input id="judge_count" name="judge_count" type="number" min="1" max="10" step="1" value="{{ old('judge_count', $competition->judge_count) }}" required class="h-11 w-28 rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        </div>
                        <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save judge count</button>
                    </form>
                    @error('judge_count')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                @else
                    <p class="mt-3 text-sm font-semibold text-maroon-800">{{ $competition->judge_count }} {{ \Illuminate\Support\Str::plural('judge', $competition->judge_count) }}</p>
                    @if ($judgeCountLocked)
                        <p class="mt-1 text-xs text-slate-500">Judge count is locked after scores are entered. Reset this contest’s scores to change it.</p>
                    @endif
                @endif
            </div>

            @if ($canEditConfiguration)
            @php
                $draftCriteria = is_array(old('criteria')) && count(old('criteria')) > 0
                    ? old('criteria')
                    : [['name' => '', 'max_score' => 100]];
            @endphp
            <form method="POST" action="{{ route('events.categories.competitions.criteria.store', [$event, $category, $competition], false) }}" class="border-b border-slate-200 bg-slate-50/70 p-5" data-criteria-form data-loading-text="Saving criteria…">
                @csrf
                <p class="text-sm font-semibold text-slate-900">Add judging criteria</p>
                <p class="mt-1 text-xs text-slate-500">Add the fields you need, then save them together.</p>
                @error('criteria')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="mt-4 space-y-3" data-criteria-rows>
                    @foreach ($draftCriteria as $index => $draftCriterion)
                        <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[1fr_9rem_auto] sm:items-start" data-criterion-row>
                            <div>
                                <label for="criterion_name_{{ $index }}" class="mb-1 block text-xs font-medium text-slate-600">Criterion name</label>
                                <input id="criterion_name_{{ $index }}" name="criteria[{{ $index }}][name]" type="text" value="{{ is_array($draftCriterion) ? ($draftCriterion['name'] ?? '') : '' }}" maxlength="255" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="e.g. Technique">
                                @error("criteria.$index.name")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="criterion_max_score_{{ $index }}" class="mb-1 block text-xs font-medium text-slate-600">Maximum score</label>
                                <input id="criterion_max_score_{{ $index }}" name="criteria[{{ $index }}][max_score]" type="number" min="0.01" max="999999.99" step="0.01" value="{{ is_array($draftCriterion) ? ($draftCriterion['max_score'] ?? '') : '' }}" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                @error("criteria.$index.max_score")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <button type="button" data-criterion-remove class="h-11 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40 sm:mt-5">Remove</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <button type="button" data-criterion-add class="h-11 rounded-xl border border-maroon-200 bg-white px-4 text-sm font-semibold text-maroon-700 transition hover:bg-maroon-50">+ Add another criterion</button>
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save criteria</button>
                </div>
            </form>
            <template id="criterion-row-template">
                <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[1fr_9rem_auto] sm:items-start" data-criterion-row>
                    <div>
                        <label for="criterion_name___INDEX__" class="mb-1 block text-xs font-medium text-slate-600">Criterion name</label>
                        <input id="criterion_name___INDEX__" name="criteria[__INDEX__][name]" type="text" maxlength="255" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="e.g. Presentation">
                    </div>
                    <div>
                        <label for="criterion_max_score___INDEX__" class="mb-1 block text-xs font-medium text-slate-600">Maximum score</label>
                        <input id="criterion_max_score___INDEX__" name="criteria[__INDEX__][max_score]" type="number" min="0.01" max="999999.99" step="0.01" value="100" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                    </div>
                    <button type="button" data-criterion-remove class="h-11 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40 sm:mt-5">Remove</button>
                </div>
            </template>
            @endif

            <div class="divide-y divide-slate-100">
                @forelse ($competition->criteria as $criterion)
                    <div class="flex items-center justify-between gap-4 px-6 py-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ $criterion->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">Maximum score: {{ number_format((float) $criterion->max_score, 2) }}</p>
                        </div>
                        @if ($canEditConfiguration)
                        <form method="POST" action="{{ route('events.categories.competitions.criteria.destroy', [$event, $category, $competition, $criterion], false) }}" onsubmit="return confirm('Delete this criterion?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-700" aria-label="Delete {{ $criterion->name }}">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </form>
                        @endif
                    </div>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-400">No judging criteria configured.</p>
                @endforelse
            </div>
        </section>
        @else
            <section class="rounded-2xl border border-maroon-200 bg-maroon-50/60 p-6 shadow-sm">
                <div class="flex items-start gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-maroon-700 text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4Z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M7 6H4v1a4 4 0 0 0 4 4M17 6h3v1a4 4 0 0 1-4 4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold text-maroon-950">Wins-based scoring active</h3>
                        <p class="mt-1 text-sm leading-6 text-maroon-900/70">Participant or team standings will be ordered by total wins. Judging criteria are not used in this mode.</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-950">Leaderboard points per rank</h3>
                        <p class="mt-1 text-sm text-slate-500">Set the event leaderboard points awarded to each final placement.</p>
                    </div>
                    <span class="rounded-full bg-maroon-50 px-2.5 py-1 text-xs font-semibold text-maroon-700">{{ $competition->rankScores->count() }}</span>
                </div>
            </div>

            @if ($canEditConfiguration)
            @php
                $draftRankScores = is_array(old('rank_scores')) && count(old('rank_scores')) > 0
                    ? old('rank_scores')
                    : [['rank' => '', 'points' => '']];
            @endphp
            <form method="POST" action="{{ route('events.categories.competitions.rank-scores.store', [$event, $category, $competition], false) }}" class="border-b border-slate-200 bg-slate-50/70 p-5" data-rank-scores-form data-loading-text="Saving rank scores…">
                @csrf
                <p class="text-sm font-semibold text-slate-900">Add rank scores</p>
                <p class="mt-1 text-xs text-slate-500">Add multiple placements, then save them together. Existing ranks will have their points updated.</p>
                @error('rank_scores')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="mt-4 space-y-3" data-rank-score-rows>
                    @foreach ($draftRankScores as $index => $draftRankScore)
                        <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[8rem_1fr_auto] sm:items-start" data-rank-score-row>
                            <div>
                                <label for="rank_score_rank_{{ $index }}" class="mb-1 block text-xs font-medium text-slate-600">Rank</label>
                                <input id="rank_score_rank_{{ $index }}" name="rank_scores[{{ $index }}][rank]" type="number" min="1" max="1000" step="1" value="{{ is_array($draftRankScore) ? ($draftRankScore['rank'] ?? '') : '' }}" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Rank">
                                @error("rank_scores.$index.rank")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="rank_score_points_{{ $index }}" class="mb-1 block text-xs font-medium text-slate-600">Points awarded</label>
                                <input id="rank_score_points_{{ $index }}" name="rank_scores[{{ $index }}][points]" type="number" min="0" max="999999.99" step="0.01" value="{{ is_array($draftRankScore) ? ($draftRankScore['points'] ?? '') : '' }}" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Points awarded">
                                @error("rank_scores.$index.points")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <button type="button" data-rank-score-remove class="h-11 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40 sm:mt-5">Remove</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <button type="button" data-rank-score-add class="h-11 rounded-xl border border-maroon-200 bg-white px-4 text-sm font-semibold text-maroon-700 transition hover:bg-maroon-50">+ Add another rank</button>
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save rank scores</button>
                </div>
            </form>
            <template id="rank-score-row-template">
                <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[8rem_1fr_auto] sm:items-start" data-rank-score-row>
                    <div>
                        <label for="rank_score_rank___INDEX__" class="mb-1 block text-xs font-medium text-slate-600">Rank</label>
                        <input id="rank_score_rank___INDEX__" name="rank_scores[__INDEX__][rank]" type="number" min="1" max="1000" step="1" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Rank">
                    </div>
                    <div>
                        <label for="rank_score_points___INDEX__" class="mb-1 block text-xs font-medium text-slate-600">Points awarded</label>
                        <input id="rank_score_points___INDEX__" name="rank_scores[__INDEX__][points]" type="number" min="0" max="999999.99" step="0.01" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Points awarded">
                    </div>
                    <button type="button" data-rank-score-remove class="h-11 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40 sm:mt-5">Remove</button>
                </div>
            </template>
            @endif

            @if ($canEditConfiguration)
            <form method="POST" action="{{ route('events.categories.competitions.participation-points.update', [$event, $category, $competition], false) }}" class="border-b border-slate-200 bg-maroon-50/50 p-5">
                @csrf
                @method('PATCH')
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <label for="non_podium_points" class="block text-sm font-semibold text-slate-800">Non-podium participation points</label>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Points awarded to participants who compete but do not finish in a configured ranked place. Set to 0 to award none.</p>
                    </div>
                    <div class="flex gap-2">
                        <div>
                            <label for="non_podium_points" class="sr-only">Non-podium points</label>
                            <input id="non_podium_points" name="non_podium_points" type="number" min="0" max="999999.99" step="0.01" value="{{ old('non_podium_points', $competition->non_podium_points) }}" required class="h-11 w-32 rounded-xl border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Points">
                        </div>
                        <button type="submit" class="h-11 rounded-xl border border-maroon-200 bg-white px-4 text-sm font-semibold text-maroon-700 transition hover:bg-maroon-100">Save</button>
                    </div>
                </div>
                @error('non_podium_points')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </form>
            @else
                <p class="border-b border-slate-200 p-5 text-sm text-slate-600">Non-podium participation points: <span class="font-semibold text-slate-900">{{ number_format((float) $competition->non_podium_points, 2) }}</span></p>
            @endif

            <div class="overflow-x-auto">
                @if ($competition->rankScores->isNotEmpty())
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-6 py-3 font-semibold">Rank</th>
                                <th class="px-6 py-3 font-semibold">Points</th>
                                <th class="px-6 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($competition->rankScores as $rankScore)
                                <tr>
                                    <td class="px-6 py-4 font-semibold text-slate-900">#{{ $rankScore->rank }}</td>
                                    <td class="px-6 py-4 text-slate-600">{{ number_format((float) $rankScore->points, 2) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @if ($canEditConfiguration)
                                        <form method="POST" action="{{ route('events.categories.competitions.rank-scores.destroy', [$event, $category, $competition, $rankScore], false) }}" onsubmit="return confirm('Remove scoring for this rank?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                                        </form>
                                        @else
                                            <span class="text-xs text-slate-400">Locked</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="px-6 py-10 text-center text-sm text-slate-400">No rank scoring configured.</p>
                @endif
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-criteria-form]');
            const rows = form?.querySelector('[data-criteria-rows]');
            const template = document.getElementById('criterion-row-template');

            if (!form || !rows || !template) return;

            let nextIndex = {{ max(array_map('intval', array_keys($draftCriteria ?? [0]))) + 1 }};
            const updateRemoveButtons = () => {
                rows.querySelectorAll('[data-criterion-remove]').forEach((button) => {
                    button.disabled = rows.querySelectorAll('[data-criterion-row]').length === 1;
                });
            };

            form.querySelector('[data-criterion-add]')?.addEventListener('click', () => {
                const row = template.content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[id], [name], [for]').forEach((element) => {
                    ['id', 'name', 'for'].forEach((attribute) => {
                        if (element.hasAttribute(attribute)) {
                            element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(nextIndex)));
                        }
                    });
                });
                nextIndex += 1;
                rows.append(row);
                updateRemoveButtons();
                row.querySelector('input[name$="[name]"]')?.focus();
            });

            rows.addEventListener('click', (event) => {
                const button = event.target.closest('[data-criterion-remove]');

                if (!button || rows.querySelectorAll('[data-criterion-row]').length === 1) return;

                button.closest('[data-criterion-row]')?.remove();
                updateRemoveButtons();
            });

            updateRemoveButtons();
        })();

        (() => {
            const form = document.querySelector('[data-rank-scores-form]');
            const rows = form?.querySelector('[data-rank-score-rows]');
            const template = document.getElementById('rank-score-row-template');

            if (!form || !rows || !template) return;

            let nextIndex = {{ max(array_map('intval', array_keys($draftRankScores ?? [0]))) + 1 }};
            const updateRemoveButtons = () => {
                rows.querySelectorAll('[data-rank-score-remove]').forEach((button) => {
                    button.disabled = rows.querySelectorAll('[data-rank-score-row]').length === 1;
                });
            };

            form.querySelector('[data-rank-score-add]')?.addEventListener('click', () => {
                const row = template.content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[id], [name], [for]').forEach((element) => {
                    ['id', 'name', 'for'].forEach((attribute) => {
                        if (element.hasAttribute(attribute)) {
                            element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(nextIndex)));
                        }
                    });
                });
                nextIndex += 1;
                rows.append(row);
                updateRemoveButtons();
                row.querySelector('input[name$="[rank]"]')?.focus();
            });

            rows.addEventListener('click', (event) => {
                const button = event.target.closest('[data-rank-score-remove]');

                if (!button || rows.querySelectorAll('[data-rank-score-row]').length === 1) return;

                button.closest('[data-rank-score-row]')?.remove();
                updateRemoveButtons();
            });

            updateRemoveButtons();
        })();
    </script>
@endpush
