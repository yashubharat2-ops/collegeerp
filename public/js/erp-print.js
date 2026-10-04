/* =============================================================================
 * College ERP — Print Helper for Printable Documents
 * =============================================================================
 *
 * Two progressive enhancements, both built on the browser's OWN print dialog
 * (there is no PDF library in this project, and none is needed — "Save as PDF"
 * is a destination of the native dialog):
 *
 *  - [data-print-now]      : a button that opens the print dialog on click
 *                            (the "Print / save as PDF" button of a report, and
 *                            the Print certificate / receipt buttons keep working
 *                            through their own inline-free markup).
 *  - [data-auto-print="1"] : the page opens the dialog itself once everything has
 *                            loaded — this is what the Students list's
 *                            "Export → Print" option lands on. The flag is set
 *                            server-side, so the PDF variant of the same page
 *                            (data-auto-print="0") never pops a dialog unasked.
 *
 * Waiting for `load` matters: printing before images and fonts are painted can
 * capture a half-rendered page.
 */
(function () {
    'use strict';

    function openPrintDialog() {
        window.print();
    }

    function initPrint() {
        var autoPrint = document.querySelector('[data-auto-print="1"]');
        if (autoPrint) {
            if (document.readyState === 'complete') {
                openPrintDialog();
            } else {
                window.addEventListener('load', openPrintDialog);
            }
        }

        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-print-now]'));
        buttons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openPrintDialog();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrint);
    } else {
        initPrint();
    }
})();
