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