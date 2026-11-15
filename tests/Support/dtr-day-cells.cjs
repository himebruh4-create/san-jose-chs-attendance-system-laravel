/*
 * Test helper (tests/Feature/ReportTimesTest.php): runs the real DTR
 * renderer, printDTRWithRemarks() in public/js/dtr-view.js, on the data the
 * DTR page receives and prints one day's time cells as JSON:
 *   {"layout": "day", "am_arrival": "07:05", "am_departure": null, "pm_arrival": null, "pm_departure": "15:15"}
 * or, for shift personnel (the DTR shows one CLOCK IN | CLOCK OUT pair):
 *   {"layout": "shift", "in": "21:55", "out": "06:10"}
 * Times are 24-hour HH:MM; "(Auto)" lunch cells and "MISSING OUT" are null.
 *
 * Input (stdin, JSON): { teacher, remarks, leaves, adjustments, events,
 * absences, schedule, lunch, year, month (0-based), day }.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));

const escapeHtml = value => String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const context = vm.createContext({
    window: {},
    document: { getElementById: () => null, querySelector: () => null },
    console,
    Intl,
    Date,
    escapeHtml,
    appUrl: p => '/' + p,
    showMessageModal: () => {},
    printHTMLDocument: () => {},
});

vm.runInContext(fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'dtr-view.js'), 'utf8'), context);

const html = vm.runInContext('printDTRWithRemarks', context)(
    input.teacher.id, input.remarks || [], input.leaves || [], input.adjustments || [], input.events || [],
    input.absences || [], input.schedule || {}, input.lunch || {}, input.teacher, input.year, input.month, true
);

// The day's row: <tr ...><td>DAY</td><td>AM in</td><td>AM out</td><td>PM in</td><td>PM out</td>...
const rows = html.split(/<tr\b/).slice(1);
const row = rows.find(r => new RegExp('^[^>]*>\\s*<td>\\s*' + input.day + '\\s*</td>').test(r));

if (!row) {
    process.stdout.write(JSON.stringify({ error: 'day row not found' }));
    process.exit(0);
}

const cells = [...row.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map(m => m[1]);

const toClock = cell => {
    if (/\(Auto\)/.test(cell)) return null;
    const m = cell.replace(/<[^>]+>/g, ' ').match(/(\d{1,2}):(\d{2})\s*(AM|PM)?/i);
    if (!m) return null;
    let h = parseInt(m[1], 10);
    if (m[3]) {
        const pm = m[3].toUpperCase() === 'PM';
        if (pm && h < 12) h += 12;
        if (!pm && h === 12) h = 0;
    }
    return String(h).padStart(2, '0') + ':' + m[2];
};

// Shift personnel (every scheduled day a shift): Day | CLOCK IN | CLOCK OUT | Hours | Minutes.
if (cells.length === 5) {
    process.stdout.write(JSON.stringify({ layout: 'shift', in: toClock(cells[1]), out: toClock(cells[2]) }));
    process.exit(0);
}

// Day | AM IN | AM OUT | PM IN | PM OUT | Hours | Minutes.
const columns = ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'];
const out = { layout: 'day' };
columns.forEach((c, i) => { out[c] = cells.length > 4 ? toClock(cells[i + 1]) : null; });

process.stdout.write(JSON.stringify(out));
