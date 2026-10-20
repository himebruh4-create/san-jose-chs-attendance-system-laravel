/* LIVE FILTERS — filter forms that apply themselves (no Apply / Reset).

   <form method="GET" data-live-filter [data-live-target="#results, #summary"]>

   - A dropdown applies as soon as it changes; a date / week / month
     picker shortly after (typing a year digit by digit is not applied
     half-way).
   - A text search applies 300 ms after typing stops (Enter: at once) and
     gets a × that clears it.
   - The filters stay in the URL (empty ones are left out), so reloading,
     printing and pagination keep them.

   With data-live-target only those parts of the page are replaced, so the
   search box keeps its focus while typing; without it the page reloads.
   After a replacement "live-filter:updated" is dispatched on document. */

(function () {
    const SEARCH_DELAY_MS = 300;
    const PICKER_DELAY_MS = 450;

    let inFlight = null;

    function filterUrl(form) {
        const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        url.search = '';

        new FormData(form).forEach((value, key) => {
            if (String(value).trim() !== '') {
                url.searchParams.append(key, value);
            }
        });

        return url;
    }

    async function applyFilters(form) {
        const url = filterUrl(form);
        const targets = (form.dataset.liveTarget || '').split(',').map(s => s.trim()).filter(Boolean);

        if (targets.length === 0) {
            window.location.assign(url);
            return;
        }

        if (inFlight) {
            inFlight.abort();
        }
        inFlight = new AbortController();
        form.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { signal: inFlight.signal });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');

            targets.forEach(selector => {
                const current = document.querySelector(selector);
                const fresh = page.querySelector(selector);
                if (current && fresh) {
                    current.innerHTML = fresh.innerHTML;
                }
            });

            window.history.replaceState(null, '', url);
            document.dispatchEvent(new CustomEvent('live-filter:updated', { detail: { form } }));
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Could not update in place: load the filtered page normally.
                window.location.assign(url);
            }
        } finally {
            form.removeAttribute('aria-busy');
        }
    }

    /** A date picker being typed into reports year 0002, 0020, 0202... first. */
    function isCompleteDate(input) {
        const year = parseInt(String(input.value).slice(0, 4), 10);
        return input.value === '' || year >= 2000;
    }

    function addClearButton(input, onClear) {
        const field = document.createElement('span');
        field.className = 'search-field';
        input.parentNode.insertBefore(field, input);
        field.appendChild(input);

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'search-clear';
        clear.setAttribute('aria-label', 'Clear search');
        clear.innerHTML = '&times;';
        field.appendChild(clear);

        const sync = () => { clear.hidden = input.value === ''; };
        sync();

        input.addEventListener('input', sync);
        clear.addEventListener('click', function () {
            input.value = '';
            sync();
            input.focus();
            onClear();
        });
    }

    document.querySelectorAll('form[data-live-filter]').forEach(form => {
        let timer = null;

        const applySoon = delay => {
            clearTimeout(timer);
            timer = setTimeout(() => applyFilters(form), delay);
        };

        const applyNow = () => {
            clearTimeout(timer);
            applyFilters(form);
        };

        form.querySelectorAll('input[type="text"], input[type="search"]').forEach(input => {
            addClearButton(input, applyNow);
            input.addEventListener('input', () => applySoon(SEARCH_DELAY_MS));
        });

        form.addEventListener('change', function (e) {
            const field = e.target;

            if (field.matches('select')) {
                applyNow();
            } else if (field.matches('input[type="date"], input[type="week"], input[type="month"]') && isCompleteDate(field)) {
                applySoon(PICKER_DELAY_MS);
            }
        });

        // Enter in the search box (or a <noscript> button) applies at once.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            applyNow();
        });
    });
})();
