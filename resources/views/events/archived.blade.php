@extends('layouts.admin')

@section('title', $event->name.' · Archive')
@section('heading', 'Archived event')

@section('content')
    <nav class="mb-6 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('events.index', ['archived' => 1], false) }}" class="font-medium hover:text-maroon-700">Archived events</a>
        <span class="mx-2">/</span>
        <span class="text-slate-700">{{ $event->name }}</span>
    </nav>

    <section class="rounded-2xl bg-gradient-to-br from-maroon-700 to-maroon-950 p-7 text-white shadow-xl sm:p-9">
        <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold">Archived</span>
        <h2 class="mt-4 text-3xl font-semibold">{{ $event->name }}</h2>
        <p class="mt-2 text-sm text-maroon-100">{{ $event->start_date->format('F j, Y') }} – {{ $event->end_date->format('F j, Y') }}</p>
        <p class="mt-4 text-sm text-maroon-100">This event is read-only. Only result and report downloads are available.</p>
        <a href="{{ route('events.results.download', $event, false) }}" data-export-download data-loading-text="Exporting event results…" class="mt-6 inline-flex items-center rounded-xl bg-white px-5 py-3 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-50">Download event results (CSV)</a>
    </section>

    <section class="mt-8" aria-label="Contest reports">
        <h3 class="text-xl font-semibold text-slate-950">Contest reports</h3>
        <p class="mt-1 text-sm text-slate-500">Download the full score report for each contest, including judge scores where applicable.</p>

        @forelse ($event->categories as $category)
            <div class="mt-6">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-maroon-700">{{ $category->name }}</h4>
                <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @forelse ($category->competitions as $competition)
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h5 class="text-lg font-semibold text-slate-950">{{ $competition->name }}</h5>
                            <p class="mt-1 text-xs font-medium {{ $competition->scoresAreFinalized() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $competition->scoresAreFinalized() ? 'Finalized scores' : 'Scores not finalized' }}</p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <a href="{{ route('events.categories.competitions.scores.export', [$event, $category, $competition, 'format' => 'xlsx'], false) }}" data-export-download data-loading-text="Exporting Excel report…" class="rounded-lg bg-maroon-700 px-4 py-2 text-sm font-semibold text-white hover:bg-maroon-800">Download Excel</a>
                                <a href="{{ route('events.categories.competitions.scores.export', [$event, $category, $competition, 'format' => 'pdf'], false) }}" data-export-download data-loading-text="Exporting PDF report…" class="rounded-lg border border-maroon-200 px-4 py-2 text-sm font-semibold text-maroon-700 hover:bg-maroon-50">Download PDF</a>
                            </div>
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">No contests in this category.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <p class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-sm text-slate-500">This event has no contests. The event results file is still available.</p>
        @endforelse
    </section>
@endsection
