<?php

namespace App\Console\Commands;

use App\Models\Mob;
use App\Models\MobDropIndex;
use Illuminate\Console\Command;

class GenerateMobsHtml extends Command
{
    protected $signature = 'mobs:generate
                            {--skip-index : Пропустить пересборку индекса дропа}';

    protected $description = 'Генерирует статическую HTML-страницу со списком мобов';

    public function handle(): int
    {
        if (!$this->option('skip-index')) {
            $this->rebuildDropIndex();
        }

        $this->info('Генерация HTML...');
        $html = $this->renderHtml();

        $path = public_path('mobs.html');
        file_put_contents($path, $html);

        $this->info("Готово: {$path}");

        return self::SUCCESS;
    }

    private function rebuildDropIndex(): void
    {
        $this->info('Пересборка индекса дропа...');

        MobDropIndex::truncate();

        $mobs = Mob::where('status', 'ok')
            ->whereHas('dropAssets')
            ->with('dropAssets')
            ->get();

        $bar = $this->output->createProgressBar($mobs->count());
        $bar->start();

        foreach ($mobs as $mob) {
            $this->indexMobDrop($mob);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function indexMobDrop(Mob $mob): void
    {
        foreach ($mob->dropAssets as $asset) {
            MobDropIndex::create([
                'mob_id'     => $mob->id,
                'asset_id'   => $asset->id,
                'drop_text'  => $asset->title,
                'normalized' => $asset->normalized_title ?? mb_strtolower($asset->title),
            ]);
        }
    }

    private function renderHtml(): string
    {
        $mobs = Mob::where('status', 'ok')
            ->with(['locationRef.city', 'dropAssets', 'dropItems'])
            ->orderBy('level')
            ->get();

        $rows = '';
        foreach ($mobs as $mob) {
            $dropAssetHtml = $this->renderDrop($mob->drop_asset, 'Дроп ресурсов');
            $dropItemHtml  = $mob->drop_item
                ? $this->renderDrop($mob->drop_item, 'Дроп предметов')
                : '';

            $dropHtml = '';
            if ($dropAssetHtml || $dropItemHtml) {
                $dropHtml = <<<HTML
                <details class="drop-details">
                    <summary>Показать дроп</summary>
                    {$dropAssetHtml}{$dropItemHtml}
                </details>
HTML;
            }

            $rows .= <<<HTML
            <tr data-title="{$this->e($mob->title)}" data-level="{$mob->level}">
                <td class="td-level">{$mob->level}</td>
                <td class="td-title">{$this->e($mob->title)}</td>
                <td class="td-location">{$this->e($mob->city_name)}<span class="location-sep">›</span>{$this->e($mob->location_name)}</td>
                <td class="td-exp">{$this->fmt($mob->exp)}</td>
                <td class="td-gold">{$this->fmt($mob->gold)} 💰</td>
                <td class="td-drop">{$dropHtml}</td>
            </tr>
HTML;
        }

        return $this->htmlTemplate($rows);
    }

    private function renderDrop(?array $items, string $title): string
    {
        if (empty($items)) {
            return '';
        }

        $list = implode('', array_map(
            fn($item) => '<li>' . $this->e($item) . '</li>',
            $items
        ));

        return <<<HTML
        <div class="drop-group">
            <div class="drop-group-title">{$title}</div>
            <ul class="drop-list">{$list}</ul>
        </div>
HTML;
    }

    private function e(?string $str): string
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }

    private function fmt(?int $num): string
    {
        return $num !== null ? number_format($num, 0, '.', ' ') : '—';
    }

    private function htmlTemplate(string $rows): string
    {
        $generated = now()->format('d.m.Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Бестиарий — Epsilion War</title>
    <link rel="stylesheet" href="/css/epsilon.css">
</head>
<body>

<nav class="site-nav">
    <a href="/market.html">🏪 Рынок</a>
    <a href="/mobs.html" class="active">⚔ Бестиарий</a>
</nav>

<div class="page-header">
    <h1>⚔ Бестиарий</h1>
    <div class="meta">Обновлено: {$generated}</div>
</div>

<div class="search-wrap">
    <input type="text" id="search" placeholder="Поиск по мобу или ресурсу (от 4 символов)..." autocomplete="off">
    <button id="clear-search" onclick="clearSearch()">✕ Сбросить</button>
    <div id="search-dropdown"></div>
</div>

<div class="table-wrap">
    <table id="mobs-table">
        <thead>
            <tr>
                <th style="text-align:center">Ур.</th>
                <th>Моб</th>
                <th>Локация</th>
                <th style="text-align:right">Опыт</th>
                <th style="text-align:right">Золото</th>
                <th>Дроп</th>
            </tr>
        </thead>
        <tbody id="mobs-tbody">
            {$rows}
        </tbody>
    </table>
    <div id="no-results">Ничего не найдено</div>
</div>

<script>
    const rows       = Array.from(document.querySelectorAll('#mobs-tbody tr'));
    const dropdown   = document.getElementById('search-dropdown');
    const clearBtn   = document.getElementById('clear-search');
    const noResults  = document.getElementById('no-results');
    let searchTimer  = null;
    let activeFilter = null;

    document.getElementById('search').addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(searchTimer);
        dropdown.style.display = 'none';

        if (q.length === 0) {
            clearSearch();
            return;
        }

        clearBtn.style.display = 'inline-block';

        if (q.length < 4) return;

        searchTimer = setTimeout(() => handleSearch(q), 300);
    });

    function handleSearch(q) {
        const ql = q.toLowerCase();

        let mobMatches = 0;
        rows.forEach(row => {
            const title = row.dataset.title.toLowerCase();
            const match = title.includes(ql);
            row.classList.toggle('hidden', !match);
            if (match) mobMatches++;
        });

        updateNoResults();

        if (mobMatches > 0) {
            dropdown.style.display = 'none';
            return;
        }

        fetch('/api/mobs/search?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => showDropdown(data, q))
            .catch(() => {});
    }

    function showDropdown(items, q) {
        if (!items.length) {
            dropdown.style.display = 'none';
            return;
        }

        dropdown.innerHTML = items.map(item => `
            <div class="drop-item" onclick="filterByAsset('\${escHtml(item.normalized)}', '\${escHtml(item.drop_text)}')">
                \${escHtml(item.drop_text)}
                <span class="drop-label">\${item.mob_count} мобов</span>
            </div>
        `).join('');

        dropdown.style.display = 'block';
    }

    function filterByAsset(normalized, dropText) {
        dropdown.style.display = 'none';
        document.getElementById('search').value = dropText;
        activeFilter = { type: 'asset', normalized };

        rows.forEach(row => {
            const drops = row.querySelectorAll('.drop-list li');
            let found = false;
            drops.forEach(li => {
                const txt = li.textContent.toLowerCase();
                if (txt.includes(normalized)) {
                    li.classList.add('highlight');
                    found = true;
                } else {
                    li.classList.remove('highlight');
                }
            });
            if (found) {
                const details = row.querySelector('.drop-details');
                if (details) details.open = true;
            }
            row.classList.toggle('hidden', !found);
        });

        updateNoResults();
        clearBtn.style.display = 'inline-block';
    }

    function clearSearch() {
        document.getElementById('search').value = '';
        dropdown.style.display = 'none';
        clearBtn.style.display = 'none';
        activeFilter = null;
        rows.forEach(row => {
            row.classList.remove('hidden');
            row.querySelectorAll('.drop-list li').forEach(li => li.classList.remove('highlight'));
        });
        updateNoResults();
    }

    function updateNoResults() {
        const visible = rows.filter(r => !r.classList.contains('hidden')).length;
        noResults.style.display = visible === 0 ? 'block' : 'none';
    }

    function escHtml(str) {
        return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.search-wrap')) {
            dropdown.style.display = 'none';
        }
    });
    fetch('/api/mobs/ping', { method: 'POST' });
</script>

</body>
</html>
HTML;
    }
}
