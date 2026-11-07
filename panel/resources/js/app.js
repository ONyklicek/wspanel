// Chart widgets (monitoring, overview sparklines) draw with Chart.js, which
// wire-core does not ship on its own.
import Chart from 'chart.js/auto';

window.Chart = Chart;

// The file manager's editor (CodeMirror 6, Prettier on demand).
import './code-ide';
