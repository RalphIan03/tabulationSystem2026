@php
    $standingsByParticipant = $standings->keyBy(fn ($row) => $row['participant']->getKey());
    $hasEnteredParticipants = $scoreParticipants->contains(fn ($participant) => $resultsByParticipant->get($participant->id)?->has_entry ?? true);
@endphp

<form id="overall-entries-form" method="POST" action="{{ route('events.categories.competitions.entries.update', [$event, $category, $competition], false) }}" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-loading-text="Saving participant entries…">
    @csrf
    @method('PATCH')
    <div class="flex flex-col gap-4 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-slate-950">Overall scores</h3>
            <p class="mt-1 text-sm text-slate-500">Judge columns show saved totals. The average, final rank, and points are calculated after all {{ $competition->judge_count }} judges complete every criterion.</p>
            <p class="mt-1 text-xs text-slate-500">Total score = average score minus deduction. Save entry changes here before submitting judge scores.</p>
            <p class="mt-1 text-xs text-slate-400">Tie ranking: {{ $event->tie_ranking_method->label() }}.</p>
        </div>
        @if ($canEditScores)
            <div class="flex shrink-0 flex-wrap gap-2">
                @if ($hasSavedScores)
                    <button type="submit" form="deduction-form" class="h-11 rounded-xl border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-800 hover:bg-amber-100">Save deductions</button>
                @endif
                <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-4 text-sm font-semibold text-white hover:bg-maroon-800">Save entries</button>
            </div>
        @else
            <span class="text-sm font-semibold text-emerald-700">Read only for auditors</span>
        @endif
    </div>
    @if ($canEditScores && $event->participants->contains(fn ($participant) => $participant->parent_participant_id !== null))
        <label class="flex cursor-pointer items-start gap-3 border-b border-slate-200 bg-maroon-50/50 px-6 py-4 text-sm font-semibold text-maroon-900">
            <input type="checkbox" name="exclude_subteams" value="1" @checked(old('exclude_subteams')) class="mt-0.5 rounded border-maroon-300 text-maroon-700 focus:ring-maroon-600">
            <span>Exclude all linked subteams when saving entries
                <span class="mt-1 block text-xs font-normal text-slate-600">Marks teams such as TEAM A2 as No entry for this contest. Saved judge scores are kept for later re-entry.</span>
            </span>
        </label>
    @endif
    @error('entries')<p role="alert" class="px-6 py-3 text-sm text-red-600">{{ $message }}</p>@enderror
    @error('exclude_subteams')<p role="alert" class="px-6 py-3 text-sm text-red-600">{{ $message }}</p>@enderror
    <div class="score-table-scroll" role="region" aria-label="Overall contest scores" tabindex="0">
        <table id="overall-score-table" class="w-full min-w-[1100px] text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th id="rank-sort-heading" scope="col" aria-sort="none" class="sticky left-0 z-20 w-20 min-w-20 bg-slate-50 px-2 py-3.5 text-center font-semibold">
                        @if ($hasRankings)
                            <button id="rank-sort-button" type="button" aria-label="Sort by rank ascending" title="Sort by rank ascending" class="inline-flex items-center justify-center gap-1 rounded-lg px-2 py-1 hover:bg-maroon-50 hover:text-maroon-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-maroon-600">
                                <span>Rank</span>
                                <svg class="h-3.5 w-3.5 text-maroon-700" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path id="rank-sort-up" class="opacity-50" d="m5 7 3-3 3 3" />
                                    <path id="rank-sort-down" class="opacity-50" d="m5 9 3 3 3-3" />
                                </svg>
                            </button>
                        @else
                            Rank
                        @endif
                    </th>
                    <th scope="col" class="sticky left-20 z-10 bg-slate-50 px-6 py-3.5 font-semibold">Participant</th>
                    <th scope="col" class="px-5 py-3.5 text-center font-semibold">Has entry</th>
                    @for ($judge = 1; $judge <= $competition->judge_count; $judge++)
                        <th scope="col" class="whitespace-nowrap px-5 py-3.5 text-right font-semibold">Judge {{ $judge }}</th>
                    @endfor
                    <th scope="col" class="px-5 py-3.5 text-right font-semibold">Average score</th>
                    <th scope="col" class="px-5 py-3.5 text-center font-semibold">Deduction</th>
                    <th scope="col" class="px-5 py-3.5 text-right font-semibold">Total score</th>
                    <th scope="col" class="px-5 py-3.5 text-right font-semibold">Total points granted</th>
                </tr>
            </thead>
            <tbody id="competition-score-rows" class="divide-y divide-slate-100">
                @foreach ($scoreParticipants as $participant)
                    @php
                        $result = $resultsByParticipant->get($participant->id);
                        $standing = $standingsByParticipant->get($participant->id);
                        $summary = $result?->criteriaScoreSummary($competition);
                        $entryField = "entries.{$participant->id}";
                        $hasEntry = (bool) old($entryField, $result?->has_entry ?? true);
                    @endphp
                    <tr data-rank="{{ $standing['rank'] ?? '' }}" class="hover:bg-slate-50/70">
                        <td class="sticky left-0 z-20 w-20 min-w-20 bg-white px-4 py-4 text-center font-bold text-maroon-700">{{ $standing ? '#'.$standing['rank'] : '—' }}</td>
                        <td class="sticky left-20 z-10 bg-white px-6 py-4">
                            <div class="flex items-center gap-3">
                                @if ($participant->profile_picture_path)
                                    <img src="{{ $participantImages->url($participant->profile_picture_path) }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                                @endif
                                <span>
                                    <span class="block font-semibold text-slate-900">{{ $participant->name }}</span>
                                    <span class="block text-xs text-slate-400">{{ $participant->reference_no ?: 'No reference number' }}</span>
                                </span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-center">
                            <input type="hidden" name="entries[{{ $participant->id }}]" value="0">
                            <label class="inline-flex items-center gap-2 whitespace-nowrap text-xs font-semibold text-slate-700">
                                <input name="entries[{{ $participant->id }}]" type="checkbox" value="1" @checked($hasEntry) @disabled(! $canEditScores) class="peer rounded border-slate-300 text-maroon-700 focus:ring-maroon-600">
                                <span class="peer-checked:hidden">No entry</span>
                                <span class="hidden peer-checked:inline">Entered</span>
                            </label>
                            @error($entryField)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </td>
                        @for ($judge = 1; $judge <= $competition->judge_count; $judge++)
                            @php
                                $judgeTotal = $hasEntry ? $result?->judgeScoreTotal($competition, $judge) : null;
                            @endphp
                            <td class="px-5 py-4 text-right tabular-nums">{{ $judgeTotal !== null ? number_format($judgeTotal, 2) : ($hasEntry ? 'Pending' : '—') }}</td>
                        @endfor
                        <td class="px-5 py-4 text-right tabular-nums">{{ $hasEntry && ($summary['rankable'] ?? false) ? number_format($summary['gross'], 2) : ($hasEntry ? 'Pending' : '—') }}</td>
                        <td class="px-5 py-4 text-center">
                            @if ($hasEntry && $result?->has_entry && ($summary['rankable'] ?? false))
                                @php
                                    $deductionField = "deductions.{$participant->id}";
                                @endphp
                                <input form="deduction-form" name="deductions[{{ $participant->id }}]" type="number" min="0" max="{{ min($summary['gross'], 999999.99) }}" step="0.01" value="{{ old($deductionField, $result->deduction ?? 0) }}" @disabled(! $canEditScores) aria-label="Deduction for {{ $participant->name }}" class="h-10 w-28 rounded-lg border border-amber-300 bg-amber-50/50 px-3 text-center text-sm outline-none disabled:cursor-not-allowed disabled:bg-slate-100 focus:border-amber-600 focus:ring-4 focus:ring-maroon-600/10">
                                @error($deductionField)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            @else
                                <span class="text-xs text-slate-400">{{ $hasEntry ? 'Save score first' : 'No entry' }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-right font-semibold tabular-nums text-slate-800">{{ $standing ? number_format($standing['raw_score'], 2) : ($hasEntry ? 'Pending' : 'Excluded') }}</td>
                        <td class="px-5 py-4 text-right font-bold tabular-nums text-maroon-700">{{ $standing ? number_format($standing['leaderboard_points'], 2) : ($hasEntry ? '—' : '0.00') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</form>

@error('judge_number')<p role="alert" class="mt-4 text-sm text-red-600">{{ $message }}</p>@enderror
@error('scores')<p role="alert" class="mt-4 text-sm text-red-600">{{ $message }}</p>@enderror

@for ($judge = 1; $judge <= $competition->judge_count; $judge++)
    <form id="judge-{{ $judge }}-scores" method="POST" action="{{ route('events.categories.competitions.scores.update', [$event, $category, $competition], false) }}" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-loading-text="Saving Judge {{ $judge }} scores…">
        @csrf
        @method('PATCH')
        <input type="hidden" name="judge_number" value="{{ $judge }}">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-950">Judge {{ $judge }} scores</h3>
                <p class="mt-1 text-sm text-slate-500">Enter criterion scores for this judge. Saving updates this judge only. Deductions are managed in Overall scores above.</p>
            </div>
            @if ($canEditScores)
                <button type="submit" @disabled(! $hasEnteredParticipants) class="h-11 shrink-0 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white hover:bg-maroon-800 disabled:cursor-not-allowed disabled:opacity-50">Save Judge {{ $judge }} scores</button>
            @endif
        </div>
        <div class="score-table-scroll" role="region" aria-label="Judge {{ $judge }} criterion scores" tabindex="0">
            <table id="judge-{{ $judge }}-table" class="w-full min-w-[780px] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th scope="col" class="sticky left-0 z-10 bg-slate-50 px-6 py-3.5 font-semibold">Participant</th>
                        @foreach ($competition->criteria as $criterion)
                            <th scope="col" class="px-4 py-3.5 text-center font-semibold">
                                {{ $criterion->name }}
                                <span class="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-slate-400">Max {{ number_format((float) $criterion->max_score, 2) }}</span>
                            </th>
                        @endforeach
                        <th scope="col" class="px-6 py-3.5 text-right font-semibold">Saved total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($scoreParticipants as $participant)
                        @php
                            $result = $resultsByParticipant->get($participant->id);
                            $hasEntry = $result?->has_entry ?? true;
                            $savedScores = $result?->criterionScores->where('judge_number', $judge)->keyBy('criterion_id');
                            $judgeTotal = $hasEntry ? $result?->judgeScoreTotal($competition, $judge) : null;
                        @endphp
                        <tr class="hover:bg-slate-50/70">
                            <td class="sticky left-0 z-10 bg-white px-6 py-4 font-semibold text-slate-900">
                                {{ $participant->name }}
                                @unless ($hasEntry)<span class="mt-1 block text-xs font-normal text-slate-400">No entry</span>@endunless
                            </td>
                            @foreach ($competition->criteria as $criterion)
                                @php
                                    $field = "scores.{$participant->id}.{$criterion->id}.{$judge}";
                                @endphp
                                <td class="px-4 py-4 text-center">
                                    <input name="scores[{{ $participant->id }}][{{ $criterion->id }}][{{ $judge }}]" type="number" min="0" max="{{ $criterion->max_score }}" step="0.01" value="{{ old($field, $savedScores?->get($criterion->id)?->score) }}" @disabled(! $canEditScores || ! $hasEntry) aria-label="Judge {{ $judge }} {{ $criterion->name }} score for {{ $participant->name }}" class="h-10 w-28 rounded-lg border border-slate-300 px-3 text-center text-sm outline-none disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                    @error($field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </td>
                            @endforeach
                            <td class="px-6 py-4 text-right font-semibold tabular-nums text-maroon-700">{{ $judgeTotal !== null ? number_format($judgeTotal, 2) : ($hasEntry ? 'Pending' : 'No entry') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>
@endfor
