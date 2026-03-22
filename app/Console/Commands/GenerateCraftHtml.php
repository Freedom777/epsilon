<?php

namespace App\Console\Commands;

use App\Models\CraftRecipe;
use Illuminate\Console\Command;

class GenerateCraftHtml extends Command
{
    protected $signature = 'craft:generate';
    protected $description = 'Генерирует статическую HTML-страницу с рецептами крафта';

    private const TAB_CONFIG = [
        'I'    => ['label' => 'Грейд I',   'icon' => '🟢'],
        'II'   => ['label' => 'Грейд II',  'icon' => '🔵'],
        'III'  => ['label' => 'Грейд III', 'icon' => '🟣'],
        'III+' => ['label' => 'Грейд III+','icon' => '🟡'],
        'IV'   => ['label' => 'Грейд IV',  'icon' => '🔴'],
    ];

    public function handle(): int
    {
        $this->info('Генерация HTML крафта...');

        $recipes = CraftRecipe::with(['item', 'asset', 'npc.city', 'components.asset'])
            ->get();

        $html = $this->renderHtml($recipes);

        $path = public_path('craft.html');
        file_put_contents($path, $html);

        $this->info("Готово: {$path} ({$recipes->count()} рецептов)");

        return self::SUCCESS;
    }

    private function renderHtml($recipes): string
    {
        // Группируем по грейду
        $grouped = [];
        foreach ($recipes as $recipe) {
            $product = $recipe->item ?? $recipe->asset;
            $grade   = $product?->grade ?? null;

            if ($grade && isset(self::TAB_CONFIG[$grade])) {
                $grouped[$grade][] = $recipe;
            } else {
                $grouped['I'][] = $recipe;
            }
        }

        // Сортируем внутри каждой группы по названию
        foreach ($grouped as &$group) {
            usort($group, function ($a, $b) {
                $nameA = ($a->item ?? $a->asset)?->normalized_title ?? '';
                $nameB = ($b->item ?? $b->asset)?->normalized_title ?? '';
                return strcmp($nameA, $nameB);
            });
        }
        unset($group);

        $availableTabs = array_keys($grouped);

        // Первая доступная вкладка
        $defaultTab = null;
        foreach (self::TAB_CONFIG as $key => $_) {
            if (in_array($key, $availableTabs)) {
                $defaultTab = $key;
                break;
            }
        }
        $defaultTab ??= $availableTabs[0] ?? 'I';

        // Табы
        $tabsHtml = '';
        foreach (self::TAB_CONFIG as $key => $config) {
            if (!in_array($key, $availableTabs)) continue;

            $count  = count($grouped[$key]);
            $tabId  = 'tab-' . md5($key);
            $tabsHtml .= "<button class=\"tab\" data-tab=\"{$tabId}\" onclick=\"switchTab('{$tabId}')\">"
                . "{$config['icon']} {$config['label']}"
                . " <span class=\"count\">{$count}</span>"
                . "</button>";
        }

        // Таблицы
        $tablesHtml = '';
        foreach (self::TAB_CONFIG as $key => $config) {
            if (!in_array($key, $availableTabs)) continue;

            $tabId = 'tab-' . md5($key);
            $rows  = '';

            foreach ($grouped[$key] as $recipe) {
                $rows .= $this->renderRecipeRow($recipe);
            }

            $tablesHtml .= "<div class=\"tab-content\" id=\"{$tabId}\">"
                . "<div class=\"table-wrap\"><table class=\"craft-table\"><thead><tr>"
                . "<th>{$config['icon']} Предмет</th>"
                . "<th>🏛 Город</th>"
                . "<th>⚒ NPC</th>"
                . "<th>📊 Уровень</th>"
                . "<th>🔋</th>"
                . "<th>📦 Компоненты</th>"
                . "</tr></thead><tbody>{$rows}</tbody></table></div>"
                . "</div>";
        }

        $defaultTabId = 'tab-' . md5($defaultTab);
        $generated    = now()->format('d.m.Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Крафт — Epsilion War</title>
    <link rel="stylesheet" href="/css/epsilon.css">
</head>
<body>

<nav class="site-nav">
    <a href="/market.html">🏪 Рынок</a>
    <a href="/mobs.html">⚔ Бестиарий</a>
    <a href="/craft.html" class="active">🔨 Крафт</a>
</nav>

<div class="page-header">
    <h1>🔨 Рецепты крафта</h1>
    <div class="meta">Обновлено: {$generated}</div>
</div>

<div class="search-wrap">
    <input type="text" id="craft-search" placeholder="Поиск по предмету или компоненту..." autocomplete="off">
    <button id="clear-search" onclick="clearSearch()">✕ Сбросить</button>
</div>

<div class="tabs">{$tabsHtml}</div>

{$tablesHtml}

<div id="no-results">Ничего не найдено</div>

<script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.querySelector('[data-tab="' + tabId + '"]').classList.add('active');
        localStorage.setItem('craft_tab', tabId);
        updateNoResults();
    }

    // Поиск
    const searchInput = document.getElementById('craft-search');
    const clearBtn    = document.getElementById('clear-search');
    const noResults   = document.getElementById('no-results');
    let searchTimer   = null;

    searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        clearTimeout(searchTimer);

        if (q.length === 0) {
            clearSearch();
            return;
        }

        clearBtn.style.display = 'inline-block';

        if (q.length < 3) return;

        searchTimer = setTimeout(() => filterRows(q), 200);
    });

    function filterRows(q) {
        let anyVisible = false;

        document.querySelectorAll('.tab-content').forEach(tab => {
            let tabHasVisible = false;

            tab.querySelectorAll('tbody tr').forEach(row => {
                const text = row.dataset.search || '';
                const match = text.includes(q);
                row.classList.toggle('hidden', !match);

                // Подсветка компонентов
                row.querySelectorAll('.comp-list li').forEach(li => {
                    const liText = li.textContent.toLowerCase();
                    li.classList.toggle('highlight', liText.includes(q));
                });

                // Открываем details если нашли в компонентах
                if (match) {
                    tabHasVisible = true;
                    const details = row.querySelector('.drop-details');
                    if (details && text.includes(q) && !row.dataset.title.toLowerCase().includes(q)) {
                        details.open = true;
                    }
                }
            });

            if (tabHasVisible) anyVisible = true;
        });

        // Подсветка табов с результатами
        document.querySelectorAll('.tab').forEach(btn => {
            const tabId = btn.dataset.tab;
            const tab = document.getElementById(tabId);
            if (!tab) return;
            const hasVisible = tab.querySelector('tbody tr:not(.hidden)');
            btn.classList.toggle('tab-has-results', !!hasVisible);
        });

        updateNoResults();
    }

    function clearSearch() {
        searchInput.value = '';
        clearBtn.style.display = 'none';

        document.querySelectorAll('tbody tr').forEach(row => {
            row.classList.remove('hidden');
            row.querySelectorAll('.comp-list li').forEach(li => li.classList.remove('highlight'));
        });
        document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('tab-has-results'));

        updateNoResults();
    }

    function updateNoResults() {
        const activeTab = document.querySelector('.tab-content.active');
        if (!activeTab) { noResults.style.display = 'none'; return; }
        const visible = activeTab.querySelectorAll('tbody tr:not(.hidden)').length;
        noResults.style.display = visible === 0 ? 'block' : 'none';
    }

    // Инициализация
    const saved = localStorage.getItem('craft_tab');
    const target = saved && document.getElementById(saved) ? saved : '{$defaultTabId}';
    switchTab(target);
    fetch('/api/craft/ping', { method: 'POST' });
</script>

</body>
</html>
HTML;
    }

    private function renderRecipeRow(CraftRecipe $recipe): string
    {
        $product = $recipe->item ?? $recipe->asset;
        $title   = $this->e($product?->title ?? '—');
        $city = $recipe->npc?->city?->title
            ? $this->e($recipe->npc->city->title)
            : '<span class="text-muted">—</span>';
        $npc = $recipe->npc
            ? $this->e($recipe->npc->title)
            : '<span class="text-muted">—</span>';
        $level   = $recipe->craft_level ? $this->e($recipe->craft_level) : '—';
        $energy  = $recipe->energy_cost ? "{$recipe->energy_cost} 🔋" : '—';

        // Компоненты
        $compHtml = '';
        if ($recipe->components->isNotEmpty()) {
            $items = '';
            foreach ($recipe->components as $comp) {
                $compName = $this->e($comp->asset?->title ?? '—');
                $items   .= "<li>{$compName} <span class=\"comp-qty\">×{$comp->quantity}</span></li>";
            }

            $compHtml = <<<HTML
                <details class="drop-details">
                    <summary>{$recipe->components->count()} компонентов</summary>
                    <ul class="comp-list">{$items}</ul>
                </details>
HTML;
        }

        // data-search для JS-фильтра
        $searchParts = [mb_strtolower($product?->title ?? '')];
        foreach ($recipe->components as $comp) {
            $searchParts[] = mb_strtolower($comp->asset?->title ?? '');
        }
        $searchData = $this->e(implode(' ', $searchParts));
        $titleData  = $this->e(mb_strtolower($product?->title ?? ''));

        return <<<HTML
            <tr data-search="{$searchData}" data-title="{$titleData}">
                <td class="td-title">{$title}</td>
                <td class="td-city">{$city}</td>
                <td class="td-npc">{$npc}</td>
                <td class="td-craft-level">{$level}</td>
                <td class="td-energy">{$energy}</td>
                <td class="td-components">{$compHtml}</td>
            </tr>
HTML;
    }

    private function e(?string $str): string
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}
