<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Support\Str;

final class OverallResultsExporter
{
    public function __construct(
        private readonly LeaderboardCalculator $leaderboard,
        private readonly CompetitionScoreExporter $documents,
    ) {}

    /** @return array<string, mixed> */
    public function report(Event $event, bool $includeContests = false): array
    {
        $event->load([
            'participants' => fn ($query) => $query->orderBy('name'),
            'categories' => fn ($query) => $query->orderBy('name'),
            'categories.competitions' => fn ($query) => $query->orderBy('name'),
            'categories.competitions.criteria' => fn ($query) => $query->orderBy('name'),
            'categories.competitions.rankScores',
            'categories.competitions.results.participant.parentTeam',
            'categories.competitions.results.criterionScores',
        ]);
        $contests = $event->categories->flatMap(fn ($category) => $category->competitions);
        $finalizedCount = $contests->filter(fn ($contest): bool => $contest->scoresAreFinalized())->count();
        $status = $contests->isEmpty() ? 'No contests configured'
            : ($finalizedCount === $contests->count() ? 'Finalized' : 'Provisional - unfinished contests remain');
        $standings = $this->leaderboard->overallStandings($event);
        $finalizedEvent = clone $event;
        $finalizedEvent->setRelation('categories', $event->categories->map(static function (Category $category): Category {
            $finalizedCategory = clone $category;
            $finalizedCategory->setRelation('competitions', $category->competitions->filter(fn (Competition $contest): bool => $contest->scoresAreFinalized())->values());

            return $finalizedCategory;
        }));
        $finalizedStandings = $this->leaderboard->overallStandings($finalizedEvent);
        $finalizedIds = $finalizedStandings->pluck('participant.id');
        $headers = ['Rank', 'Participant', 'Overall deduction', 'Finalized points after deduction'];
        $rows = $finalizedStandings->map(static fn (array $row, int $index): array => [
            $index + 1, $row['participant']->name, $row['overall_deduction'], $row['leaderboard_points'],
        ])->all();

        foreach ($standings as $row) {
            if (! $finalizedIds->contains($row['participant']->getKey())) {
                $rows[] = [null, $row['participant']->name, $row['overall_deduction'], 0.0];
            }
        }

        $tables = [[
            'title' => 'Overall summary - finalized results',
            'headers' => $headers,
            'widths' => [52.0, 350.0, 160.0, 220.0],
            'rows' => $rows,
        ]];

        if ($includeContests) {
            foreach ($event->categories as $category) {
                foreach ($category->competitions as $contest) {
                    $contestReport = $this->documents->report($event, $category, $contest);

                    foreach ($this->documents->resultTables($contestReport) as $table) {
                        $table['title'] = $category->name.' / '.$contest->name.' | '.$contestReport['status']
                            .($table['title'] === '' ? '' : ' | '.$table['title']);
                        $tables[] = $table;
                    }
                }
            }
        }

        foreach ($event->categories as $category) {
            $categoryEvent = clone $event;
            $categoryEvent->setRelation('categories', $event->categories->where('id', $category->getKey())->values());
            $categoryStandings = $this->leaderboard->overallStandings($categoryEvent, applyEventDeductions: false)
                ->sortByDesc('gross_points')->values();
            $tables[] = [
                'title' => 'Category point totals: '.$category->name,
                'headers' => ['Participant', 'Finalized points', 'Pending points', 'Total category points'],
                'widths' => [320.0, 154.0, 154.0, 154.0],
                'rows' => $categoryStandings->map(static fn (array $row): array => [
                    $row['participant']->name, $row['finalized_points'], $row['pending_points'], $row['gross_points'],
                ])->all(),
            ];
        }

        return [
            'title' => $includeContests ? 'All event results report' : 'Overall results report',
            'metadata' => [
                'Event: '.$event->name,
                'Inclusive dates: '.$event->start_date->format('Y-m-d').' to '.$event->end_date->format('Y-m-d'),
                'Status: '.$status.' | Finalized contests: '.$finalizedCount.' of '.$contests->count(),
                'Finalized ranking: '.($event->leaderboard_frozen ? 'Highest net points first (frozen)' : 'Contest wins, recorded wins, then net points'),
                'Exported: '.now()->format('Y-m-d H:i:s'),
                'Summary uses finalized contests only. Category totals show pending points separately; event deductions apply only to the summary.',
            ],
            'headers' => $headers,
            'rows' => $rows,
            'tables' => $tables,
            'signatories' => $event->reportSignatories(),
            'signature_teams' => ($event->include_team_signatories ?? true)
                ? $standings->pluck('participant')->sortBy('name')->pluck('name')->values()->all()
                : [],
        ];
    }

    public function filename(Event $event, string $format, bool $includeContests = false): string
    {
        $name = substr(Str::slug($event->name), 0, 60) ?: 'event';

        $prefix = $includeContests ? 'all-results' : 'overall-results';

        return "{$prefix}-{$name}-{$event->getKey()}.{$format}";
    }

    /** @param array<string, mixed> $report */
    public function pdf(array $report): string
    {
        return $this->documents->pdf([
            ...$report,
            'uses_criteria' => false,
            'empty_message' => 'No ranked results are available for this section.',
        ]);
    }

    /** @param array<string, mixed> $report */
    public function xlsx(array $report): string
    {
        $rows = [[$report['title']]];

        foreach ($report['metadata'] as $line) {
            $rows[] = [$line];
        }

        $rows[] = [];
        $headerRow = count($rows) + 1;
        $lastDataRow = $headerRow + count($report['rows']);

        foreach ($report['tables'] as $index => $table) {
            if ($index > 0) {
                $rows[] = [];
                $rows[] = [$table['title']];

                if (($table['subtitle'] ?? '') !== '') {
                    $rows[] = [$table['subtitle']];
                }
            }

            $rows[] = $table['headers'];

            foreach ($table['rows'] as $row) {
                $rows[] = $row;
            }

            if ($table['rows'] === []) {
                $rows[] = ['No ranked results are available for this section.'];
            }
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

        $columnCount = max(array_map(static fn (array $table): int => count($table['headers']), $report['tables']));

        for ($column = 1; $column <= $columnCount; $column++) {
            $width = $column === 2 ? 34 : ($column === 1 ? 14 : 22);
            $xml .= '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
        }

        $xml .= '</cols><sheetData>';

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $xml .= '<row r="'.$rowNumber.'">';

            foreach ($row as $column => $value) {
                $reference = chr(65 + $column).$rowNumber;
                if ($value === null) {
                    $xml .= '<c r="'.$reference.'"/>';
                } else {
                    $xml .= is_int($value) || is_float($value)
                        ? '<c r="'.$reference.'"><v>'.$value.'</v></c>'
                        : '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xmlText((string) $value).'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData><autoFilter ref="A'.$headerRow.':D'.$lastDataRow.'"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

        return $this->documents->xlsxWorksheet($xml, $report['title'] === 'All event results report' ? 'All results' : 'Overall results');
    }

    private function xmlText(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
