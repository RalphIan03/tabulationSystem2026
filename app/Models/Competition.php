<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompetitionScoringMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Competition extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'scoring_method' => 'criteria',
        'judge_count' => 1,
    ];

    protected $fillable = [
        'name',
        'scoring_method',
        'judge_count',
        'non_podium_points',
        'scores_finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'scoring_method' => CompetitionScoringMethod::class,
            'judge_count' => 'integer',
            'non_podium_points' => 'decimal:2',
            'scores_finalized_at' => 'immutable_datetime',
        ];
    }

    public function usesCriteriaScoring(): bool
    {
        return $this->scoring_method === CompetitionScoringMethod::Criteria;
    }

    public function usesRankingScoring(): bool
    {
        return $this->scoring_method === CompetitionScoringMethod::Ranking;
    }

    public function scoresAreFinalized(): bool
    {
        return $this->scores_finalized_at !== null;
    }

    public function hasSavedScores(): bool
    {
        if (array_key_exists('has_saved_scores', $this->attributes)) {
            return (bool) $this->attributes['has_saved_scores'];
        }

        if ($this->relationLoaded('results')) {
            return $this->results->contains(fn (CompetitionResult $result): bool => $result->has_entry
                && ($this->usesCriteriaScoring()
                    ? $result->criterionScores->isNotEmpty()
                    : ($this->usesRankingScoring() ? $result->placement !== null : $result->wins !== null)));
        }

        return $this->results()
            ->where('has_entry', true)
            ->when($this->usesCriteriaScoring(),
                fn ($query) => $query->whereHas('criterionScores'),
                fn ($query) => $query->whereNotNull($this->usesRankingScoring() ? 'placement' : 'wins'))
            ->exists();
    }

    public function canBeConfiguredBy(?User $user): bool
    {
        return $user !== null
            && (! $this->scoresAreFinalized() || $user->isAdmin());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(Criterion::class);
    }

    public function rankScores(): HasMany
    {
        return $this->hasMany(RankScore::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CompetitionResult::class);
    }
}
