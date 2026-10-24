<?php

declare(strict_types=1);

namespace App\Enums;

enum CompetitionScoringMethod: string
{
    case Criteria = 'criteria';
    case Wins = 'wins';
    case Ranking = 'ranking';

    public function label(): string
    {
        return match ($this) {
            self::Criteria => 'Criteria-based',
            self::Wins => 'Wins-based',
            self::Ranking => 'Rank-order',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Criteria => 'Judges score each participant using configured criteria.',
            self::Wins => 'Standings are determined by each participant or team\'s total wins.',
            self::Ranking => 'Enter finishing positions to award base points. Final standings use points after deductions.',
        };
    }
}
