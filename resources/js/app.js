import './bootstrap';

import Alpine from 'alpinejs';
// `chart.js/auto` registers every controller, scale, element and plugin —
// avoids "Cannot read properties of undefined (reading 'axis')" from a missing
// scale registration.
import Chart from 'chart.js/auto';

import dashboard from './dashboard';

window.Alpine = Alpine;
window.Chart = Chart;

// Every chart here is destroyed and rebuilt on each filter change and on each
// switch back into its view, so the entry animation replays constantly rather
// than once. Worse, it makes what gets captured depend on timing: printing the
// dashboard, or rendering it headlessly, catches the charts part-drawn or —
// when no animation frame runs at all — empty, with only the axes painted.
Chart.defaults.animation = false;

Alpine.data('dashboard', dashboard);
Alpine.start();
