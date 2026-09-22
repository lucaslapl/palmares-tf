(() => {
    'use strict';

    const form = document.querySelector('form[data-autocomplete]');
    if (!form) {
        return;
    }

    const input = form.querySelector('[data-autocomplete-input]');
    const results = form.querySelector('[data-autocomplete-results]');

    let timer = null;
    let currentQuery = '';

    const url = '/api/search';

    const render = (players) => {
        results.textContent = '';

        for (const player of players) {
            const link = document.createElement('a');
            link.href = player.url;
            link.textContent = player.name;

            if (player.flag) {
                const flag = document.createElement('img');
                flag.className = 'flag';
                flag.src = player.flag;
                flag.alt = player.country;
                flag.title = player.country;
                flag.loading = 'lazy';
                flag.onerror = () => flag.replaceWith(document.createTextNode(player.country));
                link.appendChild(flag);
            }

            results.appendChild(link);
        }

        results.hidden = players.length === 0;
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();

        window.clearTimeout(timer);
        if (q.length < 3) {
            results.hidden = true;
            return;
        }

        currentQuery = q;
        timer = window.setTimeout(() => {
            fetch(url + '?q=' + encodeURIComponent(currentQuery))
                .then((r) => r.json())
                .then((data) => render(data.players || []))
                .catch(() => results.hidden = true);
        }, 180);
    });

    document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) {
            results.hidden = true;
        }
    });
})();