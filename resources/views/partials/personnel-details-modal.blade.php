{{-- View More: every detail of one personnel card (filled by
     personnel-cards.js). The ⋮ actions menu is the Super Admin's only
     ($canManage); Admin and Principal get the same details, read-only. --}}
<div class="pd-backdrop" id="personnelDetailsModal" onclick="if (event.target === this) closePersonnelDetails()">
    <div class="pd-dialog" role="dialog" aria-modal="true" aria-labelledby="pdName">

        <div class="pd-toolbar">
            @if ($canManage)
                <div class="pd-menu">
                    <button type="button" class="pd-icon-btn" id="pdMenuToggle" aria-label="Actions" aria-haspopup="menu" aria-expanded="false" aria-controls="pdMenuList" onclick="togglePersonnelMenu()">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>
                    <div class="pd-menu-list" id="pdMenuList" role="menu" hidden>
                        <button type="button" role="menuitem" class="pd-action pd-action-edit" onclick="personnelAction('edit')">Edit</button>
                        <button type="button" role="menuitem" class="pd-action pd-action-regenerate" onclick="personnelAction('regenerate')">Regenerate</button>
                        <button type="button" role="menuitem" class="pd-action pd-action-print" onclick="personnelAction('print')">Print</button>
                        <button type="button" role="menuitem" class="pd-action pd-action-archive" onclick="personnelAction('archive')">Archive</button>
                    </div>
                </div>
            @endif
            <button type="button" class="pd-icon-btn" aria-label="Close" onclick="closePersonnelDetails()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="pd-head">
            <img class="pd-photo" id="pdPhoto" src="" alt="">
            <div class="pd-head-text">
                <h3 class="pd-name" id="pdName"></h3>
                <div class="pd-badges">
                    <span class="type-badge" id="pdTypeBadge"></span>
                    <span class="employment-badge" id="pdEmploymentBadge"></span>
                </div>
            </div>
        </div>

        <dl class="pd-fields">
            <div><dt>No.</dt><dd id="pdNumber"></dd></div>
            <div><dt>ID Number</dt><dd id="pdIdNumber"></dd></div>
            <div><dt>Name</dt><dd id="pdFullname"></dd></div>
            <div><dt>Position</dt><dd id="pdPosition"></dd></div>
            <div><dt>Personnel Type</dt><dd id="pdType"></dd></div>
            <div><dt>Employment</dt><dd id="pdEmployment"></dd></div>
            <div class="pd-wide"><dt>Schedule</dt><dd id="pdSchedule"></dd></div>
            <div class="pd-wide"><dt>Barcode</dt><dd class="pd-barcode"><svg id="pdBarcode"></svg></dd></div>
        </dl>

    </div>
</div>
