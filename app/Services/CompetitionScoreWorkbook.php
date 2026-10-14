<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Competition;
use App\Models\Event;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

final class CompetitionScoreWorkbook
{
    private const MARKER = 'TABULATION_SCORE_TEMPLATE_V1';

    public function __construct(private readonly CompetitionScoreExporter $exporter) {}

    public function export(Event $event, Competition $competition): string
    {
        $event->load(['participants' => fn ($query) => $query->orderBy('name')]);
        $competition->load(['criteria' => fn ($query) => $query->orderBy('id'), 'results.criterionScores']);
        $rows = [
            [self::MARKER, $competition->getKey(), $event->getKey()],
            $this->headers($competition),
        ];
        $results = $competition->results->keyBy('participant_id');

        foreach ($event->participants as $participant) {
            $result = $results->get($participant->getKey());
            $scores = $result?->criterionScores->keyBy(
                static fn ($score): string => $score->judge_number.':'.$score->criterion_id,
            );

            for ($judge = 1; $judge <= $competition->judge_count; $judge++) {
                $row = [$participant->getKey(), $participant->name, $result?->has_entry === false ? 0 : 1, $judge];

                foreach ($competition->criteria as $criterion) {
                    $score = $scores?->get($judge.':'.$criterion->getKey());
                    $row[] = $score === null ? null : (float) $score->score;
                }

                $rows[] = $row;
            }
        }

        return $this->exporter->xlsxWorksheet($this->worksheet($rows));
    }

    /** @return array{entries: array<int, int>, scores: array<int, array<int, array<int, float|null>>>} */
    public function import(string $path, Event $event, Competition $competition): array
    {
        $event->load(['participants' => fn ($query) => $query->orderBy('name')]);
        $competition->load(['criteria' => fn ($query) => $query->orderBy('id')]);
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            $this->invalid('Upload a valid Excel score template.');
        }

        try {
            $sheetInfo = $archive->statName('xl/worksheets/sheet1.xml');
            $sharedInfo = $archive->statName('xl/sharedStrings.xml');

            if ($sheetInfo === false || $sheetInfo['size'] > 15000000
                || ($sharedInfo !== false && $sharedInfo['size'] > 2000000)) {
                $this->invalid('The score template is missing or too large.');
            }

            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $shared = $archive->getFromName('xl/sharedStrings.xml');

            if ($sheet === false || ($shared !== false && strlen($shared) > 2000000)) {
                $this->invalid('The score template could not be read.');
            }
        } finally {
            $archive->close();
        }

        $sharedStrings = [];

        if ($shared !== false) {
            foreach ($this->xml($shared)->si as $item) {
                $sharedStrings[] = implode('', array_map(
                    static fn (SimpleXMLElement $text): string => (string) $text,
                    $item->xpath('.//*[local-name()="t"]') ?: [],
                ));
            }
        }

        $rows = [];

        foreach ($this->xml($sheet)->sheetData->row as $row) {
            if (count($rows) > 20000) {
                $this->invalid('The score template has too many rows.');
            }

            $cells = [];

            foreach ($row->c as $cell) {
                if (isset($cell->f)) {
                    $this->invalid('Formulas are not allowed in the score template.');
                }

                if (! preg_match('/^([A-Z]+)[0-9]+$/', (string) $cell['r'], $match)) {
                    $this->invalid('The score template has an invalid cell reference.');
                }

                $column = 0;

                foreach (str_split($match[1]) as $letter) {
                    $column = $column * 26 + ord($letter) - 64;
                }

                if ($column > 100 || array_key_exists($column - 1, $cells)) {
                    $this->invalid('The score template has invalid columns.');
                }

                $cells[$column - 1] = match ((string) $cell['t']) {
                    'inlineStr' => (string) $cell->is->t,
                    's' => $sharedStrings[(int) $cell->v] ?? $this->invalid('The score template has an invalid shared string.'),
                    '', 'n' => (string) $cell->v,
                    default => $this->invalid('The score template has an unsupported cell type.'),
                };
            }

            $rows[] = $cells;
        }

        if (($rows[0][0] ?? null) !== self::MARKER
            || ($rows[0][1] ?? null) !== (string) $competition->getKey()
            || ($rows[0][2] ?? null) !== (string) $event->getKey()) {
            $this->invalid('This score template belongs to another event or contest.');
        }

        $headers = $this->headers($competition);

        if (array_values($rows[1] ?? []) !== $headers) {
            $this->invalid('The score template criteria have changed. Export a new template.');
        }

        if (count($rows) !== $event->participants->count() * $competition->judge_count + 2) {
            $this->invalid('The score template must include every participant and judge exactly once.');
        }

        $entries = [];
        $scores = [];
        $rowIndex = 2;

        foreach ($event->participants as $participant) {
            for ($judge = 1; $judge <= $competition->judge_count; $judge++) {
                $values = $rows[$rowIndex++] ?? [];

                if (($values[0] ?? null) !== (string) $participant->getKey()
                    || ($values[1] ?? null) !== $participant->name
                    || ($values[3] ?? null) !== (string) $judge
                    || ! in_array($values[2] ?? null, ['0', '1'], true)
                    || count($values) > count($headers)) {
                    $this->invalid('Participant, judge, or entry data was changed in the score template.');
                }

                $id = $participant->getKey();
                $entry = (int) $values[2];

                if (isset($entries[$id]) && $entries[$id] !== $entry) {
                    $this->invalid('A participant must have the same entry status for every judge.');
                }

                $entries[$id] = $entry;

                foreach ($competition->criteria as $index => $criterion) {
                    $value = $values[$index + 4] ?? '';

                    if ($value !== '' && (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)
                        || (float) $value > (float) $criterion->max_score)) {
                        $this->invalid("Row {$rowIndex} has an invalid score for {$criterion->name}.");
                    }

                    $scores[$id][$criterion->getKey()][$judge] = $value === '' ? null : (float) $value;
                }
            }
        }

        return compact('entries', 'scores');
    }

    /** @return array<int, string> */
    private function headers(Competition $competition): array
    {
        return array_merge(['Participant ID', 'Participant', 'Has entry (1 or 0)', 'Judge'], $competition->criteria->map(
            static fn ($criterion): string => $criterion->name.' [ID:'.$criterion->getKey().'] (max '.$criterion->max_score.')',
        )->all());
    }

    /** @param array<int, array<int, int|float|string|null>> $rows */
    private function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $index => $values) {
            $number = $index + 1;
            $xml .= "<row r=\"{$number}\">";

            foreach ($values as $column => $value) {
                $columnNumber = $column + 1;
                $name = '';

                while ($columnNumber > 0) {
                    $columnNumber--;
                    $name = chr(65 + $columnNumber % 26).$name;
                    $columnNumber = intdiv($columnNumber, 26);
                }

                $reference = $name.$number;
                $xml .= $value === null
                    ? "<c r=\"{$reference}\"/>"
                    : (is_int($value) || is_float($value)
                        ? "<c r=\"{$reference}\"><v>{$value}</v></c>"
                        : "<c r=\"{$reference}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".htmlspecialchars(
                            preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '',
                            ENT_XML1 | ENT_QUOTES,
                            'UTF-8',
                        ).'</t></is></c>');
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function xml(string $source): SimpleXMLElement
    {
        $xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            $this->invalid('The score template contains invalid XML.');
        }

        return $xml;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['score_file' => $message]);
    }
}
