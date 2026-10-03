<?php

declare(strict_types=1);

/**
 * User manual generator (ENGINEERING_GUARDRAILS_PLAN.md part D).
 *
 * Reads docs/manual/{locale}/NN_slug.md, renders each to a static HTML page
 * under public/manual/{locale}/NN-slug.html, plus a per-locale index page
 * and a search-index.json the client-side search box reads. Deliberately a
 * standalone script (not an Artisan command): it only needs a Markdown
 * parser and the filesystem, not the framework, and the output is plain
 * static files nginx already serves from public/ with no new route.
 *
 * Usage: php tools/generate-manual.php
 */
require __DIR__.'/../vendor/autoload.php';

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * @var array<string, array{name: string, other: string, otherLabel: string}>
 */
const LOCALES = [
    'sk' => ['name' => 'Slovensky', 'other' => 'cs', 'otherLabel' => 'Česky'],
    'cs' => ['name' => 'Česky', 'other' => 'sk', 'otherLabel' => 'Slovensky'],
];

$root = dirname(__DIR__);
$manualSourceDir = $root.'/docs/manual';
$publicDir = $root.'/public/manual';

$environment = new Environment;
$environment->addExtension(new CommonMarkCoreExtension);
$environment->addExtension(new TableExtension);
$converter = new CommonMarkConverter([], $environment);

/**
 * @return list<array{slug: string, number: string, title: string, html: string, text: string}>
 */
function loadChapters(string $localeDir, CommonMarkConverter $converter): array
{
    $chapters = [];

    $files = glob($localeDir.'/*.md') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $path) {
        $basename = basename($path, '.md');

        if (! preg_match('/^(\d+)_(.+)$/', $basename, $m)) {
            fwrite(STDERR, "Skipping {$path}: expected NN_slug.md naming\n");

            continue;
        }

        $markdown = (string) file_get_contents($path);
        $firstLine = strtok($markdown, "\n");
        $title = $firstLine !== false ? trim(ltrim($firstLine, "# \t")) : $basename;

        $html = (string) $converter->convert($markdown);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        $chapters[] = [
            'slug' => $m[2],
            'number' => $m[1],
            'title' => $title,
            'html' => $html,
            'text' => $text,
        ];
    }

    return $chapters;
}

function pageShell(string $locale, string $title, string $body, string $activeSlug = ''): string
{
    $other = LOCALES[$locale]['other'];
    $otherLabel = LOCALES[$locale]['otherLabel'];
    $htmlTitle = htmlspecialchars($title, ENT_QUOTES);

    return <<<HTML
    <!DOCTYPE html>
    <html lang="{$locale}">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$htmlTitle} — Zoad</title>
    <link rel="stylesheet" href="/manual/assets/manual.css">
    </head>
    <body>
    <div class="manual-shell">
        <aside class="manual-nav">
            <a class="manual-brand" href="/manual/{$locale}/index.html">Zoad — návod</a>
            <nav id="chapter-nav"></nav>
            <a class="lang-switch" href="/manual/{$other}/index.html">{$otherLabel}</a>
        </aside>
        <main class="manual-content">
            <div class="search-box">
                <input id="search-input" type="search" placeholder="Hľadať v návode…" autocomplete="off">
                <div id="search-results"></div>
            </div>
            {$body}
        </main>
    </div>
    <script src="/manual/assets/manual.js" data-locale="{$locale}" data-active="{$activeSlug}"></script>
    </body>
    </html>
    HTML;
}

function ensureAssets(string $publicDir): void
{
    if (! is_dir($publicDir.'/assets')) {
        mkdir($publicDir.'/assets', 0755, true);
    }

    file_put_contents($publicDir.'/assets/manual.css', <<<'CSS'
    :root {
        color-scheme: light dark;
        --bg: #ffffff;
        --fg: #1a1a1a;
        --muted: #6b7280;
        --border: #e5e7eb;
        --accent: #2563eb;
        --nav-bg: #f9fafb;
        --code-bg: #f3f4f6;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #16181d;
            --fg: #e6e6e6;
            --muted: #9aa3af;
            --border: #2a2e37;
            --accent: #60a5fa;
            --nav-bg: #1c1f26;
            --code-bg: #20232b;
        }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: var(--bg);
        color: var(--fg);
        line-height: 1.6;
    }
    .manual-shell { display: flex; min-height: 100vh; max-width: 1200px; margin: 0 auto; }
    .manual-nav {
        width: 260px;
        flex-shrink: 0;
        background: var(--nav-bg);
        border-right: 1px solid var(--border);
        padding: 1.5rem 1rem;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .manual-brand { display: block; font-weight: 700; margin-bottom: 1.25rem; color: var(--fg); text-decoration: none; }
    #chapter-nav a {
        display: block;
        padding: 0.4rem 0.5rem;
        border-radius: 6px;
        color: var(--muted);
        text-decoration: none;
        font-size: 0.92rem;
    }
    #chapter-nav a:hover { background: var(--border); color: var(--fg); }
    #chapter-nav a.active { background: var(--accent); color: #fff; }
    .lang-switch {
        display: inline-block;
        margin-top: 1.5rem;
        font-size: 0.85rem;
        color: var(--accent);
        text-decoration: none;
    }
    .manual-content { flex: 1; padding: 2rem 3rem 4rem; min-width: 0; }
    .search-box { position: relative; margin-bottom: 2rem; }
    #search-input {
        width: 100%;
        padding: 0.6rem 0.9rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--bg);
        color: var(--fg);
        font-size: 1rem;
    }
    #search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 8px;
        margin-top: 0.25rem;
        max-height: 400px;
        overflow-y: auto;
        z-index: 10;
        display: none;
    }
    #search-results.open { display: block; }
    #search-results a { display: block; padding: 0.6rem 0.9rem; text-decoration: none; color: var(--fg); border-bottom: 1px solid var(--border); }
    #search-results a:last-child { border-bottom: none; }
    #search-results a:hover { background: var(--nav-bg); }
    #search-results .snippet { display: block; font-size: 0.85rem; color: var(--muted); margin-top: 0.15rem; }
    .manual-content h1 { font-size: 1.9rem; margin-top: 0; }
    .manual-content h2 { font-size: 1.4rem; margin-top: 2.2rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
    .manual-content h3 { font-size: 1.15rem; margin-top: 1.6rem; }
    .manual-content code { background: var(--code-bg); padding: 0.1rem 0.35rem; border-radius: 4px; font-size: 0.9em; }
    .manual-content pre { background: var(--code-bg); padding: 1rem; border-radius: 8px; overflow-x: auto; }
    .manual-content pre code { background: none; padding: 0; }
    .manual-content table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
    .manual-content th, .manual-content td { border: 1px solid var(--border); padding: 0.5rem 0.7rem; text-align: left; }
    .manual-content blockquote { margin: 1rem 0; padding: 0.5rem 1rem; border-left: 3px solid var(--accent); color: var(--muted); }
    .chapter-list { list-style: none; padding: 0; }
    .chapter-list li { margin-bottom: 0.5rem; }
    .chapter-list a { color: var(--accent); text-decoration: none; font-size: 1.05rem; }
    .chapter-list a:hover { text-decoration: underline; }
    .chapter-nav-links { display: flex; justify-content: space-between; margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border); }
    .chapter-nav-links a { color: var(--accent); text-decoration: none; }
    @media (max-width: 800px) {
        .manual-shell { flex-direction: column; }
        .manual-nav { position: static; height: auto; width: auto; border-right: none; border-bottom: 1px solid var(--border); }
        .manual-content { padding: 1.5rem; }
    }
    CSS);

    file_put_contents($publicDir.'/assets/manual.js', <<<'JS'
    (function () {
        var script = document.currentScript;
        var locale = script.getAttribute('data-locale');
        var active = script.getAttribute('data-active');

        fetch('/manual/' + locale + '/search-index.json')
            .then(function (r) { return r.json(); })
            .then(function (index) {
                renderNav(index);
                setupSearch(index);
            });

        function renderNav(index) {
            var nav = document.getElementById('chapter-nav');
            index.forEach(function (chapter) {
                var a = document.createElement('a');
                a.href = '/manual/' + locale + '/' + chapter.number + '-' + chapter.slug + '.html';
                a.textContent = chapter.number + '. ' + chapter.title;
                if (chapter.slug === active) a.className = 'active';
                nav.appendChild(a);
            });
        }

        function setupSearch(index) {
            var input = document.getElementById('search-input');
            var results = document.getElementById('search-results');

            input.addEventListener('input', function () {
                var query = input.value.trim().toLowerCase();

                if (query.length < 2) {
                    results.classList.remove('open');
                    results.innerHTML = '';

                    return;
                }

                var matches = index
                    .map(function (chapter) {
                        var pos = chapter.text.toLowerCase().indexOf(query);
                        if (pos === -1 && chapter.title.toLowerCase().indexOf(query) === -1) return null;

                        var snippetStart = Math.max(0, pos - 40);
                        var snippet = pos === -1
                            ? chapter.text.slice(0, 100)
                            : chapter.text.slice(snippetStart, pos + 80);

                        return { chapter: chapter, snippet: snippet };
                    })
                    .filter(function (m) { return m !== null; })
                    .slice(0, 8);

                results.innerHTML = '';

                if (matches.length === 0) {
                    results.classList.remove('open');

                    return;
                }

                matches.forEach(function (m) {
                    var a = document.createElement('a');
                    a.href = '/manual/' + locale + '/' + m.chapter.number + '-' + m.chapter.slug + '.html';

                    var title = document.createElement('span');
                    title.textContent = m.chapter.number + '. ' + m.chapter.title;
                    a.appendChild(title);

                    var snippet = document.createElement('span');
                    snippet.className = 'snippet';
                    snippet.textContent = '…' + m.snippet + '…';
                    a.appendChild(snippet);

                    results.appendChild(a);
                });

                results.classList.add('open');
            });

            document.addEventListener('click', function (event) {
                if (!results.contains(event.target) && event.target !== input) {
                    results.classList.remove('open');
                }
            });
        }
    })();
    JS);
}

$generatedLocales = 0;
$generatedChapters = 0;

foreach (array_keys(LOCALES) as $locale) {
    $localeSourceDir = $manualSourceDir.'/'.$locale;

    if (! is_dir($localeSourceDir)) {
        continue;
    }

    $chapters = loadChapters($localeSourceDir, $converter);

    if ($chapters === []) {
        continue;
    }

    $localePublicDir = $publicDir.'/'.$locale;

    if (! is_dir($localePublicDir)) {
        mkdir($localePublicDir, 0755, true);
    }

    ensureAssets($publicDir);

    foreach ($chapters as $i => $chapter) {
        $prev = $chapters[$i - 1] ?? null;
        $next = $chapters[$i + 1] ?? null;

        $navLinks = '<div class="chapter-nav-links">';
        $navLinks .= $prev !== null
            ? '<a href="'.$prev['number'].'-'.$prev['slug'].'.html">&larr; '.htmlspecialchars($prev['title'], ENT_QUOTES).'</a>'
            : '<span></span>';
        $navLinks .= $next !== null
            ? '<a href="'.$next['number'].'-'.$next['slug'].'.html">'.htmlspecialchars($next['title'], ENT_QUOTES).' &rarr;</a>'
            : '<span></span>';
        $navLinks .= '</div>';

        $page = pageShell($locale, $chapter['title'], $chapter['html'].$navLinks, $chapter['slug']);
        file_put_contents($localePublicDir.'/'.$chapter['number'].'-'.$chapter['slug'].'.html', $page);
        $generatedChapters++;
    }

    $listItems = implode('', array_map(
        static fn (array $c): string => '<li><a href="'.$c['number'].'-'.$c['slug'].'.html">'.$c['number'].'. '.htmlspecialchars($c['title'], ENT_QUOTES).'</a></li>',
        $chapters,
    ));
    $indexBody = '<h1>Návod na používanie Zoad</h1><ul class="chapter-list">'.$listItems.'</ul>';
    file_put_contents($localePublicDir.'/index.html', pageShell($locale, 'Návod', $indexBody));

    $searchIndex = array_map(
        static fn (array $c): array => [
            'slug' => $c['slug'],
            'number' => $c['number'],
            'title' => $c['title'],
            'text' => $c['text'],
        ],
        $chapters,
    );
    file_put_contents($localePublicDir.'/search-index.json', json_encode($searchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $generatedLocales++;
}

echo "Generated {$generatedChapters} chapter(s) across {$generatedLocales} locale(s) into public/manual/.\n";
