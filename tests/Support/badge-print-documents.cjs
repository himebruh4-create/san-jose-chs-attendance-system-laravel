/*
 * Test helper (tests/Feature/BadgePrintTest.php): builds the real badge
 * print documents, badgePrintDocument() in public/js/superadmin-personnel.js,
 * one after another (one badge, then Print All, then Print All again) and
 * runs each document's script in ONE shared JavaScript context, the way the
 * reused print frame (printHTMLDocument() in app-shell.js) runs them.
 * Prints JSON:
 *   {"error": null, "drawn": [["#badge-barcode-0", "100001"], ...], "printed": 3}
 * or {"error": "Identifier 'badgeBarcodes' has already been declared", ...}.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const noop = () => {};
const none = () => null;

const page = vm.createContext({
    window: { PAGE: { allTeachers: [], badgePrints: {}, allBadgesPrint: { title: 'ID Badges' }, schedulePicker: [] } },
    document: { addEventListener: noop, querySelector: none, getElementById: none, querySelectorAll: () => [] },
    console,
    escapeHtml: value => String(value ?? ''),
    appUrl: p => '/' + p,
    schoolInfo: () => ({ name: 'San Jose Community High School', short_name: 'San Jose CHS', logo_url: '/img/logo.png' }),
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js/superadmin-personnel.js'), 'utf8'), page);

const person = n => ({ name: 'Person ' + n, idNumber: 'ID-' + n, department: 'Teacher I', barcode: String(100000 + n), photo: '' });
const documents = [
    page.badgePrintDocument('ID Badge', [person(1)], ''),
    page.badgePrintDocument('ID Badges', [person(1), person(2), person(3)], ''),
    page.badgePrintDocument('ID Badges', [person(1), person(2)], ''),
];

const result = { error: null, drawn: [], printed: 0 };
const frame = vm.createContext({
    JsBarcode: (selector, value) => result.drawn.push([selector, value]),
    document: { querySelectorAll: () => [] },
    print: () => { result.printed++; },
});
frame.window = frame;

try {
    for (const html of documents) {
        const scripts = [...html.matchAll(/<script>([\s\S]*?)<\\?\/script>/g)].map(match => match[1]);
        vm.runInContext(scripts.join('\n'), frame);
        frame.window.onload();
    }
} catch (error) {
    result.error = error.message;
}

process.stdout.write(JSON.stringify(result));
