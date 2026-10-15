<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\PublicLeaderboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PublicLeaderboardController extends Controller
{
    public function __construct(private readonly PublicLeaderboardData $leaderboard) {}

    public function index(): Response
    {
        $today = today()->toDateString();
        $event = Event::query()
            ->whereNull('archived_at')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first()
            ?? Event::query()
                ->whereNull('archived_at')
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->first();

        return $this->render($event);
    }

    public function show(Event $event): Response
    {
        abort_if($event->isArchived(), 404);

        return $this->render($event);
    }

    private function render(?Event $event): Response
    {
        $initialRows = [];
        $initialCategories = [];

        if ($event !== null) {
            if (! $event->leaderboard_frozen) {
                $snapshot = $this->leaderboard->snapshot($event);
                $initialRows = $snapshot['rows'];
                $initialCategories = $snapshot['categories'];
            }

            if ($event->leaderboard_frozen) {
                $event->load(['categories' => fn ($query) => $query->orderBy('name')]);
            }
        }

        return response()->view('events.public-leaderboard', [
            'event' => $event,
            'initialRows' => $initialRows,
            'initialCategories' => $initialCategories,
            'dataUrl' => $event === null ? null : route('leaderboards.data', $event),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function data(Request $request, Event $event): JsonResponse
    {
        abort_if($event->isArchived(), 404);

        $filters = $request->validate([
            'scope' => ['sometimes', 'in:overall,category,game'],
            'category_id' => ['required_if:scope,category', 'nullable', 'integer'],
            'competition_id' => ['required_if:scope,game', 'nullable', 'integer'],
        ]);

        $snapshot = ! $event->leaderboard_frozen && ($filters['scope'] ?? 'overall') === 'overall'
            ? $this->leaderboard->snapshot(
                $event,
                isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            )
            : null;
        $rows = $event->leaderboard_frozen
            ? []
            : ($snapshot['rows'] ?? $this->leaderboard->rows(
                $event,
                $filters['scope'] ?? 'overall',
                isset($filters['category_id']) ? (int) $filters['category_id'] : null,
                isset($filters['competition_id']) ? (int) $filters['competition_id'] : null,
            ));

        return response()->json([
            'frozen' => $event->leaderboard_frozen,
            'rows' => $rows,
            'categories' => $snapshot['categories'] ?? [],
        ])->header('Cache-Control', 'no-store, private');
    }
}
