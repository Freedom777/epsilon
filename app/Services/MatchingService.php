<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Item;
use App\Models\ProductPending;
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

class MatchingService
{
    private ?Collection $itemsIndex  = null;
    private ?Collection $assetsIndex = null;

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

        // 4. Нечёткий матч в items
        $result = $this->fuzzyMatchItem($normalized, $grade);
        if ($result) return $result;

        // 5. Нечёткий матч в assets
        $result = $this->fuzzyMatchAsset($normalized, $grade);
        if ($result) return $result;

        // 6. Не нашли — отправляем в product_pendings
        $this->queuePending($rawTitle, $normalized);

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

    private function fuzzyMatchItem(string $normalized, ?string $grade): ?MatchResult
    {
        $this->loadIndexes();

        $best      = null;
        $bestScore = 0.0;

        foreach ($this->itemsIndex as $item) {
            if ($grade && $item->grade && $item->grade !== $grade) {
                continue;
            }

            similar_text($normalized, $item->normalized_title, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best      = $item;
            }
        }

        if ($bestScore < $this->threshold() || !$best) {
            return null;
        }

        return new MatchResult('item', $best->id, $best, 'fuzzy', $bestScore);
    }

    private function fuzzyMatchAsset(string $normalized, ?string $grade): ?MatchResult
    {
        $this->loadIndexes();

        $best      = null;
        $bestScore = 0.0;

        foreach ($this->assetsIndex as $asset) {
            if ($grade && $asset->grade && $asset->grade !== $grade) {
                continue;
            }

            similar_text($normalized, $asset->normalized_title, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best      = $asset;
            }
        }

        if ($bestScore < $this->threshold() || !$best) {
            return null;
        }

        return new MatchResult('asset', $best->id, $best, 'fuzzy', $bestScore);
    }

    // =========================================================================
    // Очередь на модерацию
    // =========================================================================

    private function queuePending(string $rawTitle, string $normalized): void
    {
        // Убираем невалидные UTF-8 последовательности из raw_title
        $cleanRawTitle = mb_convert_encoding($rawTitle, 'UTF-8', 'UTF-8');
        $cleanRawTitle = preg_replace('/[\x{FFFD}]/u', '', $cleanRawTitle) ?? $cleanRawTitle;
        $cleanRawTitle = trim($cleanRawTitle);

        if (blank($cleanRawTitle)) {
            return;
        }

        $existing = ProductPending::where('normalized_title', $normalized)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            $existing->increment('occurrences');
        } else {
            ProductPending::create([
                'raw_title'        => $cleanRawTitle,
                'normalized_title' => $normalized,
                'source_type'      => null,
                'suggested_id'     => null,
                'match_score'      => null,
                'match_reason'     => 'no_match',
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
        if (blank($title)) {
            return '';
        }
        // Убираем невалидные UTF-8 последовательности
        $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');

        // Убираем символ замены U+FFFD и прочий мусор
        $title = preg_replace('/[\x{FFFD}]/u', '', $title) ?? $title;
        // Убираем [Ивент] и грейды [I], [II] ...
        $title = preg_replace('/\s*\[Ивент]\s*|\s*\[[IVX+]+]\s*/u', '', $title) ?? $title;

        $title = preg_replace('/[^\x{0400}-\x{04FF}0-9a-zA-Z%+\- ]/u', '', $title) ?? $title;
        // $title = preg_replace('/[^\x{0400}-\x{04FF}0-9a-zA-Z%+\- ]/u', '', $title) ?? $title;
        // $title = preg_replace('/\s*\[[IVX+]+\]\s*$/u', '', $title) ?? $title;

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
