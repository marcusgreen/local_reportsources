/**
 * PNG download for rendered Chart.js canvas.
 *
 * @module     local_reportsources/chart_download
 * @copyright  2026 Marcus Green
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    return {
        init: function(filename) {
            var btn = document.getElementById('local-reportsources-download-png');
            if (!btn) {
                return;
            }
            btn.addEventListener('click', function() {
                var canvas = document.querySelector('.chart-output canvas, canvas');
                if (!canvas) {
                    return;
                }
                var link = document.createElement('a');
                link.download = filename + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });
        }
    };
});
