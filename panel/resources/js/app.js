// Chart widgets (monitoring, overview sparklines) draw with Chart.js, which
// wire-core does not ship on its own.
import Chart from 'chart.js/auto';

window.Chart = Chart;

// The file manager's editor (CodeMirror 6, Prettier on demand).
import './code-ide';

// The browser tab names the page ("Weby · WSPanel"), not just the panel: the
// pages set their heading, and the layout has no other way to learn it.
const brand = document.title || 'WSPanel';
const nameTab = () => {
    const heading = document.querySelector('main h1');
    const text = heading ? heading.textContent.replace(/\s+/g, ' ').trim() : '';
    document.title = text && text !== brand ? `${text} · ${brand}` : brand;
};
document.addEventListener('DOMContentLoaded', nameTab);
document.addEventListener('livewire:navigated', nameTab);
