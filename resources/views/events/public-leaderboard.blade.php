<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event?->name ?? 'Leaderboards' }} | Public leaderboard</title>
    <link rel="icon" type="image/png" href="/images/um_logo.png">
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
            <div class="border-b border-slate-200 p-5">
                <h2 class="text-lg font-semibold text-slate-950">Overall score</h2>
                <p class="mt-1 text-sm text-slate-500">Contest wins and points from finalized contests across all categories.</p>
                <input type="hidden" name="scope" value="overall">
            </div>

            <div id="frozen-message" @class(['border-b border-amber-200 bg-amber-50 px-6 py-4 text-sm font-semibold text-amber-900', 'hidden' => ! $event->leaderboard_frozen]) role="status">The public ranking table is frozen. Check back later.</div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-6 py-4">Rank</th>
                            <th scope="col" class="px-6 py-4">Participant</th>
                            <th scope="col" class="px-6 py-4 text-right">Contest wins</th>
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
        <section class="mt-6" aria-label="Contest results">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-xl font-semibold text-slate-950">Contests by category</h2><p class="mt-1 text-sm text-slate-500">Browse every contest by category. Rankings appear after scores are finalized. Choose a category and click Apply filter to load it.</p></div>
                <form id="contest-filter-form" class="flex flex-wrap items-end gap-3">
                    <label class="grid gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Category filter
                        <select id="contest-category-filter" class="h-11 min-w-48 rounded-xl border border-slate-300 bg-white px-3 text-sm normal-case focus:border-maroon-600 focus:ring-maroon-600">
                            <option value="all">All categories</option>
                            @foreach ($event->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button id="apply-contest-filter" type="submit" class="inline-flex h-11 items-center justify-center rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-maroon-600/20 disabled:cursor-wait disabled:opacity-60">Apply filter</button>
                </form>
            </div>
            <div id="contest-cards" class="mt-4 space-y-6">
                @foreach ($initialCategories as $category)
                    @if (count($category['contests']))
                        <section data-category-card="{{ $category['id'] }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <h3 class="border-b border-slate-200 bg-slate-50 px-6 py-4 text-lg font-semibold">{{ $category['name'] }}</h3>
                            <div class="grid gap-5 p-5 xl:grid-cols-2">
                                @foreach ($category['contests'] as $contest)
                                    <article data-contest-card="{{ $contest['id'] }}" class="overflow-hidden rounded-xl border border-slate-200">
                                        <h4 class="border-b border-slate-200 px-5 py-4 font-semibold">{{ $contest['name'] }}</h4>
                                        @if (! $contest['finalized'])
                                            <p class="px-5 py-8 text-center text-sm text-slate-500">No finalized score</p>
                                        @else
                                            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th scope="col" class="px-4 py-3">Rank</th><th scope="col" class="px-4 py-3">Participant</th></tr></thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @forelse ($contest['rows'] as $row)
                                                        <tr>
                                                            <td class="px-4 py-3 font-bold text-maroon-700">#{{ $row['rank'] }}</td>
                                                            <td class="px-4 py-3 font-medium">
                                                                <span class="flex items-center gap-3">
                                                                    <img src="{{ $row['profile_image_url'] ?: asset('images/participant-placeholder.svg') }}" alt="{{ $row['profile_image_url'] ? $row['name'].' profile picture' : 'Participant photo placeholder' }}" onerror="this.onerror=null;this.src='{{ asset('images/participant-placeholder.svg') }}';this.alt='Participant photo placeholder'" class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200">
                                                                    {{ $row['name'] }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="2" class="px-4 py-6 text-center text-slate-500">No ranked participants yet.</td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table></div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            </div>
        </section>
        <p class="mt-4 text-center text-xs text-slate-500">Contest rankings appear after scores are finalized. This page updates automatically.</p>
        @endif
    </main>

    @if ($event)
    <script>
        (() => {
            const dataUrl = @json($dataUrl);
            const initialCategories = @json($initialCategories);
            const filterForm = document.getElementById('contest-filter-form');
            const categoryFilter = document.getElementById('contest-category-filter');
            const applyFilterButton = document.getElementById('apply-contest-filter');
            const contestCards = document.getElementById('contest-cards');
            const rows = document.getElementById('leaderboard-rows');
            const status = document.getElementById('leaderboard-status');
            const frozenMessage = document.getElementById('frozen-message');
            const participantPlaceholderUrl = @json(asset('images/participant-placeholder.svg'));
            let currentRequest;
            let appliedCategoryId = 'all';

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

            function renderCards(categories) {
                contestCards.replaceChildren();

                for (const category of categories) {
                    if (category.contests.length === 0) continue;
                    const section = document.createElement('section');
                    section.dataset.categoryCard = String(category.id);
                    section.className = 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm';
                    const heading = document.createElement('h3');
                    heading.className = 'border-b border-slate-200 bg-slate-50 px-6 py-4 text-lg font-semibold';
                    heading.textContent = category.name;
                    section.append(heading);
                    const grid = document.createElement('div');
                    grid.className = 'grid gap-5 p-5 xl:grid-cols-2';

                    for (const contest of category.contests) {
                        const card = document.createElement('article');
                        card.dataset.contestCard = String(contest.id);
                        card.className = 'overflow-hidden rounded-xl border border-slate-200';
                        const title = document.createElement('h4');
                        title.className = 'border-b border-slate-200 px-5 py-4 font-semibold';
                        title.textContent = contest.name;
                        card.append(title);

                        if (!contest.finalized) {
                            const pending = document.createElement('p');
                            pending.className = 'px-5 py-8 text-center text-sm text-slate-500';
                            pending.textContent = 'No finalized score';
                            card.append(pending);
                            grid.append(card);
                            continue;
                        }

                        const wrapper = document.createElement('div');
                        wrapper.className = 'overflow-x-auto';
                        const table = document.createElement('table');
                        table.className = 'w-full text-left text-sm';
                        const thead = document.createElement('thead');
                        thead.className = 'bg-slate-50 text-xs uppercase tracking-wide text-slate-500';
                        const header = document.createElement('tr');
                        for (const label of ['Rank', 'Participant']) {
                            const th = document.createElement('th');
                            th.className = 'px-4 py-3';
                            th.textContent = label;
                            header.append(th);
                        }
                        thead.append(header);
                        table.append(thead);
                        const body = document.createElement('tbody');
                        body.className = 'divide-y divide-slate-100';

                        for (const result of contest.rows) {
                            const row = document.createElement('tr');
                            addCell(row, `#${result.rank}`, 'px-4 py-3 font-bold text-maroon-700');
                            addParticipantCell(row, result);
                            body.append(row);
                        }

                        if (!contest.rows.length) {
                            const row = document.createElement('tr');
                            const cell = document.createElement('td');
                            cell.colSpan = 2;
                            cell.className = 'px-4 py-6 text-center text-slate-500';
                            cell.textContent = 'No ranked participants yet.';
                            row.append(cell);
                            body.append(row);
                        }

                        table.append(body);
                        wrapper.append(table);
                        card.append(wrapper);
                        grid.append(card);
                    }

                    section.append(grid);
                    contestCards.append(section);
                }

                if (!contestCards.childElementCount) {
                    const empty = document.createElement('p');
                    empty.className = 'rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-500';
                    empty.textContent = 'No contests available.';
                    contestCards.append(empty);
                }

            }

            async function refresh(force = false, showFilterLoading = false) {
                if (document.hidden || (currentRequest && !force)) return;
                currentRequest?.abort();
                currentRequest = undefined;
                const request = new AbortController();
                currentRequest = request;
                const url = new URL(dataUrl);
                if (appliedCategoryId !== 'all') url.searchParams.set('category_id', appliedCategoryId);

                if (showFilterLoading) {
                    applyFilterButton.disabled = true;
                    applyFilterButton.textContent = 'Loading…';
                    contestCards.setAttribute('aria-busy', 'true');
                }

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
                        contestCards.replaceChildren();
                    } else if (!data.rows.length) {
                        status.textContent = 'Results pending';
                        showMessage('Results pending. No contest has been finalized yet.');
                        renderCards(data.categories);
                    } else {
                        status.textContent = 'Live finalized results';
                        renderCards(data.categories);
                        rows.replaceChildren();
                        for (const result of data.rows) {
                            const row = document.createElement('tr');
                            addCell(row, `#${result.rank}`, 'px-6 py-4 font-bold text-maroon-700');
                            addParticipantCell(row, result);
                            addCell(row, result.contest_wins, 'px-6 py-4 text-right text-slate-600');
                            addCell(row, Number(result.points).toFixed(2), 'px-6 py-4 text-right font-semibold');
                            rows.append(row);
                        }
                    }
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    status.textContent = 'Connection problem';
                    showMessage('Could not load results. Retrying shortly.');
                } finally {
                    if (currentRequest === request) {
                        currentRequest = undefined;
                        applyFilterButton.disabled = false;
                        applyFilterButton.textContent = 'Apply filter';
                        contestCards.removeAttribute('aria-busy');
                    }
                }
            }

            filterForm.addEventListener('submit', (event) => {
                event.preventDefault();
                appliedCategoryId = categoryFilter.value;
                refresh(true, true);
            });
            if (!@json($event->leaderboard_frozen)) renderCards(initialCategories);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refresh(true);
            });
            setInterval(refresh, 5000);
        })();
    </script>
    @endif
</body>
</html>
