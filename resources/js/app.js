import './bootstrap';

import Alpine from 'alpinejs';
// `chart.js/auto` registers every controller, scale, element and plugin —
// avoids "Cannot read properties of undefined (reading 'axis')" from a missing
// scale registration.
import Chart from 'chart.js/auto';

import dashboard from './dashboard';

window.Alpine = Alpine;
window.Chart = Chart;

Alpine.data('dashboard', dashboard);
Alpine.start();
