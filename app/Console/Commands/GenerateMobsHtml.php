<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Mob;
use App\Models\MobDropIndex;
use App\Services\MatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateMobsHtml extends Command
{
    protected $signature = 'mobs:generate
                            {--skip-index : Пропустить пересборку индекса дропа}';

    protected $description = 'Генерирует статическую HTML-страницу со списком мобов';

    public function __construct(private readonly MatchingService $matchingService)
    {
        parent::__construct();
    }

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
            ->whereNotNull('drop_asset')
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
        $drops = $mob->drop_asset ?? [];

        foreach ($drops as $dropText) {
            // Убираем иконку и нормализуем
            $clean      = trim(preg_replace('/^\p{So}\p{Sk}?\s*/u', '', $dropText));
            $clean      = preg_replace('/\[.*?\]/', '', $clean); // убираем грейд
            $normalized = mb_strtolower(trim($clean));

            if (blank($normalized)) {
                continue;
            }

            // Пытаемся найти asset
            $asset = Asset::whereRaw('LOWER(normalized_title) = ?', [$normalized])
                ->orWhereRaw('LOWER(title) LIKE ?', ['%' . $normalized . '%'])
                ->first();

            MobDropIndex::create([
                'mob_id'     => $mob->id,
                'asset_id'   => $asset?->id,
                'drop_text'  => $dropText,
                'normalized' => $normalized,
            ]);
        }
    }

    private function renderHtml(): string
    {
        $mobs = Mob::where('status', 'ok')
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
                <td class="td-location">{$this->e($mob->city)}<span class="location-sep">›</span>{$this->e($mob->location)}</td>
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
    <title>Мобы — Epsilion War</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600&family=Crimson+Pro:ital,wght@0,300;0,400;1,300&display=swap');

        :root {
            --bg:        #0d0f14;
            --surface:   #13161e;
            --border:    #1e2330;
            --accent:    #c9a84c;
            --accent2:   #7b6eaf;
            --text:      #d0cfc8;
            --text-dim:  #6b7280;
            --danger:    #e05c5c;
            --success:   #5cb85c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Crimson Pro', Georgia, serif;
            font-size: 16px;
            line-height: 1.5;
        }

        header {
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        header h1 {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            color: var(--accent);
            letter-spacing: 0.05em;
        }

        header p {
            color: var(--text-dim);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .search-wrap {
            padding: 1rem 2rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            position: relative;
        }

        #search {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.55rem 1rem;
            font-family: 'Crimson Pro', serif;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        #search:focus { border-color: var(--accent); }

        #search-dropdown {
            position: absolute;
            top: 100%;
            left: 2rem;
            width: 420px;
            background: var(--surface);
            border: 1px solid var(--accent);
            z-index: 100;
            display: none;
            max-height: 300px;
            overflow-y: auto;
        }

        #search-dropdown .drop-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
            font-size: 0.95rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }

        #search-dropdown .drop-item:hover { background: var(--border); }

        #search-dropdown .drop-item .drop-label {
            color: var(--text-dim);
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        #clear-search {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-dim);
            padding: 0.55rem 1rem;
            cursor: pointer;
            font-family: 'Crimson Pro', serif;
            font-size: 0.9rem;
            display: none;
            transition: border-color 0.2s, color 0.2s;
        }

        #clear-search:hover { border-color: var(--danger); color: var(--danger); }

        .table-wrap {
            padding: 0 2rem 3rem;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            font-family: 'Cinzel', serif;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            color: var(--accent);
            text-align: left;
            padding: 0.6rem 0.8rem;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background 0.1s;
        }

        tbody tr:hover { background: var(--surface); }
        tbody tr.hidden { display: none; }

        td {
            padding: 0.6rem 0.8rem;
            vertical-align: top;
        }

        .td-level {
            color: var(--accent);
            font-family: 'Cinzel', serif;
            font-size: 0.9rem;
            white-space: nowrap;
            text-align: center;
        }

        .td-title { font-size: 1rem; }

        .td-location {
            color: var(--text-dim);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .location-sep {
            margin: 0 0.4rem;
            color: var(--accent2);
        }

        .td-exp, .td-gold {
            white-space: nowrap;
            text-align: right;
            font-size: 0.95rem;
        }

        .td-gold { color: var(--accent); }

        .drop-details summary {
            cursor: pointer;
            color: var(--accent2);
            font-size: 0.85rem;
            user-select: none;
            list-style: none;
        }

        .drop-details summary::-webkit-details-marker { display: none; }
        .drop-details summary::before { content: '▶ '; font-size: 0.7rem; }
        .drop-details[open] summary::before { content: '▼ '; }

        .drop-group { margin-top: 0.6rem; }

        .drop-group-title {
            font-family: 'Cinzel', serif;
            font-size: 0.7rem;
            letter-spacing: 0.06em;
            color: var(--accent);
            margin-bottom: 0.3rem;
        }

        .drop-list {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 0.6rem;
        }

        .drop-list li {
            font-size: 0.85rem;
            color: var(--text-dim);
            white-space: nowrap;
        }

        .drop-list li.highlight { color: var(--accent); }

        #no-results {
            display: none;
            padding: 2rem;
            text-align: center;
            color: var(--text-dim);
            font-size: 1rem;
        }
    </style>
</head>
<body>

<header>
    <h1>⚔ Бестиарий</h1>
    <p>Обновлено: {$generated}</p>
</header>

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
    let activeFilter = null; // { type: 'mob'|'asset', value: string }

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

        // Сначала фильтруем по названию моба
        let mobMatches = 0;
        rows.forEach(row => {
            const title = row.dataset.title.toLowerCase();
            const match = title.includes(ql);
            row.classList.toggle('hidden', !match);
            if (match) mobMatches++;
        });

        updateNoResults();

        // Если мобы нашлись — дропдаун не показываем
        if (mobMatches > 0) {
            dropdown.style.display = 'none';
            return;
        }

        // Иначе — запрос к API для поиска по ресурсам
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
            <div class="drop-item" onclick="filterByAsset('${escHtml(item.normalized)}', '${escHtml(item.drop_text)}')">
                ${escHtml(item.drop_text)}
                <span class="drop-label">${item.mob_count} мобов</span>
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
            // Открываем details если нашли
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

    // Закрываем дропдаун при клике вне
    document.addEventListener('click', e => {
        if (!e.target.closest('.search-wrap')) {
            dropdown.style.display = 'none';
        }
    });
</script>

</body>
</html>
HTML;
    }
}
