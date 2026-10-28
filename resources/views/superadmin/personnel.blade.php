@extends('layouts.app')

@section('title', 'Personnel Management - Super Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-personnel.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/personnel-cards.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/schedule-changes.css') }}">
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
@endpush

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">{{ auth()->user()->roleLabel() }}</div>
        <h1>Personnel Management</h1>
        <p>Manage personnel records, schedules, leaves, and school-wide events.</p>
    </div>

    <!-- FILTER / SEARCH -->
    <div class="card">

        <h2><i class="fa-solid fa-magnifying-glass"></i> Search &amp; Filter Personnel</h2>
        <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Search by name or ID, or filter by personnel type and employment.</span></p>

        @include('partials.personnel-filter', ['route' => 'superadmin.personnel', 'canManage' => true])

    </div>

    <!-- PERSONNEL LIST -->
    <div class="card">

        <div class="list-header">
            <div>
                <h2><i class="fa-solid fa-users"></i> Personnel List</h2>
                <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Select View More for a person's schedule, barcode and actions.</span></p>
            </div>
            <button type="button" class="btn btn-primary" onclick="openAddPersonnelModal()">
                <i class="fa-solid fa-user-plus"></i> Add Personnel
            </button>
        </div>

        @if ($errors->any())
            <div class="error-box">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @include('partials.personnel-list', ['route' => 'superadmin.personnel', 'canManage' => true])

    </div>

    <!-- PERSONNEL ON LEAVE + SCHOOL SCHEDULE CHANGES -->
    <div class="dashboard-two-column">

        <!-- TEACHERS ON LEAVE -->
        <div class="card">

            <div class="section-header">
                <div>
                    <h2><i class="fa-solid fa-person-walking-arrow-right"></i> Personnel on Leave</h2>
                    <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Personnel who are currently on leave.</span></p>
                </div>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-gold btn-sm" onclick="openLeaveModal()">
                        <i class="fa-solid fa-plus"></i> Add Leave
                    </button>
                    <div class="count-badge">{{ count($teachersOnLeave) }}</div>
                </div>
            </div>

            @if (count($teachersOnLeave) > 0)
                <div class="mini-table-wrapper sticky-table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Personnel</th>
                                <th>Position</th>
                                <th>From</th>
                                <th>Until</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($teachersOnLeave as $leave)
                                <tr>
                                    <td>
                                        <strong>{{ $leave->fullname }}</strong><br>
                                        <small style="color:#8a7d5c;">{{ $leave->id_number }}</small>
                                    </td>
                                    <td>{{ $leave->department }}</td>
                                    <td>{{ date('M d, Y', strtotime($leave->leave_from)) }}</td>
                                    <td>{{ date('M d, Y', strtotime($leave->leave_until)) }}</td>
                                    <td><span class="pill pill-leave pill-leave-detail">On Leave @if ($leave->leave_type !== '')&middot; {{ $leave->leave_type }}@endif</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-danger btn-sm" aria-label="Archive" title="Archive"
                                                onclick="openDeleteModal({{ (int) $leave->teacher_id }}, {{ Js::from($leave->fullname) }})">
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i> <span class="btn-label">Archive</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fa-solid fa-check"></i></div>
                    <strong>No Personnel Currently on Leave</strong>
                    <p>All personnel are currently available.</p>
                </div>
            @endif

        </div>

        <!-- SCHOOL SCHEDULE CHANGES (holidays, asynchronous classes, other changes) -->
        <div class="card">

            <div class="section-header">
                <div>
                    <h2><i class="fa-solid fa-flag"></i> School Schedule Changes</h2>
                    <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Holidays, asynchronous classes and other changes that apply to all personnel.</span></p>
                </div>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-gold btn-sm" onclick="openHolidayModal()">
                        <i class="fa-solid fa-plus"></i> Add Schedule Change
                    </button>
                    <div class="count-badge" id="holidayCount">0</div>
                </div>
            </div>

            <div class="mini-table-wrapper sticky-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Included in Total Hours</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="schoolHolidayTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center;">Loading schedule changes...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>

    </div>

    @include('partials.personnel-details-modal', ['canManage' => true])

    <!-- ADD PERSONNEL MODAL -->
    <div class="modal-backdrop" id="addPersonnelModal" @if (session('failed_form') === 'add') data-reopen="1" @endif>
        <div class="modal add-personnel-modal">

            <button class="modal-close" type="button" onclick="closeAddPersonnelModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Add Personnel</h3>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Fill in the personnel's details, schedule and photo.</span></p>

            @if ($errors->any())
                <div class="error-box">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('superadmin.personnel.save') }}" enctype="multipart/form-data">
                @csrf
                @include('superadmin.partials.return-fields')

                <input type="hidden" name="id" value="">

                <div class="field-group">
                    <label>Teacher ID</label>
                    <input type="text" name="id_number" id="generatedId" class="generated-field" readonly placeholder="Auto-generated on save">
                </div>

                <div class="field-group">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="Name" data-capitalize="words" required maxlength="150">
                </div>

                <div class="field-group">
                    <label>Personnel Type</label>
                    <select name="academic_status" id="addAcademicStatusSelect" required onchange="handleAcademicStatusChange('add')">
                        <option value="">Select Personnel Type</option>
                        <option value="Academic">Teaching Staff</option>
                        <option value="Non-Academic">Non-Teaching Staff / School Administrator</option>
                    </select>
                </div>

                <div class="field-group">
                    <label id="addTeacherDeptLabel">Position</label>
                    <select name="department" id="addTeacherDeptSelect" required>
                        <option value="">Select Personnel Type first</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>Employment Type</label>
                    <select name="employment_type" required>
                        <option value="">Select Employment Type</option>
                        <option value="Regular">Regular</option>
                        <option value="Part-Time">Part-Time</option>
                        <option value="Contractual">Contractual</option>
                    </select>
                </div>

                <h2 style="margin-top:22px; font-size:15px;"><i class="fa-solid fa-calendar-days"></i> Personnel Schedule</h2>
                <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Tick the working days and set each day's Time In and Time Out.</span></p>

                <div class="schedule-grid">
                    @foreach ($scheduleDays as $day)
                        @php $dayKey = strtolower($day); @endphp
                        <div class="schedule-row">

                            <label class="day-check">
                                <input type="checkbox" name="schedule[{{ $dayKey }}][enabled]" value="1" checked onchange="toggleSchedule('{{ $dayKey }}')">
                                {{ $day }}
                            </label>

                            <div class="schedule-times">
                                <label>Time In
                                    <input type="time" name="schedule[{{ $dayKey }}][time_in]" id="add_{{ $dayKey }}_in" value="07:00">
                                </label>
                                <label>Time Out
                                    <input type="time" name="schedule[{{ $dayKey }}][time_out]" id="add_{{ $dayKey }}_out" value="15:00">
                                </label>
                            </div>

                            <div class="paid-break-note" id="add_{{ $dayKey }}_break" style="grid-column:1 / -1; font-size:11.5px; color:#7a6a3a; min-height:14px;"></div>

                        </div>
                    @endforeach
                </div>

                <div class="field-group" style="margin-top:20px;">
                    <label for="addPhotoInput">Photo (max 3MB)</label>
                    <div class="photo-upload-box">
                        <div class="image-preview" id="addPhotoPreview" style="display:none;">
                            <img src="" alt="Preview">
                        </div>
                        <div class="upload-fields">
                            <input type="file" name="photo" id="addPhotoInput" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                </div>

                <div class="field-group">
                    <label>Barcode</label>
                    <input type="text" name="barcode" id="generatedBarcode" class="generated-field" readonly placeholder="Will be generated automatically">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px; height:48px; font-size:14.5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Add Personnel
                </button>

            </form>

        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal-backdrop" id="editModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Edit Personnel</h3>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Update this personnel's details, schedule or photo.</span></p>

            <form method="POST" action="{{ route('superadmin.personnel.save') }}" enctype="multipart/form-data">
                @csrf
                @include('superadmin.partials.return-fields')

                <input type="hidden" name="id" id="modalId">

                <div class="field-group">
                    <label>ID Number</label>
                    <input type="text" name="id_number" id="modalTeacherIdNumber" placeholder="ID Number" required maxlength="50">
                </div>

                <div class="field-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="modalTeacherName" placeholder="Name" data-capitalize="words" required maxlength="150">
                </div>

                <div class="field-group">
                    <label>Personnel Type</label>
                    <select name="academic_status" id="modalAcademicStatus" required onchange="handleAcademicStatusChange('edit')">
                        <option value="">Select Personnel Type</option>
                        <option value="Academic">Teaching Staff</option>
                        <option value="Non-Academic">Non-Teaching Staff / School Administrator</option>
                    </select>
                </div>

                <div class="field-group">
                    <label id="modalTeacherDeptLabel">Position</label>
                    <select name="department" id="modalTeacherDeptSelect" required>
                        <option value="">Select Personnel Type first</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>Employment Type</label>
                    <select name="employment_type" id="modalEmploymentType" required>
                        <option value="">Select Employment Type</option>
                        <option value="Regular">Regular</option>
                        <option value="Part-Time">Part-Time</option>
                        <option value="Contractual">Contractual</option>
                    </select>
                </div>

                <h3 style="font-size:14px; margin:16px 0 8px;">Personnel Schedule</h3>

                <div class="schedule-grid">
                    @foreach ($scheduleDays as $day)
                        @php $dayKey = strtolower($day); @endphp
                        <div class="schedule-row">

                            <label class="day-check">
                                <input type="checkbox" name="schedule[{{ $dayKey }}][enabled]" value="1" id="edit_{{ $dayKey }}_enabled" onchange="toggleEditSchedule('{{ $dayKey }}')">
                                {{ $day }}
                            </label>

                            <div class="schedule-times">
                                <label>Time In
                                    <input type="time" name="schedule[{{ $dayKey }}][time_in]" id="edit_{{ $dayKey }}_in">
                                </label>
                                <label>Time Out
                                    <input type="time" name="schedule[{{ $dayKey }}][time_out]" id="edit_{{ $dayKey }}_out">
                                </label>
                            </div>

                            <div class="paid-break-note" id="edit_{{ $dayKey }}_break" style="grid-column:1 / -1; font-size:11.5px; color:#7a6a3a; min-height:14px;"></div>

                        </div>
                    @endforeach
                </div>

                <div class="field-group" style="margin-top:18px;">
                    <label>Photo</label>
                    <div class="photo-upload-box" id="modalPhotoPreviewContainer">
                        <img id="modalPhotoPreview" src="" alt="Personnel Photo"
                             style="display:none; width:66px; height:66px; object-fit:cover; border-radius:10px; border:2px solid #e8a317;">
                        <p id="modalPhotoText" style="margin:0; color:#8a7d5c; font-size:13px;">No photo uploaded</p>
                        <div class="upload-fields">
                            <input type="file" name="photo" id="modalPhotoInput" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                </div>

                <div class="field-group">
                    <label>Barcode</label>
                    <input type="text" name="barcode" id="modalBarcode" placeholder="Barcode" required maxlength="100">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>

            </form>

        </div>
    </div>

    <!-- DELETE MODAL -->
    <div class="modal-backdrop" id="deleteModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Archive Personnel</h3>
            <p>Are you sure you want to archive <strong id="deleteTeacherName" style="color:#14390f;"></strong>?</p>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>It moves to the Recycle Bin. You can restore it later.</span></p>

            <form method="POST" action="{{ route('superadmin.personnel.archive') }}">
                @csrf
                @include('superadmin.partials.return-fields')
                <input type="hidden" name="id" id="deleteTeacherId">

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Archive</button>
                </div>
            </form>

        </div>
    </div>

    <!-- REGENERATE BARCODE MODAL -->
    <div class="modal-backdrop" id="regenerateBarcodeModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeRegenerateModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Regenerate Barcode</h3>
            <p>
                Are you sure you want to regenerate the barcode for
                <strong id="regenerateTeacherName" style="color:#14390f;"></strong>?
                Their current barcode will stop working and their printed badge will need to be reprinted.
            </p>

            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeRegenerateModal()">Cancel</button>
                <button type="button" class="btn btn-gold" onclick="confirmRegenerateBarcode()">
                    <i class="fa-solid fa-barcode"></i> Regenerate
                </button>
            </div>

        </div>
    </div>

    <!-- ADD LEAVE MODAL -->
    <div class="modal-backdrop" id="leaveModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeLeaveModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Add Personnel Leave</h3>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Record an approved leave period for a personnel.</span></p>

            <form method="POST" action="{{ route('superadmin.personnel.leave') }}" onsubmit="disableLeaveSubmit(this)">
                @csrf
                @include('superadmin.partials.return-fields')

                <div class="field-group">
                    <label>Personnel</label>
                    <select name="leave_teacher_id" id="leaveTeacherSelect" required>
                        <option value="">Select Personnel</option>
                        @foreach ($allTeachers as $option)
                            <option value="{{ (int) $option['id'] }}" data-personnel-type="{{ $option['academic_status'] }}">{{ $option['fullname'] }} - {{ $option['id_number'] }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Filled in for the selected personnel's type (App\Domain\LeaveTypes). --}}
                <div class="field-group">
                    <label>Leave Type</label>
                    <select name="leave_type" id="leaveTypeSelect" data-leave-types="{{ json_encode(\App\Domain\LeaveTypes::options()) }}" required disabled>
                        <option value="">Select Leave Type</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>Leave From</label>
                    <input type="date" name="leave_from" required>
                </div>

                <div class="field-group">
                    <label>Leave Until</label>
                    <input type="date" name="leave_until" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeLeaveModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Leave</button>
                </div>
            </form>

        </div>
    </div>

    <!-- ADD SCHOOL EVENT MODAL -->
    <div class="modal-backdrop" id="holidayModal">
        <div class="holiday-modal">

            <button type="button" class="modal-close" onclick="closeHolidayModal()"><i class="fa-solid fa-xmark"></i></button>

            <h2 id="holidayModalTitle">Add School Schedule Change</h2>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Applies automatically to every personnel's DTR.</span></p>

            <input type="hidden" id="holidayEditId" value="">

            <div class="field-group">
                <label>Name</label>
                <input type="text" id="holidayNameInput" placeholder="e.g. National Heroes Day, Summer Break" data-capitalize="words" maxlength="255">
            </div>

            <div class="field-group">
                <label>Type</label>
                <select id="holidayTypeInput">
                    <option value="Holiday">Holiday</option>
                    <option value="Asynchronous">Asynchronous Class</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="field-group">
                <label>Date From</label>
                <input type="date" id="holidayDateFromInput">
            </div>

            <div class="field-group">
                <label>Date To</label>
                <input type="date" id="holidayDateToInput">
            </div>

            <div class="field-group">
                <label>Duration</label>
                <select id="holidayDurationInput" onchange="toggleHolidayHalfDayFields()">
                    <option value="Whole Day">Whole Day</option>
                    <option value="Half Day">Half Day</option>
                </select>
            </div>

            <div id="holidayHalfDayFields" style="display:none;">
                <div class="field-group">
                    <label>Start Time</label>
                    <input type="time" id="holidayStartTimeInput">
                </div>
                <div class="field-group">
                    <label>End Time</label>
                    <input type="time" id="holidayEndTimeInput">
                </div>
            </div>

            <div class="field-group">
                <label>Included in Total Hours</label>
                <select id="holidayIncludedInput">
                    <option value="1">Yes</option>
                    <option value="0" selected>No</option>
                </select>
            </div>

            <div class="field-group">
                <label>Personnel Scheduled to Work</label>
                <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Optional. Selected personnel still work that day, on their own schedule.</span></p>

                <div class="personnel-picker">
                    <input type="text" id="holidayPersonnelSearch" placeholder="Search personnel by name, ID or position" oninput="renderHolidayPersonnelList()" autocomplete="off">

                    <div class="personnel-picker-tools">
                        <button type="button" class="btn btn-outline btn-sm" onclick="selectAllHolidayPersonnel()">Select All</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="clearHolidayPersonnel()">Clear</button>
                        <span id="holidayPersonnelCount" class="picker-count">0 selected</span>
                    </div>

                    <div id="holidayPersonnelList" class="personnel-picker-list"></div>
                </div>
            </div>

            <div class="field-group">
                <label>Status</label>
                <select id="holidayStatusInput">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeHolidayModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSchoolHoliday()">Save Schedule Change</button>
            </div>

        </div>
    </div>

    <!-- DEACTIVATE SCHOOL EVENT MODAL -->
    <div class="modal-backdrop" id="deactivateEventModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeDeactivateEventModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Deactivate School Schedule Change</h3>
            <p>Are you sure you want to deactivate <strong id="deactivateEventName" style="color:#14390f;"></strong>?</p>
            <p class="help-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>It stops applying to DTRs and moves to the Recycle Bin. You can restore it later.</span></p>

            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeDeactivateEventModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDeactivateSchoolEvent()">Deactivate</button>
            </div>

        </div>
    </div>

    <!-- APPLICATION ERROR MODAL -->
    <div class="modal-backdrop" id="appErrorModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeErrorModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Something Went Wrong</h3>
            <p id="appErrorModalMessage" style="color:#8a7d5c; font-size:14px;"></p>

            <div class="modal-actions">
                <button type="button" class="btn btn-primary" onclick="closeErrorModal()">OK</button>
            </div>

        </div>
    </div>

</div>

<div id="toast" class="toast"></div>
@endsection

@push('scripts')
@php
    $schedulePicker = array_map(fn ($t) => [
        'id' => (int) $t['id'],
        'id_number' => (string) $t['id_number'],
        'fullname' => (string) $t['fullname'],
        'department' => (string) $t['department'],
    ], $allTeachers);

    $pageData = [
        'paidBreakConfig' => $breakConfig,
        'allTeachers' => $allTeachers,
        'schedulePicker' => $schedulePicker,
        'badgePrints' => $badgePrints,
        'allBadgesPrint' => $allBadgesPrint,
    ];
@endphp
<script>
window.PAGE = @json($pageData);
</script>
<script src="{{ asset_v('js/personnel-cards.js') }}"></script>
<script src="{{ asset_v('js/live-filters.js') }}"></script>
<script src="{{ asset_v('js/superadmin-personnel.js') }}"></script>
@if (session('message'))
@php $flash = ['message' => session('message'), 'type' => session('message_type', 'success')]; @endphp
<script>
document.addEventListener("DOMContentLoaded", function () {
    const flash = @json($flash);
    showToast(flash.message, flash.type);
});
</script>
@endif
@endpush
