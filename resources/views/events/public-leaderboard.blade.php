<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event?->name ?? 'Leaderboards' }} | Public leaderboard</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-12">
        <div class="mb-5 flex items-center gap-3">
            <img src="{{ asset('images/um_logo.png') }}" alt="University of Mindanao" class="h-9 w-9 object-contain">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-maroon-700">Tabulation System</p>
        </div>

        <header class="rounded-3xl bg-gradient-to-br from-maroon-700 to-maroon-950 p-7 text-white shadow-xl shadow-maroon-950/10 sm:p-10">
            <p class="text-sm font-semibold text-maroon-200">Event leaderboard</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-5xl">{{ $event?->name ?? 'Leaderboards' }}</h1>
            @if ($event)
                <p class="mt-3 text-sm text-maroon-100/80">{{ $event->start_date->format('F j, Y') }} – {{ $event->end_date->format('F j, Y') }}</p>
                <p id="leaderboard-status" class="mt-5 inline-flex rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white" aria-live="polite">{{ $event->leaderboard_frozen ? 'Public results frozen' : (count($initialRows) ? 'Live finalized results' : 'Results pending') }}</p>
            @else
                <p class="mt-3 text-sm text-maroon-100/80">No events are available yet. Please check back later.</p>
            @endif
        </header>

        @if ($event)
        <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Leaderboard results">
            <div class="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-3">
                <div>
                    <label for="leaderboard-scope" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">View</label>
                    <select id="leaderboard-scope" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm focus:border-maroon-600 focus:ring-maroon-600">
                        <option value="overall">Overall</option>
                        <option value="category">By category</option>
                        <option value="game">By game</option>
                    </select>
                </div>
                <div id="category-filter" class="hidden">
                    <label for="leaderboard-category" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Category</label>
                    <select id="leaderboard-category" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm focus:border-maroon-600 focus:ring-maroon-600">
                        @foreach ($event->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div id="game-filter" class="hidden">
                    <label for="leaderboard-game" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Game</label>
                    <select id="leaderboard-game" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm focus:border-maroon-600 focus:ring-maroon-600">
                        @foreach ($event->categories as $category)
                            @foreach ($category->competitions as $competition)
                                <option value="{{ $competition->id }}" data-scoring-method="{{ $competition->scoring_method->value }}">{{ $category->name }} · {{ $competition->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
            </div>

            <div id="frozen-message" @class(['border-b border-amber-200 bg-amber-50 px-6 py-4 text-sm font-semibold text-amber-900', 'hidden' => ! $event->leaderboard_frozen]) role="status">The public ranking table is frozen. Check back later.</div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-6 py-4">Rank</th>
                            <th scope="col" class="px-6 py-4">Participant</th>
                            <th id="result-heading" scope="col" class="px-6 py-4 text-right">Contest wins</th>
                            <th scope="col" class="px-6 py-4 text-right">Points</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboard-rows" class="divide-y divide-slate-100">
                        @if ($event->leaderboard_frozen)
                            <tr class="select-none blur-sm"><td colspan="4" class="px-6 py-10 text-center text-slate-500">Results hidden until unfrozen</td></tr>
                        @else
                        @forelse ($initialRows as $row)
                            <tr>
                                <td class="px-6 py-4 font-bold text-maroon-700">#{{ $row['rank'] }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $row['profile_image_url'] ?: asset('images/participant-placeholder.svg') }}" alt="{{ $row['profile_image_url'] ? $row['name'].' profile picture' : 'Participant photo placeholder' }}" onerror="this.onerror=null;this.src='{{ asset('images/participant-placeholder.svg') }}';this.alt='Participant photo placeholder'" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200">
                                        <span>
                                            <span class="block font-semibold text-slate-900">{{ $row['name'] }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right text-slate-600">{{ $row['contest_wins'] }}</td>
                                <td class="px-6 py-4 text-right font-semibold">{{ number_format($row['points'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-10 text-center text-slate-500">Results pending. No contest has been finalized yet.</td></tr>
                        @endforelse
                        @endif
                    </tbody>
                </table>
            </div>
        </section>
        <p class="mt-4 text-center text-xs text-slate-500">Only finalized contest results appear here. This page updates automatically.</p>
        @endif
    </main>

    @if ($event)
    <script>
        (() => {
            const dataUrl = @json($dataUrl);
            const scope = document.getElementById('leaderboard-scope');
            const category = document.getElementById('leaderboard-category');
            const game = document.getElementById('leaderboard-game');
            const rows = document.getElementById('leaderboard-rows');
            const status = document.getElementById('leaderboard-status');
            const frozenMessage = document.getElementById('frozen-message');
            const resultHeading = document.getElementById('result-heading');
            const participantPlaceholderUrl = @json(asset('images/participant-placeholder.svg'));
            let currentRequest;

            function showMessage(message, frozen = false) {
                rows.replaceChildren();
                const row = document.createElement('tr');
                if (frozen) row.className = 'select-none blur-sm';
                const cell = document.createElement('td');
                cell.colSpan = 4;
                cell.className = 'px-6 py-10 text-center text-slate-500';
                cell.textContent = message;
                row.append(cell);
                rows.append(row);
            }

            function addCell(row, value, className) {
                const cell = document.createElement('td');
                cell.className = className;
                cell.textContent = value;
                row.append(cell);
            }

            function addParticipantCell(row, result) {
                const cell = document.createElement('td');
                cell.className = 'px-6 py-4';
                const content = document.createElement('div');
                content.className = 'flex items-center gap-3';

                const photo = document.createElement('img');
                photo.src = result.profile_image_url || participantPlaceholderUrl;
                photo.alt = result.profile_image_url ? `${result.name} profile picture` : 'Participant photo placeholder';
                photo.className = 'h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200';
                photo.onerror = () => {
                    photo.onerror = null;
                    photo.src = participantPlaceholderUrl;
                    photo.alt = 'Participant photo placeholder';
                };
                content.append(photo);

                const details = document.createElement('span');
                const name = document.createElement('span');
                name.className = 'block font-semibold text-slate-900';
                name.textContent = result.name;
                details.append(name);

                content.append(details);
                cell.append(content);
                row.append(cell);
            }

            async function refresh(force = false) {
                if (document.hidden || (currentRequest && !force)) return;
                currentRequest?.abort();
                currentRequest = undefined;
                const selectedScope = scope.value;
                document.getElementById('category-filter').classList.toggle('hidden', selectedScope !== 'category');
                document.getElementById('game-filter').classList.toggle('hidden', selectedScope !== 'game');
                resultHeading.textContent = selectedScope === 'game'
                    ? (game.selectedOptions[0]?.dataset.scoringMethod === 'wins' ? 'Wins' : 'Score')
                    : 'Contest wins';

                if ((selectedScope === 'category' && !category.value) || (selectedScope === 'game' && !game.value)) {
                    frozenMessage.classList.add('hidden');
                    status.textContent = 'Results pending';
                    showMessage('No categories or games have been configured.');
                    return;
                }

                const request = new AbortController();
                currentRequest = request;

                const url = new URL(dataUrl);
                url.searchParams.set('scope', selectedScope);
                if (selectedScope === 'category') url.searchParams.set('category_id', category.value);
                if (selectedScope === 'game') url.searchParams.set('competition_id', game.value);

                try {
                    const response = await fetch(url, {
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                        signal: request.signal,
                    });
                    if (!response.ok) throw new Error('Unable to load results');
                    const data = await response.json();
                    frozenMessage.classList.toggle('hidden', !data.frozen);

                    if (data.frozen) {
                        status.textContent = 'Public results frozen';
                        showMessage('Results hidden until unfrozen', true);
                    } else if (!data.rows.length) {
                        status.textContent = 'Results pending';
                        showMessage('Results pending. No contest has been finalized yet.');
                    } else {
                        status.textContent = 'Live finalized results';
                        rows.replaceChildren();
                        for (const result of data.rows) {
                            const row = document.createElement('tr');
                            addCell(row, `#${result.rank}`, 'px-6 py-4 font-bold text-maroon-700');
                            addParticipantCell(row, result);
                            addCell(row, selectedScope === 'game' ? result.result : result.contest_wins, 'px-6 py-4 text-right text-slate-600');
                            addCell(row, Number(result.points).toFixed(2), 'px-6 py-4 text-right font-semibold');
                            rows.append(row);
                        }
                    }
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    status.textContent = 'Connection problem';
                    showMessage('Could not load results. Retrying shortly.');
                } finally {
                    if (currentRequest === request) currentRequest = undefined;
                }
            }

            [scope, category, game].forEach(element => element.addEventListener('change', () => refresh(true)));
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refresh(true);
            });
            setInterval(refresh, 5000);
        })();
    </script>
    @endif
</body>
</html>
