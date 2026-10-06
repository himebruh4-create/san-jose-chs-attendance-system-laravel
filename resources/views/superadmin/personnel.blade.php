@extends('layouts.app')

@section('title', 'Personnel Management - Super Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-personnel.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/schedule-changes.css') }}">
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
@endpush

@php
    $pageQuery = array_filter([
        'search' => $search,
        'academic_status' => $filterAcademic,
        'employment_type' => $filterEmployment,
    ], fn ($v) => $v !== '');

    $photoUrl = fn ($photo) => $photo ? route('photos.personnel', basename($photo)) : asset('img/logo.png');
@endphp

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">Admin Aide</div>
        <h1>Personnel Management</h1>
        <p>Manage personnel records, schedules, leaves, and school-wide events.</p>
    </div>

    <!-- FILTER / SEARCH -->
    <div class="card">

        <h2><i class="fa-solid fa-magnifying-glass"></i> Search &amp; Filter Personnel</h2>
        <p class="card-subtitle">Find a personnel by name/ID, or narrow the list by status.</p>

        <form method="GET" action="{{ route('superadmin.personnel') }}" class="filter-row">

            <div class="field-group" style="flex:2 1 260px;">
                <input type="text" name="search" placeholder="Search by Name or ID No." value="{{ $search }}">
            </div>

            <div class="field-group">
                <select name="academic_status">
                    <option value="">All Personnel Types</option>
                    <option value="Academic" @selected($filterAcademic === 'Academic')>Teaching Staff</option>
                    <option value="Non-Academic" @selected($filterAcademic === 'Non-Academic')>Non-Teaching Staff / School Administrator</option>
                </select>
            </div>

            <div class="field-group">
                <select name="employment_type">
                    <option value="">All Employment Type</option>
                    <option value="Regular" @selected($filterEmployment === 'Regular')>Regular</option>
                    <option value="Part-Time" @selected($filterEmployment === 'Part-Time')>Part-Time</option>
                    <option value="Contractual" @selected($filterEmployment === 'Contractual')>Contractual</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Apply
                </button>
                <button type="button" class="btn btn-outline" onclick="window.location={{ Js::from(route('superadmin.personnel')) }}">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
                <button type="button" class="btn btn-gold" onclick="printAllTeacherBadges()">
                    <i class="fa-solid fa-id-card"></i> Print All ID Badges
                </button>
            </div>

        </form>

    </div>

    <!-- TEACHER LIST -->
    <div class="card">

        <h2><i class="fa-solid fa-users"></i> Personnel List</h2>
        <p class="card-subtitle">All registered Personnel, schedules, and badge information.</p>

        <div class="table-scroll sticky-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>ID Number</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Personnel Type</th>
                        <th>Employment</th>
                        <th>Schedule</th>
                        <th>Barcode</th>
                        <th>Photo</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                @forelse ($teachers as $teacher)
                    @php
                        $employmentPillClass = match (strtolower((string) $teacher['employment_type'])) {
                            'regular' => 'pill-fulltime',
                            'contractual' => 'pill-contractual',
                            default => 'pill-parttime',
                        };
                    @endphp
                    <tr>
                        <td>{{ ($page - 1) * $limit + $loop->iteration }}</td>
                        <td>{{ $teacher['id_number'] }}</td>
                        <td><strong>{{ $teacher['fullname'] }}</strong></td>
                        <td>{{ $teacher['department'] }}</td>

                        <td>
                            <span class="pill {{ strtolower((string) $teacher['academic_status']) === 'academic' ? 'pill-academic' : 'pill-non-academic' }}">
                                {{ $teacher['academic_status'] === 'Academic' ? 'Teaching Staff' : 'Non-Teaching Staff / School Administrator' }}
                            </span>
                        </td>

                        <td>
                            <span class="pill {{ $employmentPillClass }}">{{ $teacher['employment_type'] }}</span>
                        </td>

                        <td>
                            @forelse ($teacher['schedules'] as $schedule)
                                @php $paidBreak = \App\Domain\Attendance\dtrBreakForSchedule($breakConfig, $schedule); @endphp
                                <div style="margin-bottom:5px; font-size:12.5px;">
                                    <strong>{{ $schedule['day'] }}:</strong>
                                    {{ date('g:i A', strtotime($schedule['time_in'])) }} -
                                    {{ date('g:i A', strtotime($schedule['time_out'])) }}
                                    @if ($paidBreak)
                                        <span style="color:#7a6a3a; font-size:11.5px;">(paid break {{ date('g:i A', strtotime($paidBreak['start'])) }} - {{ date('g:i A', strtotime($paidBreak['end'])) }})</span>
                                    @endif
                                </div>
                            @empty
                                <span style="color:#a89a6e; font-size:12.5px;">No schedule assigned</span>
                            @endforelse
                        </td>

                        <td>
                            {{ $teacher['barcode'] }}
                            <br>
                            <svg id="barcode-{{ (int) $teacher['id'] }}" class="barcode-svg" data-barcode="{{ $teacher['barcode'] }}"></svg>
                        </td>

                        <td>
                            <img src="{{ $photoUrl($teacher['photo']) }}" alt="Personnel Photo"
                                 style="width:56px; height:56px; object-fit:cover; border-radius:9px; border:1px solid #e6dcc4;">
                        </td>

                        <td>
                            <div class="action-group">

                                <button type="button" class="btn btn-outline btn-sm edit-button" data-id="{{ (int) $teacher['id'] }}">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>

                                <!-- Submitted via JS after the confirmation modal -->
                                <form method="POST" action="{{ route('superadmin.personnel.regenerate-barcode') }}" style="display:none;" id="regenerateForm-{{ (int) $teacher['id'] }}">
                                    @csrf
                                    @include('superadmin.partials.return-fields')
                                    <input type="hidden" name="id" value="{{ (int) $teacher['id'] }}">
                                </form>

                                <button type="button" class="btn btn-gold btn-sm"
                                    onclick="openRegenerateModal({{ (int) $teacher['id'] }}, {{ Js::from($teacher['fullname']) }})">
                                    <i class="fa-solid fa-barcode"></i> Regenerate
                                </button>

                                <button type="button" class="btn btn-danger btn-sm"
                                    onclick="openDeleteModal({{ (int) $teacher['id'] }}, {{ Js::from($teacher['fullname']) }})">
                                    <i class="fa-solid fa-trash"></i> Archive
                                </button>

                                <button type="button" class="btn btn-primary btn-sm"
                                    data-idnumber="{{ $teacher['id_number'] }}"
                                    data-name="{{ $teacher['fullname'] }}"
                                    data-department="{{ $teacher['department'] }}"
                                    data-academic="{{ $teacher['academic_status'] }}"
                                    data-employment="{{ $teacher['employment_type'] }}"
                                    data-barcode="{{ $teacher['barcode'] }}"
                                    data-photo="{{ $teacher['photo'] ?? '' }}"
                                    onclick="printTeacherBadge(this)">
                                    <i class="fa-solid fa-print"></i> Print
                                </button>

                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" style="text-align:center; padding:30px;">No teachers found yet.</td>
                    </tr>
                @endforelse

                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        @if ($totalPages > 1)
            @php $pageUrl = fn ($p) => route('superadmin.personnel', $pageQuery + ['page' => $p]); @endphp
            <div class="pagination">
                @if ($page > 1)
                    <a href="{{ $pageUrl(1) }}">&laquo; First</a>
                    <a href="{{ $pageUrl($page - 1) }}">&lsaquo; Previous</a>
                @endif

                @for ($i = 1; $i <= $totalPages; $i++)
                    @if ($i == $page)
                        <span class="active">{{ $i }}</span>
                    @else
                        <a href="{{ $pageUrl($i) }}">{{ $i }}</a>
                    @endif
                @endfor

                @if ($page < $totalPages)
                    <a href="{{ $pageUrl($page + 1) }}">Next &rsaquo;</a>
                    <a href="{{ $pageUrl($totalPages) }}">Last &raquo;</a>
                @endif
            </div>
        @endif

    </div>


    <!-- ADD TEACHER + TEACHERS ON LEAVE / SCHOOL EVENTS -->
    <div class="dashboard-two-column">

        <!-- ADD TEACHER -->
        <div class="card">

            <h2><i class="fa-solid fa-user-plus"></i> Add Personnel</h2>
            <p class="card-subtitle">Fill in the teacher's details, schedule, and photo.</p>

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
                <p class="card-subtitle" style="margin-bottom:10px;">Select working days and assign time-in / time-out.</p>

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

        <!-- RIGHT COLUMN: TEACHERS ON LEAVE + SCHOOL EVENTS -->
        <div class="right-column">

            <!-- TEACHERS ON LEAVE -->
            <div class="card">

                <div class="section-header">
                    <div>
                        <h2><i class="fa-solid fa-person-walking-arrow-right"></i> Personnel on Leave</h2>
                        <p>Personnel who are currently on leave.</p>
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
                                        <td><span class="pill pill-leave">On Leave</span></td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm"
                                                onclick="openDeleteModal({{ (int) $leave->teacher_id }}, {{ Js::from($leave->fullname) }})">
                                                <i class="fa-solid fa-trash"></i> Archive
                                            </button>
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
                        <p>Holidays, asynchronous classes, and other schedule changes that apply to all teachers.</p>
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

    </div>

    <!-- EDIT MODAL -->
    <div class="modal-backdrop" id="editModal">
        <div class="modal">

            <button class="modal-close" type="button" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>

            <h3>Edit Personnel</h3>
            <p>Update this personnel's information, schedule, or photo.</p>

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
            <p style="color: #8a7d5c; font-size: 13px;">
                This record will be moved to the Recycle Bin and will not be permanently deleted. You can restore it later.
            </p>

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
            <p>Record an approved leave period for a personnel.</p>

            <form method="POST" action="{{ route('superadmin.personnel.leave') }}" onsubmit="disableLeaveSubmit(this)">
                @csrf
                @include('superadmin.partials.return-fields')

                <div class="field-group">
                    <label>Personnel</label>
                    <select name="leave_teacher_id" required>
                        <option value="">Select Personnel</option>
                        @foreach ($allTeachers as $option)
                            <option value="{{ (int) $option['id'] }}">{{ $option['fullname'] }} - {{ $option['id_number'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field-group">
                    <label>Leave Type</label>
                    <select name="leave_type" required>
                        <option value="">Select Leave Type</option>
                        <option value="Sick Leave">Sick Leave</option>
                        <option value="Vacation Leave">Vacation Leave</option>
                        <option value="Emergency Leave">Emergency Leave</option>
                        <option value="Maternity Leave">Maternity Leave</option>
                        <option value="Paternity Leave">Paternity Leave</option>
                        <option value="Other">Other</option>
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
            <p>Add a holiday, asynchronous class, or other schedule change that will automatically apply to all teachers' DTR.</p>

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
                <p class="picker-help">Optional. Personnel selected here still work on this schedule change, following their own individual schedule (it does not apply to them as a no-work day).</p>

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
            <p style="font-size:12px;">It will no longer apply to any DTR, but the record is kept for history.</p>
            <p style="color: #8a7d5c; font-size: 12px;">
                This will move it to the Recycle Bin and will not be permanently deleted. You can restore it later.
            </p>

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
        'teachers' => $teachers,
        'allTeachers' => $allTeachers,
        'schedulePicker' => $schedulePicker,
    ];
@endphp
<script>
window.PAGE = @json($pageData);
</script>
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
