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
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first()
            ?? Event::query()
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->first();

        return $this->render($event);
    }

    public function show(Event $event): Response
    {
        return $this->render($event);
    }

    private function render(?Event $event): Response
    {
        $initialRows = [];

        if ($event !== null) {
            if (! $event->leaderboard_frozen) {
                $initialRows = $this->leaderboard->rows($event, 'overall', null, null);
            }

            $event->load([
                'categories' => fn ($query) => $query->orderBy('name'),
                'categories.competitions' => fn ($query) => $query->orderBy('name'),
            ]);
        }

        return response()->view('events.public-leaderboard', [
            'event' => $event,
            'initialRows' => $initialRows,
            'dataUrl' => $event === null ? null : route('leaderboards.data', $event),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function data(Request $request, Event $event): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['sometimes', 'in:overall,category,game'],
            'category_id' => ['required_if:scope,category', 'nullable', 'integer'],
            'competition_id' => ['required_if:scope,game', 'nullable', 'integer'],
        ]);

        $rows = $event->leaderboard_frozen
            ? []
            : $this->leaderboard->rows(
                $event,
                $filters['scope'] ?? 'overall',
                isset($filters['category_id']) ? (int) $filters['category_id'] : null,
                isset($filters['competition_id']) ? (int) $filters['competition_id'] : null,
            );

        return response()->json([
            'frozen' => $event->leaderboard_frozen,
            'rows' => $rows,
        ])->header('Cache-Control', 'no-store, private');
    }
}
