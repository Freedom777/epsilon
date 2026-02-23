<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Item;
use App\Models\ProductPending;
use App\Support\Utf8Helper;
use Illuminate\Support\Collection;

class MatchResult
{
    public function __construct(
        public readonly string     $sourceType, // 'asset' | 'item'
        public readonly int        $id,
        public readonly Asset|Item $model,
        public readonly string     $matchType,  // 'exact' | 'fuzzy' | 'approved'
        public readonly float      $score,      // 100.0 для exact
    ) {}

    public function isAsset(): bool
    {
        return $this->sourceType === 'asset';
    }

    public function isItem(): bool
    {
        return $this->sourceType === 'item';
    }
}

/**
 * Лучший кандидат fuzzy-матча (может быть ниже порога).
 */
class FuzzyCandidate
{
    public function __construct(
        public readonly string     $sourceType, // 'asset' | 'item'
        public readonly int        $id,
        public readonly Asset|Item $model,
        public readonly float      $score,
    ) {}
}

class MatchingService
{
    private ?Collection $itemsIndex  = null;
    private ?Collection $assetsIndex = null;

    /** Минимальный score для показа кандидата в pending (ниже — no_match) */
    private const LOW_SCORE_FLOOR = 50.0;

    private function threshold(): float
    {
        return (float) config('parser.matching.fuzzy_threshold', 85);
    }

    // =========================================================================
    // Публичный API
    // =========================================================================

    public function match(string $rawTitle, ?string $grade = null): ?MatchResult
    {
        if (blank($rawTitle)) {
            return null;
        }

        // Защита от склеенных строк парсера — не матчим и не пишем мусор
        if (mb_strlen($rawTitle) > 120) {
            return null;
        }

        $normalized = $this->normalize($rawTitle);

        // 1. Точный матч в items
        $result = $this->exactMatchItem($normalized, $grade);
        if ($result) return $result;

        // 2. Точный матч в assets
        $result = $this->exactMatchAsset($normalized, $grade);
        if ($result) return $result;

        // 3. Апрувнутые записи в product_pendings
        $result = $this->approvedMatch($normalized);
        if ($result) return $result;

        // 4. Нечёткий матч — собираем лучших кандидатов из items и assets
        $candidates = array_filter([
            $this->fuzzyBestCandidate($normalized, $grade, 'item'),
            $this->fuzzyBestCandidate($normalized, $grade, 'asset'),
        ]);

        // Берём лучшего по score
        $best = null;
        foreach ($candidates as $candidate) {
            if (!$best || $candidate->score > $best->score) {
                $best = $candidate;
            }
        }

        // 5. Score выше порога — автоматический матч
        if ($best && $best->score >= $this->threshold()) {
            return new MatchResult(
                $best->sourceType,
                $best->id,
                $best->model,
                'fuzzy',
                $best->score,
            );
        }

        // 6. Не нашли или низкий score — в очередь на модерацию
        $this->queuePending($rawTitle, $normalized, $best);

        return null;
    }

    // =========================================================================
    // Точный матч
    // =========================================================================

    private function exactMatchItem(string $normalized, ?string $grade): ?MatchResult
    {
        $item = Item::where('normalized_title', $normalized)
            ->where('grade', $grade)
            ->where('status', 'ok')
            ->first();

        if (!$item) return null;

        return new MatchResult('item', $item->id, $item, 'exact', 100.0);
    }

    private function exactMatchAsset(string $normalized, ?string $grade): ?MatchResult
    {
        $query = Asset::where('normalized_title', $normalized)
            ->where('status', 'ok');

        if ($grade) {
            $query->where('grade', $grade);
        }

        $asset = $query->first();

        if (!$asset) return null;

        return new MatchResult('asset', $asset->id, $asset, 'exact', 100.0);
    }

    // =========================================================================
    // Апрувнутые записи
    // =========================================================================

    private function approvedMatch(string $normalized): ?MatchResult
    {
        $pending = ProductPending::where('normalized_title', $normalized)
            ->where('status', 'approved')
            ->whereNotNull('suggested_id')
            ->whereNotNull('source_type')
            ->first();

        if (!$pending) return null;

        if ($pending->source_type === 'item') {
            $model = Item::find($pending->suggested_id);
            if (!$model) return null;
            return new MatchResult('item', $model->id, $model, 'approved', 100.0);
        }

        $model = Asset::find($pending->suggested_id);
        if (!$model) return null;
        return new MatchResult('asset', $model->id, $model, 'approved', 100.0);
    }

    // =========================================================================
    // Нечёткий матч
    // =========================================================================

    /**
     * Возвращает лучшего кандидата по score из items или assets.
     * Возвращает ВСЕГДА (даже ниже порога), чтобы передать в pending.
     * null только если коллекция пуста или score < LOW_SCORE_FLOOR.
     */
    private function fuzzyBestCandidate(string $normalized, ?string $grade, string $sourceType): ?FuzzyCandidate
    {
        $this->loadIndexes();

        $index = $sourceType === 'item' ? $this->itemsIndex : $this->assetsIndex;

        $best      = null;
        $bestScore = 0.0;

        foreach ($index as $record) {
            if ($grade && $record->grade && $record->grade !== $grade) {
                continue;
            }

            similar_text($normalized, $record->normalized_title, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best      = $record;
            }
        }

        if (!$best || $bestScore < self::LOW_SCORE_FLOOR) {
            return null;
        }

        return new FuzzyCandidate($sourceType, $best->id, $best, $bestScore);
    }

    // =========================================================================
    // Очередь на модерацию
    // =========================================================================

    private function queuePending(string $rawTitle, string $normalized, ?FuzzyCandidate $candidate = null): void
    {
        // Убираем невалидные UTF-8 последовательности из raw_title
        $cleanRawTitle = Utf8Helper::clean($rawTitle);

        if (blank($cleanRawTitle)) {
            return;
        }

        // Определяем reason и данные кандидата
        $suggestedId = $candidate?->id;
        $sourceType  = $candidate?->sourceType;
        $matchScore  = $candidate ? round($candidate->score, 1) : null;
        $matchReason = $candidate ? 'low_score' : 'no_match';

        $existing = ProductPending::where('normalized_title', $normalized)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            $existing->increment('occurrences');

            // Обновляем кандидата если новый score лучше
            if ($matchScore && (!$existing->match_score || $matchScore > $existing->match_score)) {
                $existing->update([
                    'source_type'  => $sourceType,
                    'suggested_id' => $suggestedId,
                    'match_score'  => $matchScore,
                    'match_reason' => $matchReason,
                ]);
            }
        } else {
            ProductPending::create([
                'raw_title'        => $cleanRawTitle,
                'normalized_title' => $normalized,
                'source_type'      => $sourceType,
                'suggested_id'     => $suggestedId,
                'match_score'      => $matchScore,
                'match_reason'     => $matchReason,
                'occurrences'      => 1,
                'status'           => 'pending',
            ]);
        }
    }

    // =========================================================================
    // Вспомогательные методы
    // =========================================================================

    public function normalize(?string $title): string
    {
        // Убираем невалидные UTF-8 последовательности
        $title = Utf8Helper::clean($title);
        if (blank($title)) {
            return '';
        }

        $title = preg_replace('/\s*\[Ивент]\s*|\s*\[[IVX+]+]\s*/u', '', $title) ?? $title;
        $title = preg_replace('/[^\x{0400}-\x{04FF}0-9a-zA-Z%+\- ]/u', '', $title) ?? $title;

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? $title));
    }

    private function loadIndexes(): void
    {
        $this->itemsIndex  ??= Item::select(['id', 'normalized_title', 'grade', 'status'])
            ->where('status', 'ok')
            ->get();

        $this->assetsIndex ??= Asset::select(['id', 'normalized_title', 'grade', 'status'])
            ->where('status', 'ok')
            ->get();
    }
}
