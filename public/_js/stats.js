(() => {
    'use strict';

    const dataElement = document.getElementById('stats-data');
    if (!dataElement || typeof window.Chart === 'undefined') {
        return;
    }

    const stats = JSON.parse(dataElement.textContent);
    const formats = stats.formats || {};
    const labelFor = (format) => (formats[format] && formats[format].label) || format;

    const seasons = stats.seasons || {};
    const years = stats.years || [];
    const allSeasons = [...(seasons['6s'] || []), ...(seasons['9v9'] || [])].sort((a, b) => a.end_time - b.end_time);

    if (years.length === 0 && allSeasons.length === 0) {
        return;
    }

    // Palette alignée sur le thème du site (app.css).
    const COLORS = {
        '6s': '#6ea8ff',
        '9v9': '#f5c542',
        new: '#4ade80',
        neutral: '#8b93a1',
    };

    Chart.defaults.color = '#8b93a1';
    Chart.defaults.borderColor = '#262d37';
    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';

    // Une seule instance par canvas, recréée quand le filtre change : les
    // datasets dépendent du format, plus simple que de jongler avec les
    // métadonnées de visibilité Chart.js.
    const charts = {};

    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { boxWidth: 12 } },
            tooltip: {
                backgroundColor: '#161a20',
                borderColor: '#262d37',
                borderWidth: 1,
            },
        },
        scales: {
            x: { ticks: { maxRotation: 60, minRotation: 45, autoSkip: true, maxTicksLimit: 18 } },
            y: { beginAtZero: true },
        },
    };

    // Série chronologique du format : en mode « all », les saisons des deux
    // formats partagent l'axe des x fusionné (trié par date de fin), chaque
    // série ne portant des valeurs que sur ses propres saisons.
    const timelineFor = (filter) => filter === 'all' ? allSeasons : (seasons[filter] || []);

    const seasonDatasets = (filter, field, { type = 'bar', fill = false } = {}) => {
        const timeline = timelineFor(filter);

        return ['6s', '9v9']
            .filter((format) => filter === 'all' || filter === format)
            .map((format) => ({
                label: labelFor(format),
                data: timeline.map((entry) => entry.format === format ? entry[field] : null),
                borderColor: COLORS[format],
                backgroundColor: COLORS[format],
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.25,
                fill,
            }));
    };

    const buildYearsChart = () => {
        const canvas = document.getElementById('chart-years');
        if (!canvas) {
            return;
        }

        charts.years = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: years.map((entry) => entry.year),
                datasets: [
                    {
                        label: '6v6 league seasons',
                        data: years.map((entry) => entry.seasons_6s),
                        backgroundColor: COLORS['6s'],
                        stack: 'seasons',
                    },
                    {
                        label: '9v9 league seasons',
                        data: years.map((entry) => entry.seasons_9v9),
                        backgroundColor: COLORS['9v9'],
                        stack: 'seasons',
                    },
                    {
                        label: 'Cups & tournaments',
                        data: years.map((entry) => entry.other),
                        backgroundColor: COLORS.neutral,
                        stack: 'seasons',
                    },
                ],
            },
            options: baseOptions,
        });
    };

    const buildTeamsChart = (filter) => {
        const canvas = document.getElementById('chart-teams');
        if (!canvas) {
            return;
        }

        charts.teams = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: timelineFor(filter).map((entry) => entry.name),
                datasets: seasonDatasets(filter, 'teams'),
            },
            options: baseOptions,
        });
    };

    const buildPlayersChart = (filter) => {
        const canvas = document.getElementById('chart-players');
        if (!canvas) {
            return;
        }

        charts.players = new Chart(canvas, {
            type: 'line',
            data: {
                labels: timelineFor(filter).map((entry) => entry.name),
                datasets: seasonDatasets(filter, 'players', { fill: true }),
            },
            options: baseOptions,
        });
    };

    const buildRetentionChart = (filter) => {
        const canvas = document.getElementById('chart-retention');
        if (!canvas) {
            return;
        }

        const timeline = timelineFor(filter);

        const datasets = ['6s', '9v9']
            .filter((format) => filter === 'all' || filter === format)
            .flatMap((format) => ([
                {
                    label: `New (${labelFor(format)})`,
                    data: timeline.map((entry) => entry.format === format ? entry.new_players : null),
                    backgroundColor: COLORS.new,
                    stack: format,
                },
                {
                    label: `Returning (${labelFor(format)})`,
                    data: timeline.map((entry) => entry.format === format ? entry.returning_players : null),
                    backgroundColor: COLORS[format],
                    stack: format,
                },
            ]));

        charts.retention = new Chart(canvas, {
            type: 'bar',
            data: { labels: timeline.map((entry) => entry.name), datasets },
            options: {
                ...baseOptions,
                scales: {
                    ...baseOptions.scales,
                    x: { stacked: true, ticks: { maxRotation: 60, minRotation: 45, autoSkip: true, maxTicksLimit: 18 } },
                    y: { ...baseOptions.scales.y, stacked: true },
                },
            },
        });
    };

    const renderCharts = (filter) => {
        for (const key of Object.keys(charts)) {
            charts[key].destroy();
            delete charts[key];
        }

        buildYearsChart();
        buildTeamsChart(filter);
        buildPlayersChart(filter);
        buildRetentionChart(filter);
    };

    renderCharts('all');

    for (const button of document.querySelectorAll('.stats-filter')) {
        if (button.dataset.format === 'all') {
            button.classList.add('is-active');
        }

        button.addEventListener('click', () => {
            for (const other of document.querySelectorAll('.stats-filter')) {
                other.classList.toggle('is-active', other === button);
            }
            renderCharts(button.dataset.format);
        });
    }
})();
