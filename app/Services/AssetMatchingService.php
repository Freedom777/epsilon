<?php

namespace App\Services;

use App\Models\Asset;

class AssetMatchingService
{
    private const AUTO_APPROVE_THRESHOLD = 95;  // % - матчим автоматически
    private const PENDING_THRESHOLD      = 70;  // % - отправляем на апрув
    // < 70% - создаём новую запись

    public function match(string $rawTitle): Asset|null
    {
        $normalized = $this->normalize($rawTitle);

        // 1. Проверяем уже апрувнутые записи в pending
        $approved = AssetPending::where('normalized_title', $normalized)
            ->where('status', 'approved')
            ->first();
        if ($approved?->asset_id) {
            return Asset::find($approved->asset_id);
        }

        // 2. Прямой матч в assets
        $asset = Asset::where('normalized_title', $normalized)->first();
        if ($asset) {
            return $asset;
        }

        // 3. Нечёткий матч
        [$asset, $score] = $this->fuzzyMatch($normalized);

        if ($score >= self::AUTO_APPROVE_THRESHOLD) {
            return $asset;
        }

        if ($score >= self::PENDING_THRESHOLD) {
            $this->createPending($rawTitle, $normalized, $asset, $score, 'low_score');
            return null;
        }

        $this->createPending($rawTitle, $normalized, null, $score, 'no_match');
        return null;
    }
}
