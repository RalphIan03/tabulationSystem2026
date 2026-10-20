@extends('layouts.admin')

@section('title', $event->name)
@section('heading', 'Event configuration')

@section('content')
    <nav class="mb-6 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('events.index') }}" class="font-medium transition hover:text-maroon-700">Events</a>
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span class="truncate text-slate-700">{{ $event->name }}</span>
    </nav>

    <section class="overflow-hidden rounded-2xl bg-gradient-to-br from-maroon-700 to-maroon-950 text-white shadow-xl shadow-maroon-950/10">
        <div class="flex flex-col gap-6 p-7 sm:p-9 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-maroon-100 ring-1 ring-inset ring-white/15">{{ $event->scoringSystemLabel() }}</span>
                    @unless ($event->is_active)
                        <span class="rounded-full bg-amber-300 px-3 py-1 text-xs font-semibold text-amber-950">Inactive · hidden from auditors</span>
                    @endunless
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-amber-400/15 text-amber-200 ring-amber-300/20' => $event->leaderboard_frozen,
                        'bg-emerald-400/15 text-emerald-200 ring-emerald-300/20' => ! $event->leaderboard_frozen,
                    ])>
                        Leaderboard {{ $event->leaderboard_frozen ? 'frozen' : 'live' }}
                    </span>
                </div>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $event->name }}</h2>
                <p class="mt-3 text-sm text-maroon-100/80">{{ $event->start_date->format('F j, Y') }} – {{ $event->end_date->format('F j, Y') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if (auth()->user()->isAdmin())
                    <button id="open-event-details-dialog" type="button" class="inline-flex h-11 items-center justify-center rounded-xl bg-white px-5 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">Edit event details</button>
                    <form method="POST" action="{{ route('events.toggle-status', $event, false) }}" data-loading-text="{{ $event->is_active ? 'Deactivating event…' : 'Activating event…' }}" onsubmit="return confirm('{{ $event->is_active ? 'Deactivate this event? Auditors will no longer see or access it.' : 'Activate this event? Auditors will be able to access it again.' }}')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/30 bg-maroon-950/30 px-5 text-sm font-semibold text-white transition hover:bg-white/10">{{ $event->is_active ? 'Deactivate event' : 'Activate event' }}</button>
                    </form>
                    @if ($event->canBeArchived())
                        <form method="POST" action="{{ route('events.archive', $event, false) }}" data-loading-text="Archiving event…" onsubmit="return confirm('Archive this event? It will become read-only, and only report and result downloads will remain available.')">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/30 bg-maroon-950/30 px-5 text-sm font-semibold text-white transition hover:bg-white/10">Archive event</button>
                        </form>
                    @endif
                @endif

                <a href="{{ route('events.leaderboard', $event) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/30 bg-maroon-950/30 px-5 text-sm font-semibold text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M4 19V9m6 10V5m6 14v-7m4 7H2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Current leaderboard
                </a>

                <a href="{{ route('leaderboards.show', $event) }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/30 bg-maroon-950/30 px-5 text-sm font-semibold text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">
                    Public leaderboard
                </a>

                @if (auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('events.leaderboard-freeze', $event, false) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" @class([
                            'inline-flex h-11 items-center justify-center gap-2 rounded-xl px-5 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30',
                            'bg-white text-maroon-800 hover:bg-maroon-50' => ! $event->leaderboard_frozen,
                            'bg-amber-300 text-amber-950 hover:bg-amber-200' => $event->leaderboard_frozen,
                        ])>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="5" y="11" width="14" height="10" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round" />
                            </svg>
                            {{ $event->leaderboard_frozen ? 'Unfreeze leaderboard' : 'Freeze leaderboard' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('events.destroy', $event, false) }}" data-loading-text="Deleting event…" onsubmit="return confirm('Delete this event? All event data will be permanently removed.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/30 bg-maroon-950/30 px-5 text-sm font-semibold text-white transition hover:border-red-200 hover:bg-red-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            Delete event
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    @if (auth()->user()->isAdmin())
        <dialog id="event-details-dialog" aria-labelledby="event-details-title" class="user-dialog rounded-2xl bg-white p-0 text-slate-900 shadow-2xl">
            <div class="max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-6 py-5">
                    <div>
                        <h3 id="event-details-title" class="text-xl font-semibold text-slate-950">Edit event details</h3>
                        <p class="mt-1 text-sm text-slate-500">Update the event name and inclusive dates.</p>
                    </div>
                    <button type="button" data-event-details-close class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Close event details dialog">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" /></svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('events.details.update', $event, false) }}" class="space-y-4 p-6" data-loading-text="Updating event details…">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_form" value="event_details">

                    @if (old('_form') === 'event_details' && $errors->any())
                        <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">Please correct the highlighted event details.</p>
                    @endif

                    <div>
                        <label for="edit_event_name" class="mb-1.5 block text-sm font-semibold text-slate-700">Event name</label>
                        <input id="edit_event_name" name="event_name" type="text" value="{{ old('_form') === 'event_details' ? old('event_name') : $event->name }}" required maxlength="255" class="h-11 w-full rounded-xl border border-slate-300 px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        @error('event_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="edit_event_start_date" class="mb-1.5 block text-sm font-semibold text-slate-700">Start date</label>
                            <input id="edit_event_start_date" name="start_date" type="date" value="{{ old('_form') === 'event_details' ? old('start_date') : $event->start_date->format('Y-m-d') }}" required class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                            @error('start_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="edit_event_end_date" class="mb-1.5 block text-sm font-semibold text-slate-700">End date</label>
                            <input id="edit_event_end_date" name="end_date" type="date" value="{{ old('_form') === 'event_details' ? old('end_date') : $event->end_date->format('Y-m-d') }}" required class="h-11 w-full rounded-xl border border-slate-300 px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                            @error('end_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
                        <button type="button" data-event-details-close class="h-11 rounded-xl border border-slate-300 px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save event details</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-6 py-5">
            <h3 class="text-lg font-semibold text-slate-950">Tie ranking rule</h3>
            <p class="mt-1 text-sm text-slate-500">Choose how positions continue after tied scores or wins. This applies to every contest and its leaderboard points.</p>
        </div>
        @if ($canEditTieRanking)
        <form method="POST" action="{{ route('events.tie-ranking.update', $event, false) }}" class="p-5 sm:p-6" data-loading-text="Saving tie ranking rule…">
            @csrf
            @method('PATCH')
            <fieldset class="grid gap-3 lg:grid-cols-2">
                <legend class="sr-only">Tie ranking rule</legend>
                @foreach ($tieRankingMethods as $method)
                    <label @class([
                        'flex cursor-pointer gap-3 rounded-xl border p-4 transition',
                        'border-maroon-600 bg-maroon-50 ring-2 ring-maroon-600/10' => old('tie_ranking_method', $event->tie_ranking_method->value) === $method->value,
                        'border-slate-200 hover:border-maroon-300 hover:bg-maroon-50/40' => old('tie_ranking_method', $event->tie_ranking_method->value) !== $method->value,
                    ])>
                        <input name="tie_ranking_method" type="radio" value="{{ $method->value }}" @checked(old('tie_ranking_method', $event->tie_ranking_method->value) === $method->value) class="mt-1 border-slate-300 text-maroon-700 focus:ring-maroon-600">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">{{ $method->label() }}</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $method->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            @error('tie_ranking_method')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end">
                <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Save tie ranking rule</button>
            </div>
        </form>
        @else
            <p class="p-5 text-sm text-slate-600 sm:p-6">Current rule: <span class="font-semibold text-slate-900">{{ $event->tie_ranking_method->label() }}</span>. Finalized scores lock this setting for auditors.</p>
        @endif
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-950">Participants</h3>
                        <p class="mt-1 text-sm text-slate-500">Manage competitors, team entries, and optional profile photos.</p>
                    </div>
                    <span class="rounded-full bg-maroon-50 px-2.5 py-1 text-xs font-semibold text-maroon-700">{{ $event->participants->count() }}</span>
                </div>
            </div>

            @error('participant')
                <p role="alert" class="border-b border-red-200 bg-red-50 px-6 py-3 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('events.participants.store', $event, false) }}" enctype="multipart/form-data" class="space-y-3 border-b border-slate-200 bg-slate-50/70 p-5">
                @csrf
                <div>
                    <label for="participant_name" class="mb-1.5 block text-xs font-semibold text-slate-600">Participant name</label>
                    <input id="participant_name" name="participant_name" type="text" value="{{ old('participant_name') }}" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Participant name">
                    @error('participant_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="participant_reference" class="mb-1.5 block text-xs font-semibold text-slate-600">Reference number <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="participant_reference" name="participant_reference" type="text" value="{{ old('participant_reference') }}" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="e.g. P-001">
                    @error('participant_reference')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="parent_participant_id" class="mb-1.5 block text-xs font-semibold text-slate-600">Parent team <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="parent_participant_id" name="parent_participant_id" class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                        <option value="">Independent participant or team</option>
                        @foreach ($event->participants->whereNull('parent_participant_id') as $team)
                            <option value="{{ $team->id }}" @selected((string) old('parent_participant_id') === (string) $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-slate-400">Choose TEAM A when adding TEAM A2. Both enter contests separately; earned points roll up to TEAM A overall.</p>
                    @error('parent_participant_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="participant_image" class="mb-1.5 block text-xs font-semibold text-slate-600">Profile picture <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="participant_image" name="participant_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="block w-full rounded-xl border border-slate-300 bg-white text-sm text-slate-500 file:mr-4 file:border-0 file:bg-maroon-50 file:px-4 file:py-3 file:text-sm file:font-semibold file:text-maroon-700 hover:file:bg-maroon-100">
                    <p class="mt-1.5 text-xs text-slate-400">JPG, PNG, or WebP up to 10 MB and 6000 × 6000 pixels. Images over 2 MB are automatically compressed.</p>
                    @error('participant_image')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="h-11 w-full rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Add participant</button>
            </form>

            <div class="divide-y divide-slate-100">
                @forelse ($event->participants as $participant)
                    <article class="px-5 py-5">
                        <div class="flex items-start gap-3">
                            @if ($participant->profile_picture_path)
                                <img src="{{ $participantImages->url($participant->profile_picture_path) }}" alt="{{ $participant->name }}" class="h-12 w-12 shrink-0 rounded-full object-cover ring-2 ring-slate-100">
                            @else
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-maroon-50 text-sm font-bold text-maroon-700">{{ strtoupper(substr($participant->name, 0, 1)) }}</span>
                            @endif

                            <div class="min-w-0 flex-1 pt-1">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ $participant->name }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ $participant->reference_no ?: 'No reference number' }}</p>
                                @if ($participant->parent_participant_id)
                                    <p class="mt-1 text-xs font-medium text-maroon-700">Points roll up to {{ $event->participants->firstWhere('id', $participant->parent_participant_id)?->name }}</p>
                                @endif
                            </div>

                            @if (! in_array($participant->id, $lockedParticipantIds, true) && $participant->child_teams_count === 0)
                            <form method="POST" action="{{ route('events.participants.destroy', [$event, $participant], false) }}" onsubmit="return confirm('Delete this participant? This action cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-700" aria-label="Delete {{ $participant->name }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </form>
                            @endif
                        </div>

                        <details class="group mt-3">
                            <summary class="ml-15 inline-flex cursor-pointer list-none items-center gap-1 text-xs font-semibold text-maroon-700 hover:text-maroon-800 [&::-webkit-details-marker]:hidden">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Edit participant
                            </summary>

                            <form method="POST" action="{{ route('events.participants.update', [$event, $participant], false) }}" enctype="multipart/form-data" class="mt-3 space-y-3 rounded-xl bg-slate-50 p-4">
                                @csrf
                                @method('PATCH')

                                <div>
                                    <label for="participant_name_{{ $participant->id }}" class="mb-1 block text-xs font-semibold text-slate-600">Name</label>
                                    <input id="participant_name_{{ $participant->id }}" name="participant_name" type="text" value="{{ $participant->name }}" required class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                </div>
                                <div>
                                    <label for="participant_reference_{{ $participant->id }}" class="mb-1 block text-xs font-semibold text-slate-600">Reference number</label>
                                    <input id="participant_reference_{{ $participant->id }}" name="participant_reference" type="text" value="{{ $participant->reference_no }}" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                </div>
                                @if ($participant->child_teams_count === 0)
                                    <div>
                                        <label for="parent_participant_id_{{ $participant->id }}" class="mb-1 block text-xs font-semibold text-slate-600">Parent team</label>
                                        <select id="parent_participant_id_{{ $participant->id }}" name="parent_participant_id" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                            <option value="">Independent participant or team</option>
                                            @foreach ($event->participants->whereNull('parent_participant_id') as $team)
                                                @if ($team->id !== $participant->id)
                                                    <option value="{{ $team->id }}" @selected($participant->parent_participant_id === $team->id)>{{ $team->name }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                @else
                                    <p class="text-xs text-slate-500">This team has subteams, so it cannot be assigned to another parent.</p>
                                @endif
                                <div>
                                    <label for="participant_image_{{ $participant->id }}" class="mb-1 block text-xs font-semibold text-slate-600">Replace profile picture</label>
                                    <input id="participant_image_{{ $participant->id }}" name="participant_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="block w-full text-xs text-slate-500">
                                    <p class="mt-1 text-xs text-slate-400">Up to 10 MB and 6000 × 6000 pixels; images over 2 MB are automatically compressed.</p>
                                </div>
                                @if ($participant->profile_picture_path)
                                    <label class="flex items-center gap-2 text-xs text-slate-600">
                                        <input name="remove_profile_picture" type="checkbox" value="1" class="rounded border-slate-300 text-maroon-700 focus:ring-maroon-600">
                                        Remove current profile picture
                                    </label>
                                @endif
                                <button type="submit" class="h-10 w-full rounded-lg bg-maroon-700 px-4 text-sm font-semibold text-white transition hover:bg-maroon-800">Save changes</button>
                            </form>
                        </details>
                    </article>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-400">No participants have been added.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-950">Categories</h3>
                        <p class="mt-1 text-sm text-slate-500">Create divisions that will organize the contest cards below.</p>
                    </div>
                    <span class="rounded-full bg-maroon-50 px-2.5 py-1 text-xs font-semibold text-maroon-700">{{ $event->categories->count() }}</span>
                </div>
            </div>

            <form method="POST" action="{{ route('events.categories.store', $event, false) }}" class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-5 sm:flex-row">
                @csrf
                <div class="min-w-0 flex-1">
                    <label for="category_name" class="sr-only">Category name</label>
                    <input id="category_name" name="category_name" type="text" value="{{ old('category_name') }}" required class="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="Category name">
                    @error('category_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Add category</button>
            </form>

            <div class="grid gap-3 p-5 sm:grid-cols-2">
                @error('category')
                    <p role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 sm:col-span-2">{{ $message }}</p>
                @enderror
                @forelse ($event->categories as $category)
                    <article class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-maroon-100 text-sm font-bold text-maroon-700">{{ strtoupper(substr($category->name, 0, 1)) }}</span>
                            @if ($category->competitions->isEmpty())
                                <form method="POST" action="{{ route('events.categories.destroy', [$event, $category], false) }}" data-loading-text="Deleting category…" onsubmit="return confirm('Delete this empty category? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" aria-label="Delete category {{ $category->name }}" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">Delete category</button>
                                </form>
                            @endif
                        </div>
                        <h4 class="mt-3 font-semibold text-slate-900">{{ $category->name }}</h4>
                        <p class="mt-1 text-xs text-slate-400">{{ $category->competitions->count() }} {{ Str::plural('contest', $category->competitions->count()) }}</p>
                        @if ($category->competitions->isNotEmpty())
                            <p class="mt-2 text-xs text-slate-500">Only empty categories can be deleted.</p>
                        @endif
                    </article>
                @empty
                    <p class="py-8 text-center text-sm text-slate-400 sm:col-span-2">Add a category to begin creating contests.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-slate-200 px-6 py-5 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-950">Contests by category</h3>
                <p class="mt-1 text-sm text-slate-500">Select one or more contests below to exclude all linked participants from those contests. Existing scores are kept for later re-entry.</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <a href="{{ route('events.leaderboard', $event) }}" class="inline-flex h-10 items-center justify-center rounded-xl border border-maroon-200 bg-maroon-50 px-4 text-sm font-semibold text-maroon-700 transition hover:bg-maroon-100">View current leaderboard</a>

                @if (auth()->user()->isAdmin() && $event->categories->contains(fn ($category) => $category->competitions->isNotEmpty()))
                    <form id="reset-contest-scores-form" method="POST" action="{{ route('events.competition-scores.reset', $event, false) }}" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap" data-loading-text="Resetting contest scores…">
                        @csrf
                        @method('PATCH')
                        <label class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-700">
                            <input id="select-all-contests" type="checkbox" class="rounded border-slate-300 text-maroon-700 focus:ring-maroon-600">
                            Select all contests
                        </label>
                        <button type="submit" formaction="{{ route('events.competition-scores.exclude-linked', $event, false) }}" onclick="if (!confirm('Exclude all linked participants from the selected contests? Their saved scores and deductions will be kept for later re-entry.')) return false; this.form.setAttribute('action', this.getAttribute('formaction')); this.form.dataset.loadingText = 'Excluding linked participants…';" @disabled(! $event->participants->contains(fn ($participant) => $participant->parent_participant_id !== null)) class="h-10 rounded-xl border border-maroon-200 bg-maroon-50 px-4 text-sm font-semibold text-maroon-800 transition hover:bg-maroon-100 disabled:cursor-not-allowed disabled:opacity-50">Exclude linked from selected</button>
                        <button type="submit" name="reset_scope" value="selected" onclick="return confirm('Reset scores for the selected contests? Score entries and finalization will be cleared, but contest configuration will be preserved.')" class="h-10 rounded-xl border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Reset selected</button>
                        <button type="submit" name="reset_scope" value="all" onclick="return confirm('Reset scores for every contest in this event? This cannot be undone.')" class="h-10 rounded-xl border border-red-300 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100">Reset all scores</button>
                    </form>
                @endif
            </div>
        </div>

        @if (auth()->user()->isAdmin())
            @error('competition_ids')
                <p class="border-b border-red-200 bg-red-50 px-6 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
            @error('competition_ids.*')
                <p class="border-b border-red-200 bg-red-50 px-6 py-3 text-sm font-medium text-red-700">{{ $message }}</p>
            @enderror
        @endif

        <div class="space-y-6 p-5 sm:p-6">
            @forelse ($event->categories as $category)
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/50">
                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-white px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h4 class="font-semibold text-slate-950">{{ $category->name }}</h4>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $category->competitions->count() }} {{ Str::plural('contest', $category->competitions->count()) }}</p>
                        </div>

                        <button type="button" data-contest-dialog-open="add-contest-dialog-{{ $category->id }}" class="h-10 rounded-lg bg-maroon-700 px-4 text-sm font-semibold text-white transition hover:bg-maroon-800">Add contest</button>
                    </div>

                    <div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse ($category->competitions as $competition)
                            <article class="flex min-h-52 flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-maroon-50 text-maroon-700">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4ZM7 6H4v1a4 4 0 0 0 4 4m9-5h3v1a4 4 0 0 1-4 4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </span>
                                        @if (auth()->user()->isAdmin())
                                            <label class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-500">
                                                <input form="reset-contest-scores-form" name="competition_ids[]" type="checkbox" value="{{ $competition->id }}" @checked(in_array($competition->id, (array) old('competition_ids', []))) class="rounded border-slate-300 text-maroon-700 focus:ring-maroon-600">
                                                Select
                                            </label>
                                        @endif
                                    </div>
                                    <span class="flex flex-col items-end gap-1.5">
                                        <span class="rounded-full bg-maroon-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-maroon-700">{{ $competition->scoring_method->label() }}</span>
                                        @if ($competition->scoresAreFinalized())
                                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Scores finalized</span>
                                        @elseif ($competition->hasSavedScores())
                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending finalization</span>
                                        @endif
                                    </span>
                                </div>
                                <h5 class="mt-4 text-lg font-semibold text-slate-950">{{ $competition->name }}</h5>
                                <p class="mt-1 text-xs text-slate-400">{{ $competition->criteria_count }} {{ Str::plural('criterion', $competition->criteria_count) }} · {{ $competition->results_count }} scored {{ Str::plural('participant', $competition->results_count) }}</p>

                                <div class="mt-auto grid grid-cols-2 gap-2 pt-5">
                                    <a href="{{ route('events.categories.competitions.show', [$event, $category, $competition]) }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-slate-300 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Configure</a>
                                    <a href="{{ route('events.categories.competitions.scores.edit', [$event, $category, $competition]) }}" class="inline-flex h-10 items-center justify-center rounded-lg bg-maroon-700 text-sm font-semibold text-white transition hover:bg-maroon-800">Enter scores</a>
                                    @if (auth()->user()->isAdmin())
                                        <form method="POST" action="{{ route('events.categories.competitions.destroy', [$event, $category, $competition], false) }}" class="col-span-2" data-loading-text="Deleting contest…" onsubmit="return confirm('Delete this contest? Its configuration and scores will be permanently removed.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="h-10 w-full rounded-lg border border-red-200 bg-red-50 text-sm font-semibold text-red-700 transition hover:bg-red-100">Delete contest</button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center md:col-span-2 xl:col-span-3">
                                <p class="text-sm text-slate-400">No contests in {{ $category->name }} yet.</p>
                            </div>
                        @endforelse
                    </div>
                    <dialog id="add-contest-dialog-{{ $category->id }}" aria-labelledby="add-contest-title-{{ $category->id }}" class="user-dialog rounded-2xl bg-white p-0 text-slate-900 shadow-2xl">
                        <div class="max-h-[90vh] overflow-y-auto">
                            <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-6 py-5">
                                <div>
                                    <h3 id="add-contest-title-{{ $category->id }}" class="text-xl font-semibold text-slate-950">Add contest</h3>
                                    <p class="mt-1 text-sm text-slate-500">Set up a contest in {{ $category->name }}.</p>
                                </div>
                                <button type="button" data-contest-dialog-close class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Close add contest dialog">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" /></svg>
                                </button>
                            </div>

                            <form method="POST" action="{{ route('events.categories.competitions.store', [$event, $category], false) }}" class="space-y-5 p-6" data-loading-text="Creating contest…" data-contest-form>
                                @csrf
                                <input type="hidden" name="_form" value="add_contest">
                                <input type="hidden" name="category_id" value="{{ $category->id }}">

                                @if (old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id && $errors->any())
                                    <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">Please correct the highlighted contest details.</p>
                                @endif

                                <div>
                                    <label for="competition_name_{{ $category->id }}" class="mb-1.5 block text-sm font-semibold text-slate-700">Contest name</label>
                                    <input id="competition_name_{{ $category->id }}" name="competition_name" type="text" value="{{ old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id ? old('competition_name') : '' }}" maxlength="255" required class="h-11 w-full rounded-xl border border-slate-300 px-4 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10" placeholder="e.g. Basketball">
                                    @if (old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id)
                                        @error('competition_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    @endif
                                </div>

                                <fieldset>
                                    <legend class="mb-2 text-sm font-semibold text-slate-700">Winner determination</legend>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        @foreach (\App\Enums\CompetitionScoringMethod::cases() as $method)
                                            <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-maroon-300 hover:bg-maroon-50/40">
                                                <input name="scoring_method" type="radio" value="{{ $method->value }}" @checked((old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id ? old('scoring_method') : \App\Enums\CompetitionScoringMethod::Criteria->value) === $method->value) required class="mt-1 border-slate-300 text-maroon-700 focus:ring-maroon-600">
                                                <span>
                                                    <span class="block text-sm font-semibold text-slate-900">{{ $method->label() }}</span>
                                                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $method->description() }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @if (old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id)
                                        @error('scoring_method')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    @endif
                                </fieldset>

                                <div data-contest-judge-field>
                                    <label for="competition_judge_count_{{ $category->id }}" class="mb-1.5 block text-sm font-semibold text-slate-700">Number of judges</label>
                                    <input id="competition_judge_count_{{ $category->id }}" name="judge_count" type="number" min="1" max="10" step="1" value="{{ old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id ? old('judge_count', 1) : 1 }}" required class="h-11 w-28 rounded-xl border border-slate-300 px-3 text-sm outline-none transition focus:border-maroon-600 focus:ring-4 focus:ring-maroon-600/10">
                                    <p class="mt-1 text-xs text-slate-500">Each judge scores every criterion; final scores use their average.</p>
                                    @if (old('_form') === 'add_contest' && (string) old('category_id') === (string) $category->id)
                                        @error('judge_count')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    @endif
                                </div>

                                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
                                    <button type="button" data-contest-dialog-close class="h-11 rounded-xl border border-slate-300 px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                                    <button type="submit" class="h-11 rounded-xl bg-maroon-700 px-5 text-sm font-semibold text-white transition hover:bg-maroon-800">Create contest</button>
                                </div>
                            </form>
                        </div>
                    </dialog>
                </section>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 px-6 py-12 text-center">
                    <p class="font-semibold text-slate-700">No categories yet</p>
                    <p class="mt-1 text-sm text-slate-400">Add a category above to begin creating contest cards.</p>
                </div>
            @endforelse
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            document.querySelectorAll('[data-contest-dialog-open]').forEach((button) => {
                button.addEventListener('click', () => document.getElementById(button.dataset.contestDialogOpen)?.showModal());
            });

            document.querySelectorAll('[id^="add-contest-dialog-"]').forEach((dialog) => {
                const form = dialog.querySelector('[data-contest-form]');
                const judgeField = dialog.querySelector('[data-contest-judge-field]');
                const judgeInput = judgeField?.querySelector('input');
                const syncJudgeField = () => {
                    const criteriaBased = form?.querySelector('input[name="scoring_method"]:checked')?.value === 'criteria';
                    judgeField.hidden = ! criteriaBased;
                    judgeInput.required = criteriaBased;
                };

                form?.querySelectorAll('input[name="scoring_method"]').forEach((input) => input.addEventListener('change', syncJudgeField));
                dialog.querySelectorAll('[data-contest-dialog-close]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });
                syncJudgeField();

                if (@json(old('_form') === 'add_contest' && $errors->any()) && form?.querySelector('input[name="category_id"]')?.value === @json((string) old('category_id'))) {
                    dialog.showModal();
                }
            });
        })();
    </script>
    @if (auth()->user()->isAdmin())
        <script>
            (() => {
                const selectAll = document.getElementById('select-all-contests');
                const contestSelections = Array.from(document.querySelectorAll('input[form="reset-contest-scores-form"][name="competition_ids[]"]'));
                const refreshSelectAll = () => {
                    const selectedCount = contestSelections.filter((selection) => selection.checked).length;
                    selectAll.checked = selectedCount === contestSelections.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < contestSelections.length;
                };

                selectAll?.addEventListener('change', () => {
                    contestSelections.forEach((selection) => { selection.checked = selectAll.checked; });
                    refreshSelectAll();
                });
                contestSelections.forEach((selection) => selection.addEventListener('change', refreshSelectAll));
                if (selectAll) refreshSelectAll();

                const dialog = document.getElementById('event-details-dialog');
                const openButton = document.getElementById('open-event-details-dialog');

                openButton?.addEventListener('click', () => dialog?.showModal());
                dialog?.querySelectorAll('[data-event-details-close]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                dialog?.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });

                if (@json(old('_form') === 'event_details' && $errors->any())) {
                    dialog?.showModal();
                }
            })();
        </script>
    @endif
@endpush
