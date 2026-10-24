@extends('layouts.admin')

@section('title', 'Scores · '.$competition->name)
@section('heading', 'Participant scores')

@section('content')
    @php
        $isAdmin = auth()->user()->isAdmin();
        $scoresAreFinalized = $competition->scoresAreFinalized();
        $canEditScores = ! $scoresAreFinalized || $isAdmin;
    @endphp

    <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('events.index') }}" class="font-medium transition hover:text-maroon-700">Events</a>
        <span>/</span>
        <a href="{{ route('events.show', $event) }}" class="font-medium transition hover:text-maroon-700">{{ $event->name }}</a>
        <span>/</span>
        <span>{{ $category->name }}</span>
        <span>/</span>
        <span class="font-medium text-slate-800">{{ $competition->name }} scores</span>
    </nav>

    <section class="overflow-hidden rounded-2xl bg-gradient-to-br from-maroon-700 to-maroon-950 text-white shadow-xl shadow-maroon-950/10">
        <div class="flex flex-col gap-6 p-7 sm:p-9 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">{{ $category->name }}</span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">{{ $competition->scoring_method->label() }}</span>
                    @if ($competition->usesCriteriaScoring())
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">{{ $competition->judge_count }} {{ \Illuminate\Support\Str::plural('judge', $competition->judge_count) }}</span>
                    @endif
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-emerald-400/15 text-emerald-200 ring-emerald-300/20' => $competition->scoresAreFinalized(),
                        'bg-amber-400/15 text-amber-200 ring-amber-300/20' => ! $competition->scoresAreFinalized(),
                    ])>{{ $competition->scoresAreFinalized() ? 'Scores finalized' : ($competition->hasSavedScores() ? 'Pending finalization' : 'Scores not started') }}</span>
                </div>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight">Enter participant scores</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-maroon-100/80">{{ $competition->name }} · {{ $competition->scoring_method->description() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('events.categories.competitions.show', [$event, $category, $competition]) }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Configure contest</a>
                @if ($competition->supportsExternalBracket() && $competition->bracket_url)
                    <a href="{{ $competition->bracket_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Open bracket</a>
                @endif
                <a href="{{ route('events.leaderboard', $event) }}" class="inline-flex h-10 items-center justify-center rounded-xl bg-white px-4 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-50">View leaderboard</a>
                <a href="{{ route('events.categories.competitions.scores.export', [$event, $category, $competition, 'format' => 'xlsx'], false) }}" download data-export-download data-loading-text="Exporting Excel…" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Export Excel</a>
                <a href="{{ route('events.categories.competitions.scores.export', [$event, $category, $competition, 'format' => 'pdf'], false) }}" download data-export-download data-loading-text="Exporting PDF…" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Export PDF</a>
                @if ($competition->usesCriteriaScoring())
                    <a href="{{ route('events.categories.competitions.scores.template', [$event, $category, $competition], false) }}" download data-export-download data-loading-text="Preparing score template…" class="inline-flex h-10 items-center justify-center rounded-xl border border-white/25 px-4 text-sm font-semibold text-white transition hover:bg-white/10">Download scoring template</a>
                @endif
            </div>
        </div>
    </section>

    @if ($competition->usesCriteriaScoring() && $competition->criteria->isNotEmpty() && $event->participants->isNotEmpty())
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="font-semibold text-slate-950">Score with Excel</h3>
            <p class="mt-1 text-sm text-slate-500">Download the scoring template, fill the score cells and entry status, then import it here. Import replaces this contest's current base scores; review existing deductions afterward. Do not change participant, judge, or criterion columns.</p>
            @if ($canEditScores)
                <form method="POST" enctype="multipart/form-data" action="{{ route('events.categories.competitions.scores.import', [$event, $category, $competition], false) }}" class="mt-4 flex flex-wrap items-end gap-3" data-loading-text="Importing Excel scores…">
                    @csrf
                    <label class="grid gap-1 text-sm font-semibold text-slate-700">Scored workbook (.xlsx)
                        <input type="file" name="score_file" accept=".xlsx" required class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-maroon-50 file:px-3 file:py-2 file:font-semibold file:text-maroon-700">
                    </label>
                    <button type="submit" class="h-10 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white hover:bg-maroon-800">Import scores</button>
                </form>
                @error('score_file')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            @else
                <p class="mt-3 text-sm font-semibold text-emerald-700">Imports are locked for auditors after finalization.</p>
            @endif
        </section>
    @endif

    <section @class([
        'mt-6 flex flex-col gap-4 rounded-2xl border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between',
        'border-emerald-200 bg-emerald-50' => $competition->scoresAreFinalized(),
        'border-amber-200 bg-amber-50' => ! $competition->scoresAreFinalized(),
    ])>
        <div>
            <h3 @class([
                'font-semibold',
                'text-emerald-950' => $competition->scoresAreFinalized(),
                'text-amber-950' => ! $competition->scoresAreFinalized(),
            ])>{{ $competition->scoresAreFinalized() ? 'This score sheet is finalized' : 'Finalize this score sheet when scoring is complete' }}</h3>
            <p @class([
                'mt-1 text-sm',
                'text-emerald-800' => $competition->scoresAreFinalized(),
                'text-amber-800' => ! $competition->scoresAreFinalized(),
            ])>
                @if ($scoresAreFinalized && $isAdmin)
                    Finalized {{ $competition->scores_finalized_at->diffForHumans() }}. Administrator override is enabled for scores and deductions.
                @elseif ($scoresAreFinalized)
                    Finalized {{ $competition->scores_finalized_at->diffForHumans() }}. Scores and deductions are locked for auditor accounts.
                @else
                    Finalized contests are counted toward the event's dashboard progress.
                @endif
            </p>
            @error('finalization')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
        </div>

        @if (! $scoresAreFinalized || $isAdmin)
            <form method="POST" action="{{ route('events.categories.competitions.scores.finalization.update', [$event, $category, $competition], false) }}" data-loading-text="{{ $scoresAreFinalized ? 'Reopening scores…' : 'Finalizing scores…' }}" @if ($scoresAreFinalized) onsubmit="return confirm('Reopen this score sheet for auditors?')" @else onsubmit="return confirm('Finalize these scores? Auditor editing will be locked, while administrators will retain override access.')" @endif>
                @csrf
                @method('PATCH')
                <button type="submit" @class([
                    'inline-flex h-11 shrink-0 items-center justify-center rounded-xl px-5 text-sm font-semibold transition',
                    'border border-emerald-300 bg-white text-emerald-800 hover:bg-emerald-100' => $scoresAreFinalized,
                    'bg-amber-900 text-white hover:bg-amber-950' => ! $scoresAreFinalized,
                ])>{{ $scoresAreFinalized ? 'Reopen scores' : 'Finalize scores' }}</button>
            </form>
        @else
            <span class="inline-flex h-11 items-center rounded-xl border border-emerald-300 bg-white px-5 text-sm font-semibold text-emerald-800">Locked for auditors</span>
        @endif
    </section>

    @if ($competition->usesCriteriaScoring() && $competition->criteria->isEmpty())
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-6">
            <h3 class="font-semibold text-amber-950">Configure criteria before entering scores</h3>
            <p class="mt-1 text-sm text-amber-800">This contest does not have any judging criteria yet.</p>
            <a href="{{ route('events.categories.competitions.show', [$event, $category, $competition]) }}" class="mt-4 inline-flex h-10 items-center rounded-xl bg-amber-900 px-4 text-sm font-semibold text-white">Open configuration</a>
        </section>
    @elseif ($event->participants->isEmpty())
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
            <p class="font-semibold text-slate-800">No participants available</p>
            <p class="mt-1 text-sm text-slate-500">Add participants to the event before recording scores.</p>
            <a href="{{ route('events.show', $event) }}" class="mt-4 inline-flex h-10 items-center rounded-xl bg-maroon-700 px-4 text-sm font-semibold text-white">Manage participants</a>
        </section>
    @else
        @php
            $hasRankings = $standings->isNotEmpty();
            $hasSavedScores = $resultsByParticipant->contains(function ($result) use ($competition) {
                return $result->has_entry && ($competition->usesCriteriaScoring()
                    ? $result->criteriaScoreSummary($competition)['rankable']
                    : ($competition->usesRankingScoring() ? $result->placement !== null : $result->wins !== null));
            });
        @endphp

        @if ($canEditScores && $hasSavedScores)
            <form id="deduction-form" method="POST" action="{{ route('events.categories.competitions.deductions.update', [$event, $category, $competition], false) }}" class="hidden" data-loading-text="Saving deductions…">
                @csrf
                @method('PATCH')
            </form>
        @endif

        @if ($competition->usesCriteriaScoring() && $competition->judge_count > 1)
            @include('events.competitions.partials.multi-judge-scores')
        @else
        <form method="POST" action="{{ route('events.categories.competitions.scores.update', [$event, $category, $competition], false) }}" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-loading-text="Saving participant scores…">
            @csrf
            @method('PATCH')

            <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-950">Score sheet</h3>
                    <p class="mt-1 text-sm text-slate-500">Uncheck Has entry to exclude a participant from rankings, podium results, and non-podium points. Save a base score before entering a deduction.</p>
                    @unless ($competition->usesRankingScoring())
                        <p class="mt-1 text-xs text-slate-400">Tie ranking: {{ $event->tie_ranking_method->label() }}.</p>
                    @endunless
                    @if ($competition->usesRankingScoring())
                        <p class="mt-1 text-xs text-slate-500">Enter each participating team’s unique finishing rank, starting at 1. Entered ranks determine base points; final rankings use points after deductions. Mark non-entrants as No entry. Entered ranks must be consecutive before finalization.</p>
                    @elseif (! $competition->usesCriteriaScoring())
                        <p class="mt-1 text-xs text-slate-500">Wins determine rank. Deductions reduce awarded rank points, not wins.</p>
                    @endif
                    @if ($competition->usesCriteriaScoring() && $competition->judge_count > 1)
                        <p class="mt-1 text-xs text-slate-500">Each criterion uses the average of {{ $competition->judge_count }} judge scores. Rankings and deductions become available after every judge scores each criterion for a participant.</p>
                    @endif
                </div>
                @if ($canEditScores)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($scoresAreFinalized)
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Admin override</span>
                        @endif
                        @if ($hasSavedScores)
                            <button form="deduction-form" type="submit" class="h-11 rounded-xl border border-amber-300 bg-amber-50 px-5 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Save deductions</button>
                        @endif
                        <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save all scores</button>
                    </div>
                @else
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Read only for auditors</span>
                @endif
            </div>

            @if ($event->participants->contains(fn ($participant) => $participant->parent_participant_id !== null))
                <div class="border-b border-slate-200 bg-maroon-50/50 px-6 py-4">
                    @if ($canEditScores)
                        <label class="inline-flex cursor-pointer items-start gap-3 text-sm font-semibold text-maroon-900">
                            <input type="checkbox" name="exclude_subteams" value="1" @checked(old('exclude_subteams')) class="mt-0.5 h-4 w-4 rounded border-maroon-300 text-maroon-700 focus:ring-maroon-600">
                            <span>Automatically exclude all linked subteams on save
                                <span class="mt-1 block text-xs font-normal text-slate-600">Marks teams such as TEAM A2 as No entry for this contest only, and clears their saved scores and deductions. Main teams remain entered.</span>
                            </span>
                        </label>
                        @error('exclude_subteams')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                    @else
                        <p class="text-sm text-slate-600">Linked subteams can be excluded by an administrator after finalization.</p>
                    @endif
                </div>
            @endif

            <div class="score-table-scroll" role="region" aria-label="Contest scoring table" tabindex="0">
                <table class="w-full min-w-[1020px] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            @if ($hasRankings)
                                <th id="rank-sort-heading" aria-sort="none" class="sticky left-0 z-20 w-20 min-w-20 bg-slate-50 px-2 py-3.5 text-center font-semibold">
                                    <button id="rank-sort-button" type="button" aria-label="Sort by rank ascending" title="Sort by rank ascending" class="inline-flex items-center justify-center gap-1 rounded-lg px-2 py-1 text-slate-600 transition hover:bg-maroon-50 hover:text-maroon-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-maroon-600">
                                        <span>Rank</span>
                                        <svg class="h-3.5 w-3.5 text-maroon-700" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path id="rank-sort-up" class="opacity-50" d="m5 7 3-3 3 3" />
                                            <path id="rank-sort-down" class="opacity-50" d="m5 9 3 3 3-3" />
                                        </svg>
                                    </button>
                                </th>
                            @endif
                            <th @class([
                                'sticky z-10 bg-slate-50 px-6 py-3.5 font-semibold',
                                'left-20' => $hasRankings,
                                'left-0' => ! $hasRankings,
                            ])>Participant</th>
                            <th class="px-5 py-3.5 text-center font-semibold">Has entry</th>
                            @if ($competition->usesCriteriaScoring())
                                @foreach ($competition->criteria as $criterion)
                                    <th class="px-4 py-3.5 text-center font-semibold">
                                        {{ $criterion->name }}
                                        <span class="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-slate-400">Max {{ number_format((float) $criterion->max_score, 2) }}</span>
                                    </th>
                                @endforeach
                            @else
                                <th class="px-6 py-3.5 font-semibold">{{ $competition->usesRankingScoring() ? 'Finishing rank' : 'Total wins' }}</th>
                            @endif
                            <th class="px-6 py-3.5 text-center font-semibold">Deduction</th>
                            <th class="px-6 py-3.5 text-right font-semibold">{{ $competition->usesCriteriaScoring() ? 'Adjusted total' : 'Rank points after deduction' }}</th>
                        </tr>
                    </thead>
                    <tbody id="competition-score-rows" class="divide-y divide-slate-100">
                        @foreach ($scoreParticipants as $participant)
                            @php
                                $result = $resultsByParticipant->get($participant->id);
                                $criterionScores = $result?->criterionScores->groupBy('criterion_id') ?? collect();
                                $criteriaSummary = $competition->usesCriteriaScoring() && $result
                                    ? $result->criteriaScoreSummary($competition)
                                    : null;
                                $standing = $standings->first(fn ($row) => $row['participant']->is($participant));
                                $entryField = "entries.{$participant->id}";
                                $hasEntry = (bool) old($entryField, $result?->has_entry ?? true);
                                $hasSavedScore = $competition->usesCriteriaScoring()
                                    ? ($criteriaSummary['rankable'] ?? false)
                                    : ($competition->usesRankingScoring() ? $result?->placement !== null : $result?->wins !== null);
                            @endphp
                            <tr data-rank="{{ $standing['rank'] ?? '' }}" class="align-middle hover:bg-slate-50/70">
                                @if ($hasRankings)
                                    <td class="sticky left-0 z-20 w-20 min-w-20 bg-white px-4 py-4 text-center font-bold text-maroon-700">{{ $standing ? '#'.$standing['rank'] : '—' }}</td>
                                @endif
                                <td @class([
                                    'sticky z-10 bg-white px-6 py-4',
                                    'left-20' => $hasRankings,
                                    'left-0' => ! $hasRankings,
                                ])>
                                    <div class="flex items-center gap-3">
                                        @if ($participant->profile_picture_path)
                                            <img src="{{ $participantImages->url($participant->profile_picture_path) }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                        @else
                                            <span class="grid h-9 w-9 place-items-center rounded-full bg-maroon-50 text-xs font-bold text-maroon-700">{{ strtoupper(substr($participant->name, 0, 1)) }}</span>
                                        @endif
                                        <span>
                                            <span class="block font-semibold text-slate-900">{{ $participant->name }}</span>
                                            <span class="block text-xs text-slate-400">{{ $participant->reference_no ?: 'No reference number' }}</span>
                                            @if ($participant->parent_participant_id !== null)
                                                <span class="mt-0.5 block text-xs font-medium text-maroon-700">Linked subteam</span>
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <input type="hidden" name="entries[{{ $participant->id }}]" value="0">
                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-maroon-300 hover:bg-maroon-50/50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
                                        <input name="entries[{{ $participant->id }}]" type="checkbox" value="1" @checked($hasEntry) @disabled(! $canEditScores) class="peer h-4 w-4 rounded border-slate-300 text-maroon-700 focus:ring-maroon-600">
                                        <span class="peer-checked:hidden">No entry</span>
                                        <span class="hidden peer-checked:inline">Entered</span>
                                    </label>
                                    @error($entryField)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </td>
                                @if ($competition->usesCriteriaScoring())
                                    @foreach ($competition->criteria as $criterion)
                                        <td class="px-4 py-4 text-center">
                                            @if ($competition->judge_count === 1)
                                                @php($field = "scores.{$participant->id}.{$criterion->id}")
                                                <input name="scores[{{ $participant->id }}][{{ $criterion->id }}]" type="number" min="0" max="{{ $criterion->max_score }}" step="0.01" value="{{ old($field, $criterionScores->get($criterion->id)?->firstWhere('judge_number', 1)?->score) }}" @disabled(! $canEditScores) class="h-10 w-28 rounded-lg border border-slate-300 px-3 text-center text-sm outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" aria-label="{{ $criterion->name }} score for {{ $participant->name }}">
                                                @error($field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                            @else
                                                <div class="grid gap-2">
                                                    @for ($judge = 1; $judge <= $competition->judge_count; $judge++)
                                                        @php($field = "scores.{$participant->id}.{$criterion->id}.{$judge}")
                                                        <label class="flex items-center justify-between gap-2 text-xs text-slate-600">
                                                            <span class="whitespace-nowrap">Judge {{ $judge }}</span>
                                                            <input name="scores[{{ $participant->id }}][{{ $criterion->id }}][{{ $judge }}]" type="number" min="0" max="{{ $criterion->max_score }}" step="0.01" value="{{ old($field, $criterionScores->get($criterion->id)?->firstWhere('judge_number', $judge)?->score) }}" @disabled(! $canEditScores) class="h-9 w-24 rounded-lg border border-slate-300 px-2 text-center text-sm outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" aria-label="Judge {{ $judge }} {{ $criterion->name }} score for {{ $participant->name }}">
                                                        </label>
                                                        @error($field)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                                    @endfor
                                                    <p class="border-t border-slate-100 pt-1 text-xs font-semibold text-maroon-800">Average: {{ isset($criteriaSummary['averages'][$criterion->id]) ? number_format($criteriaSummary['averages'][$criterion->id], 2) : '—' }}</p>
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                @elseif ($competition->usesRankingScoring())
                                    @php($field = "placements.{$participant->id}")
                                    <td class="px-6 py-4">
                                        <input name="placements[{{ $participant->id }}]" type="number" min="1" max="{{ $event->participants->count() }}" step="1" value="{{ old($field, $result?->placement) }}" @disabled(! $canEditScores) class="h-10 w-36 rounded-lg border border-slate-300 px-3 text-sm outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" aria-label="Finishing rank for {{ $participant->name }}">
                                        @error($field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </td>
                                @else
                                    @php($field = "wins.{$participant->id}")
                                    <td class="px-6 py-4">
                                        <input name="wins[{{ $participant->id }}]" type="number" min="0" max="1000000" step="1" value="{{ old($field, $result?->wins) }}" @disabled(! $canEditScores) class="h-10 w-36 rounded-lg border border-slate-300 px-3 text-sm outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" aria-label="Total wins for {{ $participant->name }}">
                                        @error($field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </td>
                                @endif
                                <td class="px-6 py-4 text-center">
                                    @if ($hasSavedScore && $result?->has_entry)
                                        @php($deductionField = "deductions.{$participant->id}")
                                        <input form="deduction-form" name="deductions[{{ $participant->id }}]" type="number" min="0" max="{{ min($competition->usesCriteriaScoring() ? ($standing['gross_score'] ?? 0) : ($standing['rank_points'] ?? 0), 999999.99) }}" step="0.01" value="{{ old($deductionField, $result?->deduction ?? 0) }}" @disabled(! $canEditScores) class="h-10 w-28 rounded-lg border border-amber-300 bg-amber-50/50 px-3 text-center text-sm outline-none transition disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-500 focus:border-amber-600 focus:ring-4 focus:ring-maroon-600/10" aria-label="Deduction for {{ $participant->name }}">
                                        @error($deductionField)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    @else
                                        <span class="text-xs text-slate-400">{{ $result && ! $result->has_entry ? 'No entry' : 'Save score first' }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right font-semibold text-slate-700">{{ $standing ? number_format($competition->usesCriteriaScoring() ? $standing['raw_score'] : $standing['leaderboard_points'], 2) : ($result && ! $result->has_entry ? 'Excluded' : '—') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canEditScores)
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    @if ($hasSavedScores)
                        <button form="deduction-form" type="submit" class="h-11 rounded-xl border border-amber-300 bg-amber-50 px-6 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Save deductions</button>
                    @endif
                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-6 text-sm font-semibold text-white transition hover:bg-maroon-800">Save all scores</button>
                </div>
            @endif
        </form>
        @endif
    @endif

    @if ($competition->usesCriteriaScoring() && $criterionStandings->isNotEmpty())
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-950">Best on each criterion</h3>
            <p class="mt-1 text-sm text-slate-500">Positions use each criterion's average across judges and the event tie rule. Participants without an entry or complete judge scores for a criterion are excluded.</p>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach ($criterionStandings as $breakdown)
                    <article class="overflow-hidden rounded-xl border border-slate-200">
                        <h4 class="border-b border-slate-200 bg-slate-50 px-4 py-3 font-semibold text-slate-900">{{ $breakdown['criterion']->name }}</h4>
                        @if ($breakdown['standings']->isNotEmpty())
                            <div class="score-table-scroll" role="region" aria-label="{{ $breakdown['criterion']->name }} rankings" tabindex="0">
                                <table class="w-full text-left text-sm">
                                    <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2">Rank</th><th class="px-4 py-2">Participant</th><th class="px-4 py-2 text-right">Average</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($breakdown['standings'] as $row)
                                            <tr><td class="px-4 py-3 font-bold text-maroon-700">#{{ $row['rank'] }}</td><td class="px-4 py-3">{{ $row['participant']->name }}</td><td class="px-4 py-3 text-right font-semibold">{{ number_format($row['score'], 2) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="px-4 py-6 text-sm text-slate-500">No complete scores yet.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const button = document.getElementById('rank-sort-button');
            const heading = document.getElementById('rank-sort-heading');
            const body = document.getElementById('competition-score-rows');

            if (!button || !heading || !body) {
                return;
            }

            const rows = Array.from(body.rows);
            const originalOrder = new Map(rows.map((row, index) => [row, index]));
            const upArrow = document.getElementById('rank-sort-up');
            const downArrow = document.getElementById('rank-sort-down');
            let direction = null;

            button.addEventListener('click', () => {
                direction = direction === 'ascending' ? 'descending' : 'ascending';

                rows.sort((left, right) => {
                    const leftHasRank = left.dataset.rank !== '';
                    const rightHasRank = right.dataset.rank !== '';

                    if (leftHasRank !== rightHasRank) {
                        return leftHasRank ? -1 : 1;
                    }

                    if (leftHasRank) {
                        const rankDifference = Number(left.dataset.rank) - Number(right.dataset.rank);

                        if (rankDifference !== 0) {
                            return direction === 'ascending' ? rankDifference : -rankDifference;
                        }
                    }

                    return originalOrder.get(left) - originalOrder.get(right);
                });

                body.append(...rows);
                heading.setAttribute('aria-sort', direction);

                const nextDirection = direction === 'ascending' ? 'descending' : 'ascending';
                button.setAttribute('aria-label', `Sort by rank ${nextDirection}`);
                button.title = `Sort by rank ${nextDirection}`;
                upArrow?.classList.toggle('opacity-50', direction !== 'ascending');
                downArrow?.classList.toggle('opacity-50', direction !== 'descending');
            });
        })();
    </script>
@endpush
