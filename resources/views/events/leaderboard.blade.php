@extends('layouts.admin')

@section('title', 'Leaderboard · '.$event->name)
@section('heading', 'Current leaderboard')

@section('content')
    <nav class="mb-6 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('events.index') }}" class="font-medium transition hover:text-maroon-700">Events</a>
        <span>/</span>
        <a href="{{ route('events.show', $event) }}" class="font-medium transition hover:text-maroon-700">{{ $event->name }}</a>
        <span>/</span>
        <span class="font-medium text-slate-800">Leaderboard</span>
    </nav>

    <section class="overflow-hidden rounded-2xl bg-gradient-to-br from-maroon-700 to-maroon-950 text-white shadow-xl shadow-maroon-950/10">
        <div class="flex flex-col gap-6 p-7 sm:p-9 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">Current scoring</span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">Ties: {{ $event->tie_ranking_method->label() }}</span>
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-amber-400/15 text-amber-200 ring-amber-300/20' => $event->leaderboard_frozen,
                        'bg-emerald-400/15 text-emerald-200 ring-emerald-300/20' => ! $event->leaderboard_frozen,
                    ])>Public leaderboard {{ $event->leaderboard_frozen ? 'frozen' : 'live' }}</span>
                </div>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $event->name }}</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-maroon-100/80">See each contest’s current ranking and overall points. Overall standings use highest net points while frozen, and contest wins while live.</p>
            </div>
            <a href="{{ route('events.show', $event) }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-white px-5 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-50">Back to event</a>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="font-semibold text-slate-950">Share the public leaderboard</h3>
        <p class="mt-1 text-sm text-slate-500">The general link opens the active event automatically. Use the event-specific link to share this event directly. Freezing shows only total points publicly; unfreezing shows team names and wins instead.</p>
        <a href="{{ route('leaderboards.index') }}" target="_blank" rel="noopener noreferrer" class="mt-3 block break-all text-sm font-semibold text-maroon-700 hover:underline">{{ route('leaderboards.index') }}</a>
        <a href="{{ route('leaderboards.show', $event) }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-xs font-medium text-slate-500 hover:underline">Event-specific: {{ route('leaderboards.show', $event) }}</a>
    </section>

    <section id="overall-deductions" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="POST" action="{{ route('events.overall-deductions.update', $event, false) }}" data-loading-text="Saving overall deductions…">
            @csrf
            @method('PATCH')
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                <div>
                    <h3 class="text-lg font-semibold text-slate-950">Overall point deductions</h3>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">Deduct points once from each main team’s event total, including points from linked teams. Contest scores and wins are unchanged. Frozen overall standings use net points; live standings use contest wins, with net points breaking ties.</p>
                    <p class="mt-1 text-xs text-slate-500">Staff totals include pending contest points; public totals include finalized contests only. Net points cannot fall below zero. Enter 0 or leave blank to remove a deduction. Totals refresh after saving.</p>
                </div>
                @if ($deductionParticipants->isNotEmpty())
                    <button type="submit" class="h-11 shrink-0 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save overall deductions</button>
                @endif
            </div>
            @error('deductions')<p role="alert" class="px-6 py-3 text-sm text-red-600">{{ $message }}</p>@enderror
            @if ($deductionParticipants->isNotEmpty())
                <div class="score-table-scroll" role="region" aria-label="Overall event point deductions" tabindex="0">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th scope="col" class="px-6 py-3.5 font-semibold">Main team / participant</th>
                                <th scope="col" class="px-5 py-3.5 text-right font-semibold">Earned points</th>
                                <th scope="col" class="px-5 py-3.5 text-center font-semibold">Overall deduction</th>
                                <th scope="col" class="px-6 py-3.5 text-right font-semibold">Net overall points</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($deductionParticipants as $participant)
                                @php
                                    $overall = $overallPointsByParticipant->get($participant->id);
                                    $grossPoints = (float) ($overall['gross_points'] ?? 0);
                                    $deduction = (float) ($participant->overall_deduction ?? 0);
                                    $netPoints = $overall['leaderboard_points'] ?? max(0, $grossPoints - $deduction);
                                    $field = "deductions.{$participant->id}";
                                @endphp
                                <tr data-overall-deduction-row="{{ $participant->id }}">
                                    <td class="px-6 py-4 font-semibold text-slate-900">{{ $participant->name }}</td>
                                    <td class="px-5 py-4 text-right tabular-nums">{{ number_format($grossPoints, 2) }}</td>
                                    <td class="px-5 py-4 text-center">
                                        <input name="deductions[{{ $participant->id }}]" type="number" min="0" max="9999999999.99" step="0.01" value="{{ old($field, $deduction) }}" aria-label="Overall deduction for {{ $participant->name }}" class="h-10 w-36 rounded-lg border border-amber-300 bg-amber-50/50 px-3 text-center text-sm outline-none focus:border-amber-600 focus:ring-4 focus:ring-maroon-600/10">
                                        @error($field)<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </td>
                                    <td class="px-6 py-4 text-right font-bold tabular-nums text-maroon-700">{{ number_format($netPoints, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-6 py-8 text-sm text-slate-500">Add a main team or participant to the event before entering overall deductions.</p>
            @endif
        </form>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
            <div>
                <p class="text-sm font-semibold text-maroon-700">Overall winner</p>
                <h3 class="mt-1 text-xl font-semibold text-slate-950">{{ $event->leaderboard_frozen ? 'Overall standings by net points' : 'Overall standings by contest wins' }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $event->leaderboard_frozen ? 'Highest points after all contest and overall deductions receive the highest rank.' : 'First-place finishes are summed across contests and shown by category. Recorded match wins and net leaderboard points after overall deductions break ties.' }}</p>
                <p class="mt-1 text-xs text-amber-700">Pending points are provisional and hidden from the public leaderboard. Current net points include both finalized and pending points.</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a href="{{ route('events.results.export', [$event, 'format' => 'xlsx'], false) }}" download data-export-download data-loading-text="Exporting overall Excel report…" class="inline-flex h-10 items-center justify-center rounded-xl bg-maroon-700 px-4 text-sm font-semibold text-white hover:bg-maroon-800">Overall Excel</a>
                <a href="{{ route('events.results.export', [$event, 'format' => 'pdf'], false) }}" download data-export-download data-loading-text="Exporting overall PDF report…" class="inline-flex h-10 items-center justify-center rounded-xl border border-maroon-200 px-4 text-sm font-semibold text-maroon-700 hover:bg-maroon-50">Overall PDF</a>
            </div>
        </div>

        @if ($overallStandings->isNotEmpty())
            @php($winner = $overallStandings->first())
            <div class="border-b border-maroon-100 bg-maroon-50/60 px-6 py-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        <span class="grid h-12 w-12 place-items-center rounded-xl bg-maroon-700 text-lg font-bold text-white">1</span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-maroon-600">Current overall leader</p>
                            <p class="mt-1 text-xl font-semibold text-maroon-950">{{ $winner['participant']->name }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <span class="rounded-xl bg-white px-4 py-2 font-semibold text-maroon-900 shadow-sm">{{ $winner['contest_wins'] }} {{ Str::plural('contest win', $winner['contest_wins']) }}</span>
                        <span class="rounded-xl bg-white px-4 py-2 font-semibold text-slate-700 shadow-sm">{{ number_format($winner['leaderboard_points'], 2) }} pts</span>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table id="overall-standings-table" class="w-full min-w-[760px] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-6 py-3.5 font-semibold">Rank</th>
                            <th class="px-5 py-3.5 font-semibold">Participant</th>
                            <th class="px-5 py-3.5 text-center font-semibold">Contest wins</th>
                            @foreach ($event->categories as $category)
                                <th class="px-5 py-3.5 text-center font-semibold">{{ $category->name }} wins</th>
                            @endforeach
                            <th class="px-5 py-3.5 text-center font-semibold">Recorded wins</th>
                            <th class="px-5 py-3.5 text-center font-semibold">Contests entered</th>
                            <th class="px-5 py-3.5 text-right font-semibold">Finalized points</th>
                            <th class="px-5 py-3.5 text-right font-semibold text-amber-700">Pending points</th>
                            <th class="px-6 py-3.5 text-right font-semibold">Net points</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($overallStandings as $index => $row)
                            <tr @class(['bg-maroon-50/30' => $index === 0])>
                                <td class="px-6 py-4 font-bold text-slate-700">#{{ $index + 1 }}</td>
                                <td class="px-5 py-4 font-semibold text-slate-900">{{ $row['participant']->name }}</td>
                                <td class="px-5 py-4 text-center font-semibold text-maroon-700">{{ $row['contest_wins'] }}</td>
                                @foreach ($event->categories as $category)
                                    <td class="px-5 py-4 text-center text-slate-600">{{ $row['category_wins'][$category->id] ?? 0 }}</td>
                                @endforeach
                                <td class="px-5 py-4 text-center text-slate-600">{{ $row['recorded_wins'] }}</td>
                                <td class="px-5 py-4 text-center text-slate-600">{{ $row['contests_entered'] }}</td>
                                <td class="px-5 py-4 text-right tabular-nums text-slate-700">{{ number_format($row['finalized_points'], 2) }}</td>
                                <td class="px-5 py-4 text-right font-semibold tabular-nums text-amber-700">{{ number_format($row['pending_points'], 2) }}</td>
                                <td class="px-6 py-4 text-right font-semibold tabular-nums text-slate-700">{{ number_format($row['leaderboard_points'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="px-6 py-14 text-center">
                <p class="font-semibold text-slate-700">No scores have been entered yet.</p>
                <p class="mt-1 text-sm text-slate-400">Use a contest's Enter scores button to begin building the leaderboard.</p>
            </div>
        @endif
    </section>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-xl font-semibold text-slate-950">Contest standings</h3>
        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">Category
            <select id="contest-category-filter" class="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm focus:border-maroon-600 focus:ring-maroon-600">
                <option value="all">All categories</option>
                @foreach ($event->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <div class="mt-4 space-y-6">
        @forelse ($event->categories as $category)
            <section data-category-card="{{ $category->id }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-950">{{ $category->name }}</h3>
                    <p class="mt-1 text-sm text-slate-500">Current scores for {{ $category->competitions->count() }} {{ Str::plural('contest', $category->competitions->count()) }}.</p>
                </div>

                <div class="grid gap-5 p-5 xl:grid-cols-2">
                    @forelse ($category->competitions as $competition)
                        @php($standings = $standingsByCompetition[$competition->id])
                        <article class="overflow-hidden rounded-xl border border-slate-200">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                                <div>
                                    <h4 class="font-semibold text-slate-900">{{ $competition->name }}</h4>
                                    <p class="mt-0.5 text-xs text-slate-400">{{ $competition->scoring_method->label() }}</p>
                                    @if (! $competition->scoresAreFinalized() && $competition->hasSavedScores())
                                        <span class="mt-2 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending finalization</span>
                                    @endif
                                </div>
                                <a href="{{ route('events.categories.competitions.scores.edit', [$event, $category, $competition]) }}" class="shrink-0 text-xs font-semibold text-maroon-700 hover:text-maroon-900">Enter scores</a>
                            </div>

                            @if ($standings->isNotEmpty())
                                <div class="overflow-x-auto"><table class="w-full min-w-[440px] text-left text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2">Rank</th><th class="px-4 py-2">Participant</th><th class="px-4 py-2 text-right">{{ $competition->usesCriteriaScoring() ? 'Score' : ($competition->usesRankingScoring() ? 'Placement' : 'Wins') }}</th><th class="px-4 py-2 text-right">Points</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($standings as $row)
                                            <tr><td class="px-4 py-3 font-bold text-maroon-700">#{{ $row['rank'] }}</td><td class="px-4 py-3 font-medium text-slate-800">{{ $row['participant']->name }}</td><td class="px-4 py-3 text-right">{{ $competition->usesCriteriaScoring() ? number_format($row['raw_score'], 2) : ($competition->usesRankingScoring() ? '#'.$row['placement'] : $row['wins']) }}</td><td class="px-4 py-3 text-right font-semibold">{{ number_format($row['leaderboard_points'], 2) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table></div>
                            @else
                                <p class="px-5 py-8 text-center text-sm text-slate-400">No scores entered.</p>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-400">No contests in this category.</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
                <p class="font-semibold text-slate-700">No categories or contests configured.</p>
            </section>
        @endforelse
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('contest-category-filter')?.addEventListener('change', (event) => {
            document.querySelectorAll('[data-category-card]').forEach((card) => {
                card.classList.toggle('hidden', event.target.value !== 'all' && card.dataset.categoryCard !== event.target.value);
            });
        });
    </script>
@endpush
