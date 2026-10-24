<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class CompetitionScoreExporter
{
    public function __construct(private readonly LeaderboardCalculator $leaderboard) {}

    /** @return array<string, mixed> */
    public function report(Event $event, Category $category, Competition $competition): array
    {
        $event->loadMissing(['participants' => fn ($query) => $query->orderBy('name')]);
        $competition->loadMissing([
            'criteria' => fn ($query) => $query->orderBy('name'),
            'rankScores',
            'results.participant.parentTeam',
            'results.criterionScores',
        ]);

        $standings = $this->leaderboard->competitionStandings($competition, $event)
            ->keyBy(static fn (array $row): int => $row['participant']->getKey());
        $results = $competition->results->keyBy('participant_id');
        $criteria = $competition->criteria->map(static fn ($criterion): array => [
            'id' => $criterion->getKey(),
            'name' => $criterion->name.' average',
            'raw_name' => $criterion->name,
        ])->all();
        $rows = [];
        $usesCriteria = $competition->usesCriteriaScoring();
        $usesRanking = $competition->usesRankingScoring();
        $participantsById = $event->participants->keyBy('id');

        foreach ($event->participants as $participant) {
            $result = $results->get($participant->getKey());
            $standing = $standings->get($participant->getKey());
            $criteriaSummary = $usesCriteria && $result !== null
                ? $result->criteriaScoreSummary($competition)
                : null;
            $hasNoEntry = $result?->has_entry === false;
            $scores = [];
            $judgeScores = [];
            $judgeTotals = [];
            $savedJudgeScores = $result?->criterionScores->keyBy(
                static fn ($score): string => $score->judge_number.':'.$score->criterion_id,
            );

            foreach ($criteria as $criterion) {
                $scores[$criterion['id']] = $hasNoEntry
                    ? 0.0
                    : ($criteriaSummary['averages'][$criterion['id']] ?? null);
            }

            if ($usesCriteria) {
                for ($judge = 1; $judge <= $competition->judge_count; $judge++) {
                    $judgeScores[$judge] = [];

                    foreach ($criteria as $criterion) {
                        $score = $savedJudgeScores?->get($judge.':'.$criterion['id']);
                        $judgeScores[$judge][$criterion['id']] = $hasNoEntry
                            ? 0.0
                            : ($score === null ? null : (float) $score->score);
                    }

                    $judgeTotals[$judge] = $hasNoEntry
                        ? 0.0
                        : ($judgeScores[$judge] !== [] && ! in_array(null, $judgeScores[$judge], true)
                            ? round(array_sum($judgeScores[$judge]), 2)
                            : null);
                }
            }

            $hasSavedScore = $usesCriteria
                ? ($criteriaSummary['rankable'] ?? false)
                : ($usesRanking ? $result?->placement !== null : $result?->wins !== null);
            $gross = null;

            if ($hasNoEntry) {
                $gross = 0.0;
            } elseif ($hasSavedScore) {
                $gross = $usesCriteria
                    ? $criteriaSummary['gross']
                    : (float) ($usesRanking ? $result->placement : $result->wins);
            }

            $deduction = $hasNoEntry ? 0.0 : ($gross === null ? null : (float) $result->deduction);

            $rows[] = [
                'rank' => $standing['rank'] ?? null,
                'participant' => $participant->name,
                'status' => $hasNoEntry
                    ? 'No entry'
                    : ($hasSavedScore ? 'Scored' : 'Pending'),
                'scores' => $scores,
                'judge_scores' => $judgeScores,
                'judge_totals' => $judgeTotals,
                'wins' => $hasNoEntry ? 0 : $result?->wins,
                'placement' => $hasNoEntry ? null : $result?->placement,
                'gross' => $gross,
                'deduction' => $deduction,
                'adjusted' => $usesCriteria
                    ? ($gross === null ? null : max(0.0, $gross - $deduction))
                    : ($hasNoEntry ? 0.0 : ($standing['rank_points'] ?? null)),
                'points' => $standing['leaderboard_points'] ?? null,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $rankComparison = ($left['rank'] ?? PHP_INT_MAX) <=> ($right['rank'] ?? PHP_INT_MAX);

            return $rankComparison !== 0
                ? $rankComparison
                : (strcasecmp($left['participant'], $right['participant']) ?: strcmp($left['participant'], $right['participant']));
        });

        $criterionRanks = $usesCriteria
            ? $this->leaderboard->criterionStandings($competition, $event)
                ->map(static fn (array $breakdown): array => [
                    'name' => $breakdown['criterion']->name,
                    'rows' => $breakdown['standings']->map(static fn (array $row): array => [
                        'rank' => $row['rank'],
                        'participant' => $row['participant']->name,
                        'score' => $row['score'],
                    ])->all(),
                ])->all()
            : [];

        return [
            'event' => $event->name,
            'category' => $category->name,
            'competition' => $competition->name,
            'scoring_method' => $competition->scoring_method->label(),
            'judge_count' => $competition->judge_count,
            'uses_criteria' => $usesCriteria,
            'uses_ranking' => $usesRanking,
            'status' => $competition->scoresAreFinalized()
                ? 'Finalized'
                : ($competition->hasSavedScores() ? 'Pending finalization' : 'Not started'),
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'criteria' => $criteria,
            'rows' => $rows,
            'criterion_ranks' => $criterionRanks,
            'signatories' => $event->reportSignatories(),
            'signature_teams' => ($event->include_team_signatories ?? true) ? $competition->results
                ->filter(static fn ($result): bool => $result->has_entry)
                ->map(static fn ($result) => $participantsById->get($result->participant_id))
                ->filter()
                ->map(static fn ($participant) => $participantsById->get($participant->parent_participant_id) ?? $participant)
                ->unique('id')
                ->sortBy('name')
                ->pluck('name')
                ->values()
                ->all() : [],
        ];
    }

    public function filename(Event $event, Competition $competition, string $format): string
    {
        $eventName = substr(Str::slug($event->name), 0, 40) ?: 'event';
        $contestName = substr(Str::slug($competition->name), 0, 40) ?: 'contest';

        return "scores-{$eventName}-{$contestName}-{$competition->getKey()}.{$format}";
    }

    /** @param array<string, mixed> $report */
    public function xlsx(array $report): string
    {
        return $this->xlsxWorksheet($this->worksheet($report));
    }

    public function xlsxWorksheet(string $worksheet, string $sheetName = 'Scores'): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZIP extension is required for Excel exports.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'contest-scores-');

        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary Excel export file could not be created.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The Excel export could not be created.');
            }

            $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'</Types>');
            $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$this->xmlText($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>');
            $archive->addFromString('xl/worksheets/sheet1.xml', $worksheet);
            $archive->close();

            $content = file_get_contents($temporaryPath);

            if ($content === false) {
                throw new RuntimeException('The Excel export could not be read.');
            }

            return $content;
        } finally {
            unlink($temporaryPath);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, names: list<string>, caption: string}>
     */
    private function signatureSections(array $report): array
    {
        return array_values(array_filter([
            ['title' => 'Signatories', 'names' => $report['signatories'], 'caption' => 'Signature'],
            ['title' => 'Team signatures', 'names' => $report['signature_teams'], 'caption' => 'Authorized representative / signature'],
        ], static fn (array $section): bool => $section['names'] !== []));
    }

    /** @param array<string, mixed> $report */
    private function worksheet(array $report): string
    {
        $headers = ['Rank', 'Participant', 'Status'];

        if ($report['uses_criteria']) {
            foreach ($report['criteria'] as $criterion) {
                $headers[] = $criterion['name'];
            }

            $headers[] = 'Gross score';
        } else {
            $headers[] = $report['uses_ranking'] ? 'Entered rank' : 'Wins';
        }

        array_push($headers, 'Deduction', $report['uses_criteria'] ? 'Adjusted total' : 'Rank points', 'Leaderboard points');
        $summaryHeaderRow = $report['uses_criteria'] ? 10 : 9;

        $sheetRows = [
            1 => ['Contest score report'],
            2 => ['Event', $report['event']],
            3 => ['Category', $report['category']],
            4 => ['Contest', $report['competition']],
            5 => ['Scoring method', $report['scoring_method']],
            6 => ['Status', $report['status']],
            7 => ['Exported at', $report['exported_at']],
            8 => $report['uses_criteria'] ? ['Judges', $report['judge_count']] : [],
        ];

        if ($report['uses_criteria']) {
            $sheetRows[9] = ['Averaged scores'];
        }

        $sheetRows[$summaryHeaderRow] = $headers;

        foreach ($report['rows'] as $index => $row) {
            $values = [$row['rank'], $row['participant'], $row['status']];

            if ($report['uses_criteria']) {
                foreach ($report['criteria'] as $criterion) {
                    $values[] = $row['scores'][$criterion['id']];
                }

                $values[] = $row['gross'];
            } else {
                $values[] = $report['uses_ranking'] ? $row['placement'] : $row['wins'];
            }

            array_push($values, $row['deduction'], $row['adjusted'], $row['points']);
            $sheetRows[$summaryHeaderRow + $index + 1] = $values;
        }

        if ($report['uses_criteria']) {
            $nextRow = $summaryHeaderRow + count($report['rows']) + 2;

            for ($judge = 1; $judge <= $report['judge_count']; $judge++) {
                $sheetRows[$nextRow++] = ["Judge {$judge} scores"];
                $judgeHeaders = ['Rank', 'Participant', 'Status'];

                foreach ($report['criteria'] as $criterion) {
                    $judgeHeaders[] = $criterion['raw_name'];
                }

                $judgeHeaders[] = 'Judge total';
                $sheetRows[$nextRow++] = $judgeHeaders;

                foreach ($report['rows'] as $row) {
                    $values = [$row['rank'], $row['participant'], $row['status']];

                    foreach ($report['criteria'] as $criterion) {
                        $values[] = $row['judge_scores'][$judge][$criterion['id']];
                    }

                    $values[] = $row['judge_totals'][$judge];
                    $sheetRows[$nextRow++] = $values;
                }

                $nextRow++;
            }

            foreach ($report['criterion_ranks'] as $criterionRank) {
                if ($criterionRank['rows'] === []) {
                    continue;
                }

                $sheetRows[$nextRow++] = ['Best on '.$criterionRank['name']];
                $sheetRows[$nextRow++] = ['Rank', 'Participant', 'Average score'];

                foreach ($criterionRank['rows'] as $rankedRow) {
                    $sheetRows[$nextRow++] = [$rankedRow['rank'], $rankedRow['participant'], $rankedRow['score']];
                }

                $nextRow++;
            }
        }

        $nextRow = max(array_keys($sheetRows)) + 2;

        foreach ($this->signatureSections($report) as $section) {
            $sheetRows[$nextRow++] = [$section['title']];
            $nextRow++;

            foreach ($section['names'] as $name) {
                $sheetRows[$nextRow++] = [$name];
                $sheetRows[$nextRow++] = ['________________________________'];
                $sheetRows[$nextRow++] = [$section['caption']];
                $nextRow++;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';

        foreach (array_keys($headers) as $index) {
            $width = $index === 1 ? 32 : ($index > 2 && $index < count($headers) - 4 ? 22 : 18);
            $column = $index + 1;
            $xml .= "<col min=\"{$column}\" max=\"{$column}\" width=\"{$width}\" customWidth=\"1\"/>";
        }

        $xml .= '</cols><sheetData>';

        foreach ($sheetRows as $rowNumber => $values) {
            $xml .= "<row r=\"{$rowNumber}\">";

            foreach ($values as $index => $value) {
                $reference = $this->columnName($index + 1).$rowNumber;

                if ($value === null) {
                    $xml .= "<c r=\"{$reference}\"/>";
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= "<c r=\"{$reference}\"><v>{$value}</v></c>";
                } else {
                    $escaped = $this->xmlText((string) $value);
                    $xml .= "<c r=\"{$reference}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$escaped}</t></is></c>";
                }
            }

            $xml .= '</row>';
        }

        $lastColumn = $this->columnName(count($headers));
        $lastRow = $summaryHeaderRow + count($report['rows']);

        return $xml."</sheetData><autoFilter ref=\"A{$summaryHeaderRow}:{$lastColumn}{$lastRow}\"/></worksheet>";
    }

    private function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function xmlText(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param  list<string>  $metadata
     * @param  list<string>  $headers
     * @param  list<float>  $widths
     * @param  list<list<string>>  $rows
     * @param  list<string>  $signatories
     * @param  list<string>  $signatureTeams
     */
    public function tabularPdf(string $title, array $metadata, array $headers, array $widths, array $rows, array $signatories = [], array $signatureTeams = []): string
    {
        return $this->pdf([
            'title' => $title,
            'metadata' => $metadata,
            'table' => ['headers' => $headers, 'widths' => $widths],
            'uses_criteria' => false,
            'uses_ranking' => false,
            'criteria' => [],
            'criterion_ranks' => [],
            'rows' => array_map(static fn (array $values): array => ['values' => $values], $rows),
            'signatories' => $signatories,
            'signature_teams' => $signatureTeams,
            'empty_message' => 'No ranked results are available for this event.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{title: string, subtitle?: string, headers: list<string>, widths: list<float>, rows: list<list<int|float|string|null>>}>
     */
    public function resultTables(array $report): array
    {
        if (isset($report['tables'])) {
            return $report['tables'];
        }

        if (isset($report['table'])) {
            return [['title' => '', ...$report['table'], 'rows' => array_column($report['rows'], 'values')]];
        }

        $groups = $report['uses_criteria'] ? array_chunk($report['criteria'], 6) : [];
        $groups = $groups === [] ? [[]] : $groups;
        $judges = $report['uses_criteria'] ? array_merge([null], range(1, $report['judge_count'])) : [null];
        $tables = [];

        foreach ($judges as $judge) {
            foreach ($groups as $groupIndex => $criteria) {
                [$headers, $widths] = $this->pdfColumns($report['uses_criteria'], $report['uses_ranking'], $criteria, $judge);
                $title = $report['uses_criteria'] ? ($judge === null ? 'Averaged scores' : "Judge {$judge} scores") : '';

                $tables[] = [
                    'title' => $title,
                    'subtitle' => count($groups) > 1
                        ? 'Criteria columns '.($groupIndex * 6 + 1).'-'.min(($groupIndex + 1) * 6, count($report['criteria'])).' of '.count($report['criteria'])
                        : '',
                    'headers' => $headers,
                    'widths' => $widths,
                    'rows' => array_map(fn (array $row): array => $this->tableRowValues($row, $criteria, $report['uses_criteria'], $report['uses_ranking'], $judge), $report['rows']),
                ];
            }
        }

        foreach ($report['criterion_ranks'] as $criterion) {
            if ($criterion['rows'] !== []) {
                $tables[] = [
                    'title' => 'Best on '.$criterion['name'],
                    'headers' => ['Rank', 'Participant', 'Average score'],
                    'widths' => [70.0, 502.0, 210.0],
                    'rows' => array_map(static fn (array $row): array => [$row['rank'], $row['participant'], $row['score']], $criterion['rows']),
                ];
            }
        }

        return $tables;
    }

    /** @param array<string, mixed> $report */
    public function pdf(array $report): string
    {
        $pages = [];
        [$content, $cursor] = $this->pdfPageHeader($report);
        $pageStartCursor = $cursor;
        $hasTableOnPage = false;

        foreach ($this->resultTables($report) as $table) {
            $widths = $table['widths'];
            $headerCells = $this->pdfCellLines($table['headers'], $widths);
            $headerHeight = $this->pdfRowHeight($headerCells);
            $titleLines = $table['title'] === '' ? [] : $this->pdfWrapCell($table['title'], 782, 10);

            if (($table['subtitle'] ?? '') !== '') {
                $titleLines = array_merge($titleLines, $this->pdfWrapCell($table['subtitle'], 782, 10));
            }
            $headingHeight = $titleLines === [] ? 0 : count($titleLines) * 12 + 14;
            $preparedRows = [];
            $tableHeight = $headingHeight + $headerHeight;

            foreach ($table['rows'] as $index => $values) {
                $cells = $this->pdfCellLines(array_map(
                    fn (mixed $value): string => is_float($value) ? $this->formattedNumber($value) : ($value === null ? '-' : (string) $value),
                    $values,
                ), $widths);
                $height = $this->pdfRowHeight($cells);
                $preparedRows[] = ['cells' => $cells, 'height' => $height, 'index' => $index];
                $tableHeight += $height;
            }

            if ($preparedRows === []) {
                $tableHeight += 24;
            }

            $gap = $hasTableOnPage ? 16 : 0;

            if ($hasTableOnPage && $cursor - $gap - $tableHeight < 38
                && ($tableHeight <= $pageStartCursor - 38
                    || $cursor - $gap - $headingHeight - $headerHeight - ($preparedRows[0]['height'] ?? 24) < 38)) {
                $pages[] = $content;
                [$content, $cursor] = $this->pdfPageHeader($report);
                $hasTableOnPage = false;
            }

            if ($hasTableOnPage) {
                $cursor -= 16;
            }

            [$heading, $cursor] = $this->pdfTableHeading($titleLines, $cursor);
            $content .= $heading;
            $content .= $this->pdfTableRow($headerCells, $widths, $cursor, $headerHeight, true);
            $cursor -= $headerHeight;

            foreach ($preparedRows as $row) {
                if ($cursor - $row['height'] < 38) {
                    $pages[] = $content;
                    [$content, $cursor] = $this->pdfPageHeader($report);
                    [$heading, $cursor] = $this->pdfTableHeading($titleLines, $cursor);
                    $content .= $heading;
                    $content .= $this->pdfTableRow($headerCells, $widths, $cursor, $headerHeight, true);
                    $cursor -= $headerHeight;
                }

                $content .= $this->pdfTableRow($row['cells'], $widths, $cursor, $row['height'], false, $row['index'] % 2 === 1);
                $cursor -= $row['height'];
            }

            if ($preparedRows === []) {
                $content .= $this->pdfDrawText($this->pdfText($report['empty_message'] ?? 'No participants have been added to this event.'), 36, $cursor - 18, 9);
                $cursor -= 24;
            }

            $hasTableOnPage = true;
        }

        foreach ($this->signatureSections($report) as $section) {
            $firstLines = $this->pdfWrapCell($section['names'][0], 500, 9);
            $firstHeight = count($firstLines) * 11 + 48;

            if ($cursor - 18 - 24 - $firstHeight < 38) {
                $pages[] = $content;
                [$content, $cursor] = $this->pdfPageHeader($report);
            } else {
                $cursor -= 18;
            }

            $content .= $this->pdfDrawText($section['title'], 30, $cursor - 2, 11, true);
            $cursor -= 24;

            foreach ($section['names'] as $name) {
                $nameLines = $this->pdfWrapCell($name, 500, 9);
                $height = count($nameLines) * 11 + 48;

                if ($cursor - $height < 38) {
                    $pages[] = $content;
                    [$content, $cursor] = $this->pdfPageHeader($report);
                    $content .= $this->pdfDrawText($section['title'].' (continued)', 30, $cursor - 2, 11, true);
                    $cursor -= 24;
                }

                foreach ($nameLines as $line) {
                    $content .= $this->pdfDrawText($line, 30, $cursor - 2, 9, true);
                    $cursor -= 11;
                }

                $cursor -= 12;
                $content .= sprintf("0.35 0.35 0.35 RG 0.7 w 30 %.2F m 350 %.2F l S\n", $cursor, $cursor);
                $content .= $this->pdfDrawText($section['caption'], 30, $cursor - 12, 8);
                $cursor -= 36;
            }
        }

        $pages[] = $content;

        return $this->pdfDocument($pages);
    }

    private function formattedNumber(int|float|null $value): string
    {
        return $value === null ? '-' : number_format($value, 2, '.', ',');
    }

    /**
     * @param  array<int, array{id: int, name: string, raw_name: string}>  $criteria
     * @return array{array<int, string>, array<int, float>}
     */
    private function pdfColumns(bool $usesCriteria, bool $usesRanking, array $criteria, ?int $judgeNumber): array
    {
        if ($judgeNumber !== null) {
            $headers = ['Rank', 'Participant', 'Status'];

            foreach ($criteria as $criterion) {
                $headers[] = $criterion['raw_name'];
            }

            $headers[] = 'Judge total';

            if ($criteria === []) {
                return [$headers, [38.0, 592.0, 72.0, 80.0]];
            }

            return [$headers, array_merge([38.0, 180.0, 72.0], array_fill(0, count($criteria), 412.0 / count($criteria)), [80.0])];
        }

        if (! $usesCriteria || $criteria === []) {
            return [
                ['Rank', 'Participant', 'Status', $usesCriteria ? 'Gross score' : ($usesRanking ? 'Entered rank' : 'Wins'), 'Deduction', $usesCriteria ? 'Adjusted total' : 'Rank points', 'Leaderboard points'],
                [38.0, 274.0, 78.0, 98.0, 98.0, 98.0, 98.0],
            ];
        }

        $headers = ['Rank', 'Participant', 'Status'];

        foreach ($criteria as $criterion) {
            $headers[] = $criterion['name'];
        }

        array_push($headers, 'Gross score', 'Deduction', 'Adjusted total', 'Leaderboard points');

        return [$headers, array_merge([38.0, 158.0, 66.0], array_fill(0, count($criteria), 272.0 / count($criteria)), [62.0, 62.0, 62.0, 62.0])];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{string, float}
     */
    private function pdfPageHeader(array $report): array
    {
        $content = "0.42 0.07 0.16 rg\n";
        $content .= $this->pdfDrawText($this->pdfText($report['title'] ?? 'Contest score report'), 30, 564, 15, true);
        $content .= "0.42 0.07 0.16 RG 1 w 30 551 m 812 551 l S\n0.16 0.16 0.16 rg\n";
        $metadata = $report['metadata'] ?? [
            'Event: '.$report['event'].'  |  Category: '.$report['category'],
            'Contest: '.$report['competition'].'  |  Scoring: '.$report['scoring_method'],
            'Status: '.$report['status'].'  |  Exported: '.$report['exported_at'],
        ];

        if ($report['uses_criteria']) {
            $metadata[] = 'Judges: '.$report['judge_count'].'  |  Averaged and individual judge scores';
        }

        $cursor = 537.0;

        foreach ($metadata as $line) {
            foreach ($this->pdfWrapCell($line, 782.0, 9) as $part) {
                $content .= $this->pdfDrawText($part, 30, $cursor, 9);
                $cursor -= 12;
            }
        }

        return [$content, $cursor - 9];
    }

    /**
     * @param  list<string>  $lines
     * @return array{string, float}
     */
    private function pdfTableHeading(array $lines, float $cursor): array
    {
        if ($lines === []) {
            return ['', $cursor];
        }

        $content = '';

        foreach ($lines as $line) {
            $content .= $this->pdfDrawText($line, 30, $cursor - 2, 10, true);
            $cursor -= 12;
        }

        return [$content, $cursor - 14];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array{id: int, name: string, raw_name: string}>  $criteria
     * @return list<int|float|string|null>
     */
    private function tableRowValues(array $row, array $criteria, bool $usesCriteria, bool $usesRanking, ?int $judgeNumber): array
    {
        $values = [$row['rank'], $row['participant'], $row['status']];

        if ($judgeNumber !== null) {
            foreach ($criteria as $criterion) {
                $values[] = $row['judge_scores'][$judgeNumber][$criterion['id']];
            }

            $values[] = $row['judge_totals'][$judgeNumber];

            return $values;
        }

        if ($usesCriteria) {
            foreach ($criteria as $criterion) {
                $values[] = $row['scores'][$criterion['id']];
            }
        }

        $values[] = $usesRanking ? $row['placement'] : ($usesCriteria ? $row['gross'] : ($row['wins'] === null ? null : (float) $row['wins']));
        $values[] = $row['deduction'];
        $values[] = $row['adjusted'];
        $values[] = $row['points'];

        return $values;
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<int, float>  $widths
     * @return array<int, array<int, string>>
     */
    private function pdfCellLines(array $values, array $widths): array
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $cells[] = $this->pdfWrapCell($value, $widths[$index], 8);
        }

        return $cells;
    }

    /** @return array<int, string> */
    private function pdfWrapCell(string $text, float $width, int $size): array
    {
        $charactersPerLine = max(1, (int) floor(($width - 10) / ($size * 0.6)));

        return explode("\n", wordwrap($this->pdfText($text), $charactersPerLine, "\n", true));
    }

    /** @param array<int, array<int, string>> $cells */
    private function pdfRowHeight(array $cells): float
    {
        return max(24, max(array_map('count', $cells)) * 10 + 8);
    }

    /**
     * @param  array<int, array<int, string>>  $cells
     * @param  array<int, float>  $widths
     */
    private function pdfTableRow(array $cells, array $widths, float $top, float $height, bool $header, bool $shaded = false): string
    {
        $fill = $header ? '0.42 0.07 0.16' : ($shaded ? '0.98 0.96 0.97' : '1 1 1');
        $content = sprintf("%s rg 30 %.2F 782 %.2F re f\n", $fill, $top - $height, $height);
        $content .= sprintf("0.74 0.70 0.72 RG 0.5 w 30 %.2F 782 %.2F re S\n", $top - $height, $height);
        $content .= $header ? "1 1 1 rg\n" : "0.16 0.16 0.16 rg\n";
        $left = 30.0;

        foreach ($cells as $index => $lines) {
            foreach ($lines as $lineNumber => $line) {
                $content .= $this->pdfDrawText($line, $left + 5, $top - 12 - ($lineNumber * 10), 8, $header);
            }

            $left += $widths[$index];

            if ($index < count($cells) - 1) {
                $content .= sprintf("0.74 0.70 0.72 RG %.2F %.2F m %.2F %.2F l S\n", $left, $top - $height, $left, $top);
            }
        }

        return $content;
    }

    private function pdfDrawText(string $text, float $x, float $y, int $size, bool $bold = false): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $font = $bold ? 'F2' : 'F1';

        return sprintf("BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $escaped);
    }

    private function pdfText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $converted = function_exists('iconv')
            ? iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text)
            : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $converted === false ? $text : $converted) ?? '';
    }

    /** @param array<int, string> $pages */
    private function pdfDocument(array $pages): string
    {
        $pageCount = count($pages);
        $kids = [];

        for ($index = 0; $index < $pageCount; $index++) {
            $kids[] = (5 + ($index * 2)).' 0 R';
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
        ];

        foreach ($pages as $index => $pageContent) {
            $pageNumber = 5 + ($index * 2);
            $streamNumber = $pageNumber + 1;
            $footer = 'Page '.($index + 1).' of '.$pageCount;
            $pageContent .= $this->pdfDrawText($footer, 746, 20, 8);
            $objects[$pageNumber] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$streamNumber} 0 R >>";
            $objects[$streamNumber] = '<< /Length '.strlen($pageContent)." >>\nstream\n{$pageContent}endstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefPosition = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_keys($objects) as $number) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number])."\n";
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\n"
            ."startxref\n{$xrefPosition}\n%%EOF";

        return $pdf;
    }
}
