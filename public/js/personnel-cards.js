/* PERSONNEL CARDS — the View More details modal of the Super Admin, Admin
   and Principal personnel lists. Each card's View More button carries the
   person's details (data-personnel). The Super Admin's ⋮ menu items call
   personnelAction() in superadmin-personnel.js, which acts on
   currentPersonnel. */

let currentPersonnel = null;

const EMPLOYMENT_BADGE_CLASSES = {
    'Regular': 'employment-regular',
    'Part-Time': 'employment-part-time',
    'Contractual': 'employment-contractual'
};

function openPersonnelDetails(button) {
    const person = JSON.parse(button.dataset.personnel);
    currentPersonnel = person;

    const photo = document.getElementById('pdPhoto');
    photo.src = person.photo_url;
    photo.alt = 'Photo of ' + person.fullname;

    document.getElementById('pdName').textContent = person.fullname;

    const typeBadge = document.getElementById('pdTypeBadge');
    typeBadge.className = 'type-badge ' + (person.academic_status === 'Academic' ? 'type-academic' : 'type-non-academic');
    typeBadge.textContent = person.type_label;

    const employmentBadge = document.getElementById('pdEmploymentBadge');
    employmentBadge.className = 'employment-badge ' + (EMPLOYMENT_BADGE_CLASSES[person.employment_type] || 'employment-part-time');
    employmentBadge.textContent = person.employment_type;

    document.getElementById('pdNumber').textContent = person.number;
    document.getElementById('pdIdNumber').textContent = person.id_number;
    document.getElementById('pdFullname').textContent = person.fullname;
    document.getElementById('pdPosition').textContent = person.department;
    document.getElementById('pdType').textContent = person.type_label;
    document.getElementById('pdEmployment').textContent = person.employment_type;

    const schedule = document.getElementById('pdSchedule');
    schedule.replaceChildren();

    if (person.schedules.length) {
        const list = document.createElement('ul');
        person.schedules.forEach(line => {
            const item = document.createElement('li');
            item.textContent = line;
            list.appendChild(item);
        });
        schedule.appendChild(list);
    } else {
        schedule.textContent = 'No schedule assigned';
    }

    // Code 128, the same symbology as the printed ID badges the kiosk scans.
    JsBarcode('#pdBarcode', person.barcode, {
        format: 'CODE128',
        displayValue: true,
        fontSize: 13,
        height: 50,
        width: 2
    });

    closePersonnelMenu();
    document.getElementById('personnelDetailsModal').classList.add('active');
    lockBodyScroll();
}

function closePersonnelDetails() {
    const modal = document.getElementById('personnelDetailsModal');

    if (!modal.classList.contains('active')) {
        return;
    }

    closePersonnelMenu();
    modal.classList.remove('active');
    unlockBodyScroll();
}

/* ================= ⋮ ACTIONS MENU (Super Admin) ================= */

function isPersonnelMenuOpen() {
    const list = document.getElementById('pdMenuList');
    return Boolean(list) && !list.hidden;
}

function togglePersonnelMenu() {
    const list = document.getElementById('pdMenuList');
    const opening = list.hidden;

    list.hidden = !opening;
    document.getElementById('pdMenuToggle').setAttribute('aria-expanded', String(opening));

    if (opening) {
        list.querySelector('.pd-action').focus();
    }
}

function closePersonnelMenu() {
    if (!isPersonnelMenuOpen()) {
        return;
    }

    document.getElementById('pdMenuList').hidden = true;
    document.getElementById('pdMenuToggle').setAttribute('aria-expanded', 'false');
}

document.addEventListener('click', function (e) {
    if (isPersonnelMenuOpen() && !e.target.closest('.pd-menu')) {
        closePersonnelMenu();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') {
        return;
    }

    if (isPersonnelMenuOpen()) {
        closePersonnelMenu();
        document.getElementById('pdMenuToggle').focus();
    } else {
        closePersonnelDetails();
    }
});
