<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Str;

final class OverallResultsExporter
{
    public function __construct(
        private readonly LeaderboardCalculator $leaderboard,
        private readonly CompetitionScoreExporter $documents,
    ) {}

    /** @return array<string, mixed> */
    public function report(Event $event): array
    {
        $event->load([
            'categories' => fn ($query) => $query->orderBy('name'),
            'categories.competitions' => fn ($query) => $query->orderBy('name'),
            'categories.competitions.criteria',
            'categories.competitions.rankScores',
            'categories.competitions.results.participant.parentTeam',
            'categories.competitions.results.criterionScores',
        ]);
        $contests = $event->categories->flatMap(fn ($category) => $category->competitions);
        $finalizedCount = $contests->filter(fn ($contest): bool => $contest->scoresAreFinalized())->count();
        $status = $contests->isEmpty() ? 'No contests configured'
            : ($finalizedCount === $contests->count() ? 'Finalized' : 'Provisional - unfinished contests remain');
        $standings = $this->leaderboard->overallStandings($event);

        return [
            'title' => 'Overall results report',
            'metadata' => [
                'Event: '.$event->name,
                'Inclusive dates: '.$event->start_date->format('Y-m-d').' to '.$event->end_date->format('Y-m-d'),
                'Status: '.$status.' | Finalized contests: '.$finalizedCount.' of '.$contests->count(),
                'Current ranking: '.($event->leaderboard_frozen ? 'Highest net points first (frozen)' : 'Contest wins, recorded wins, then net points'),
                'Exported: '.now()->format('Y-m-d H:i:s'),
                'Pending points are provisional. Public results include finalized contests only.',
            ],
            'headers' => ['Current rank', 'Participant', 'Contest wins', 'Finalized points', 'Pending points', 'Overall deduction', 'Finalized net points', 'Current net points'],
            'rows' => $standings->map(static fn (array $row, int $index): array => [
                $index + 1,
                $row['participant']->name,
                $row['contest_wins'],
                $row['finalized_points'],
                $row['pending_points'],
                $row['overall_deduction'],
                round(max(0.0, $row['finalized_points'] - $row['overall_deduction']), 2),
                $row['leaderboard_points'],
            ])->all(),
            'signatories' => $event->reportSignatories(),
            'signature_teams' => ($event->include_team_signatories ?? true)
                ? $standings->pluck('participant')->sortBy('name')->pluck('name')->values()->all()
                : [],
        ];
    }

    public function filename(Event $event, string $format): string
    {
        $name = substr(Str::slug($event->name), 0, 60) ?: 'event';

        return "overall-results-{$name}-{$event->getKey()}.{$format}";
    }

    /** @param array<string, mixed> $report */
    public function pdf(array $report): string
    {
        $rows = array_map(static fn (array $row): array => array_map(
            static fn (mixed $value, int $index): string => $index >= 3 ? number_format((float) $value, 2, '.', ',') : (string) $value,
            $row,
            array_keys($row),
        ), $report['rows']);

        return $this->documents->tabularPdf(
            $report['title'], $report['metadata'], $report['headers'],
            [38.0, 180.0, 64.0, 95.0, 95.0, 90.0, 110.0, 110.0],
            $rows, $report['signatories'], $report['signature_teams'],
        );
    }

    /** @param array<string, mixed> $report */
    public function xlsx(array $report): string
    {
        $rows = [[$report['title']]];

        foreach ($report['metadata'] as $line) {
            $rows[] = [$line];
        }

        $rows[] = [];
        $rows[] = $report['headers'];
        $headerRow = count($rows);

        foreach ($report['rows'] as $row) {
            $rows[] = $row;
        }

        $lastDataRow = count($rows);

        if ($report['rows'] === []) {
            $rows[] = ['No ranked results are available for this event.'];
        }

        foreach ([['Signatories', $report['signatories'], 'Signature'], ['Team signatures', $report['signature_teams'], 'Authorized representative / signature']] as [$title, $names, $caption]) {
            if ($names === []) {
                continue;
            }

            $rows[] = [];
            $rows[] = [$title];

            foreach ($names as $name) {
                $rows[] = [$name];
                $rows[] = [];
                $rows[] = ['____________________________'];
                $rows[] = [$caption];
                $rows[] = [];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';

        for ($column = 1; $column <= 8; $column++) {
            $width = $column === 2 ? 34 : ($column === 1 ? 14 : 22);
            $xml .= '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
        }

        $xml .= '</cols><sheetData>';

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $xml .= '<row r="'.$rowNumber.'">';

            foreach ($row as $column => $value) {
                $reference = chr(65 + $column).$rowNumber;
                $xml .= is_int($value) || is_float($value)
                    ? '<c r="'.$reference.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xmlText((string) $value).'</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData><autoFilter ref="A'.$headerRow.':H'.$lastDataRow.'"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

        return $this->documents->xlsxWorksheet($xml, 'Overall results');
    }

    private function xmlText(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
