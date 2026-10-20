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
        $initialWinCategories = [];

        if ($event !== null) {
            $snapshot = $this->leaderboard->snapshot($event);
            $initialRows = $this->visibleRows($snapshot['rows'], $event->leaderboard_frozen);

            if (! $event->leaderboard_frozen) {
                $initialCategories = $snapshot['categories'];
                $initialWinCategories = $snapshot['win_categories'];
            }
        }

        return response()->view('events.public-leaderboard', [
            'event' => $event,
            'initialRows' => $initialRows,
            'initialCategories' => $initialCategories,
            'initialWinCategories' => $initialWinCategories,
            'dataUrl' => $event === null ? null : route('leaderboards.data', $event, false),
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

        if ($event->leaderboard_frozen && ($filters['scope'] ?? 'overall') !== 'overall') {
            if ($filters['scope'] === 'category') {
                $event->categories()->findOrFail((int) $filters['category_id']);
            } else {
                $event->competitions()->findOrFail((int) $filters['competition_id']);
            }

            return response()->json([
                'frozen' => true,
                'rows' => [],
                'categories' => [],
                'win_categories' => [],
            ])->header('Cache-Control', 'no-store, private');
        }

        $snapshot = ($filters['scope'] ?? 'overall') === 'overall'
            ? $this->leaderboard->snapshot(
                $event,
                isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            )
            : null;
        $rows = $snapshot['rows'] ?? $this->leaderboard->rows(
            $event,
            $filters['scope'] ?? 'overall',
            isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            isset($filters['competition_id']) ? (int) $filters['competition_id'] : null,
        );

        return response()->json([
            'frozen' => $event->leaderboard_frozen,
            'rows' => $this->visibleRows($rows, $event->leaderboard_frozen),
            'categories' => $event->leaderboard_frozen ? [] : ($snapshot['categories'] ?? []),
            'win_categories' => $event->leaderboard_frozen ? [] : ($snapshot['win_categories'] ?? []),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function visibleRows(array $rows, bool $frozen): array
    {
        return array_map(static fn (array $row): array => $frozen
            ? ['rank' => $row['rank'], 'points' => $row['points']]
            : array_diff_key($row, ['points' => true]), $rows);
    }
}
