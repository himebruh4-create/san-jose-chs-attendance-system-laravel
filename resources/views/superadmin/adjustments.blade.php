@extends('layouts.app')

@section('title', 'Attendance Adjustments')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/superadmin-adjustments.css') }}">
@endpush

@section('content')
    <!-- ==============================
         MAIN CONTENT
    ============================== -->

    <main class="main-content">


       <div class="adjustments-header">

    <div>
        <h1>
            Attendance Adjustments
        </h1>

        <p>
            Review and manage incomplete or corrected teacher attendance records.
        </p>
    </div>

    <button
        type="button"
        class="add-adjustment-btn"
        onclick="openAddAdjustmentModal()" >
        + Add Adjustment
    </button>

</div>



               <div class="tabs">
            <button class="tab-btn active" onclick="switchAdjustmentTab('adjustments', this)">
                Attendance Adjustments
            </button>
            <button class="tab-btn" onclick="switchAdjustmentTab('pending', this)">
                Pending Review <span class="pending-badge" id="pendingCountBadge">0</span>
            </button>
            <button class="tab-btn" onclick="switchAdjustmentTab('rejected', this); loadRejectedScans();">
                Rejected Scan Log <span class="pending-badge" id="rejectedCountBadge">0</span>
            </button>
        </div>

        <div id="adjustmentsTab" class="tab-content active">

            <div class="adjustments-table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Personnel
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Attendance Issue
                            </th>

                            <th>
                                Remarks
                            </th>

                            <th>
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody id="adjustmentsTableBody">

                        <tr>

                            <td
                                colspan="5"
                                class="empty-state"
                            >

                                Loading attendance adjustments...

                            </td>

                        </tr>

                    </tbody>



                </table>



            </div>

        </div>

        <div id="pendingTab" class="tab-content">

            <div class="adjustments-table-wrapper">

                <div class="pending-scroll-wrapper sticky-table-wrapper" id="pendingScrollWrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Personnel</th>
                                <th>Date</th>
                                <th>Scheduled Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="pendingReviewTableBody">
                            <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="pending-scroll-controls">
                    <button type="button" class="scroll-btn" onclick="scrollPending(-1)" title="Scroll up">
                        <i class="fa-solid fa-chevron-up"></i>
                    </button>
                    <button type="button" class="scroll-btn" onclick="scrollPending(1)" title="Scroll down">
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                </div>

            </div>

        </div>

        <div id="rejectedTab" class="tab-content">

            <div class="adjustments-table-wrapper">

                <p style="margin:1 1 10px; color:#5c5238; font-size:13.5px;">
                    Scans the kiosk refused (duplicate scans, or extra scans after attendance was already complete).
                    These are audit records: they are kept permanently and are never deleted here.
                </p>

                <div style="margin-bottom:10px;">
                    <label style="font-weight:600; color:#5c5238; margin-right:6px;">Show</label>
                    <select id="rejectedFilter" onchange="loadRejectedScans()" style="padding:7px 10px; border:1px solid #e6dcc4; border-radius:8px;">
                        <option value="all">All</option>
                        <option value="unreviewed">Not yet reviewed</option>
                        <option value="reviewed">Reviewed</option>
                    </select>
                </div>

                <div class="pending-scroll-wrapper sticky-table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Personnel</th>
                                <th>Attempted</th>
                                <th>Attendance Date</th>
                                <th>Reason</th>
                                <th>Existing OUT</th>
                                <th>Review</th>
                            </tr>
                        </thead>
                        <tbody id="rejectedScansTableBody">
                            <tr><td colspan="6" style="text-align:center;">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

    </main>

    

    <!-- =========================================
     ADD ATTENDANCE ADJUSTMENT MODAL
========================================= -->

<div
    class="adjustment-modal-backdrop"
    id="adjustmentModal"
>

    <div class="adjustment-modal">

        <div class="adjustment-modal-header">

            <div>
                <h2>
                    Add Attendance Adjustment
                </h2>

                <p>
                    Manually record a teacher's attendance when a scan is missing or needs correction.
                </p>
            </div>

            <button
                type="button"
                class="adjustment-close-btn"
                onclick="closeAdjustmentModal()"
            >
                ×
            </button>

        </div>


        <div class="adjustment-form">

            <!-- TEACHER -->

            <div class="form-group">

               <label>
    Personnel
</label>

<select
    name="teacher_id"
    id="adjustmentTeacher"
    required
    onchange="loadRealScansPreview()"
>
    <option value="">
        Select Personnel
    </option>

    @foreach ($teachers as $teacher)
        <option value="{{ (int) $teacher->id }}">{{ $teacher->fullname }} - {{ $teacher->id_number }}{{ $teacher->is_deleted ? ' (archived)' : '' }}</option>
    @endforeach

</select>
            </div>


            <!-- DATE -->

            <div class="form-group">

                <label for="adjustmentDate">
                    Date
                </label>

                <input
                    type="date"
                    id="adjustmentDate"
                    onchange="loadRealScansPreview()"
                >

            </div>

            <!-- REAL SCANS FOR THE DAY (what the scanner actually recorded) -->

            <div class="form-group" id="realScansPreview" style="display:none; background:#fbf8f0; border:1px solid #e6dcc4; border-radius:10px; padding:10px 12px; font-size:13px; color:#5c5238;"></div>


            <!-- ISSUE -->

            <div class="form-group">

                <label for="adjustmentType">
                    Attendance Issue
                </label>

                <select
                    id="adjustmentType"
                >

                    <option value="">
                        Select Issue
                    </option>

                    <option value="Forgot to Scan">
                        Forgot to Scan
                    </option>

                    <option value="Missing Time-In">
                        Missing Time-In
                    </option>

                    <option value="Missing Time-Out">
                        Missing Time-Out
                    </option>

                    <option value="Incomplete Attendance">
                        Incomplete Attendance
                    </option>

                    <option value="Official Business">
                        Official Business
                    </option>

                    <option value="Other">
                        Other
                    </option>

                </select>

            </div>


            <!-- AM ARRIVAL -->

            <div class="form-group">

                <label for="adjustmentAMArrival">
                    AM Arrival
                </label>

                <input
                    type="time"
                    id="adjustmentAMArrival"
                >

            </div>


            <!-- AM DEPARTURE -->

            <div class="form-group">

                <label for="adjustmentAMDeparture">
                    AM Departure
                </label>

                <input
                    type="time"
                    id="adjustmentAMDeparture"
                >

            </div>


            <!-- PM ARRIVAL -->

            <div class="form-group">

                <label for="adjustmentPMArrival">
                    PM Arrival
                </label>

                <input
                    type="time"
                    id="adjustmentPMArrival"
                >

            </div>


            <!-- PM DEPARTURE -->

            <div class="form-group">

                <label for="adjustmentPMDeparture">
                    PM Departure
                </label>

                <input
                    type="time"
                    id="adjustmentPMDeparture"
                >

            </div>


            <!-- REMARKS -->

            <div class="form-group full-width">

                <label for="adjustmentRemarks">
                    Remarks
                </label>

                <textarea
                    id="adjustmentRemarks"
                    rows="4"
                    placeholder="Enter the reason or explanation for this adjustment..."
                ></textarea>

            </div>

        </div>


        <div class="adjustment-modal-footer">

            <button
                type="button"
                class="cancel-adjustment-btn"
                onclick="closeAdjustmentModal()"
            >
                Cancel
            </button>

            <button
                type="button"
                class="save-adjustment-btn"
                onclick="saveAttendanceAdjustment()"
            >
                Save Adjustment
            </button>

        </div>

    </div>

</div>

<!-- =========================================
     REVIEW ATTENDANCE ADJUSTMENT MODAL
========================================= -->

<div
    class="adjustment-modal-backdrop"
    id="reviewAdjustmentModal"
>

    <div class="adjustment-modal">

        <div class="adjustment-modal-header">

            <div>

                <h2>
                    Review Attendance Adjustment
                </h2>

                <p>
                    View the details of this attendance adjustment.
                </p>

            </div>

            <button
                type="button"
                class="adjustment-close-btn"
                onclick="closeReviewAdjustmentModal()"
            >
                ×
            </button>

        </div>


        <div class="adjustment-form">


            <!-- TEACHER -->

            <div class="form-group">

                <label>
                    Personnel
                </label>

                <input
                    type="text"
                    id="reviewTeacher"
                    readonly
                >

            </div>


            <!-- DEPARTMENT -->

            <div class="form-group">

                <label>
                    Department
                </label>

                <input
                    type="text"
                    id="reviewDepartment"
                    readonly
                >

            </div>


            <!-- DATE -->

            <div class="form-group">

                <label>
                    Date
                </label>

                <input
                    type="date"
                    id="reviewDate"
                    readonly
                >

            </div>


            <!-- ISSUE -->

            <div class="form-group">

                <label>
                    Attendance Issue
                </label>

                <input
                    type="text"
                    id="reviewAdjustmentType"
                    readonly
                >

            </div>


            <!-- AM ARRIVAL -->

            <div class="form-group">

                <label>
                    AM Arrival
                </label>

                <input
                    type="time"
                    id="reviewAMArrival"
                    readonly
                >

            </div>


            <!-- AM DEPARTURE -->

            <div class="form-group">

                <label>
                    AM Departure
                </label>

                <input
                    type="time"
                    id="reviewAMDeparture"
                    readonly
                >

            </div>


            <!-- PM ARRIVAL -->

            <div class="form-group">

                <label>
                    PM Arrival
                </label>

                <input
                    type="time"
                    id="reviewPMArrival"
                    readonly
                >

            </div>


            <!-- PM DEPARTURE -->

            <div class="form-group">

                <label>
                    PM Departure
                </label>

                <input
                    type="time"
                    id="reviewPMDeparture"
                    readonly
                >

            </div>


            <!-- REMARKS -->

            <div class="form-group full-width">

                <label>
                    Remarks
                </label>

                <textarea
                    id="reviewRemarks"
                    rows="4"
                    readonly
                ></textarea>

            </div>


            <!-- STATUS -->

            <div class="form-group">

                <label>
                    Status
                </label>

                <input
                    type="text"
                    id="reviewStatus"
                    readonly
                >

            </div>

        </div>


        <div class="adjustment-modal-footer">

            <button
                type="button"
                class="cancel-adjustment-btn"
                onclick="closeReviewAdjustmentModal()"
            >
                Close
            </button>

        </div>

    </div>

</div>

<!-- =========================================================
     CONFIRM ABSENT MODAL
========================================================= -->

<div class="adjustment-modal-backdrop" id="confirmAbsentModal">

    <div class="adjustment-modal" style="width: min(420px, 92%);">

        <div class="adjustment-modal-header">

            <div>
                <h2>Confirm Absent</h2>
                <p>This will mark the teacher as officially Absent for this date.</p>
            </div>

            <button type="button" class="adjustment-close-btn" onclick="closeConfirmAbsentModal()">×</button>

        </div>

        <div class="adjustment-form" style="grid-template-columns: 1fr;">

            <p style="margin: 0; font-size: 14px; color: #3a3527;">
                Confirm <strong id="confirmAbsentName" style="color: #14390f;"></strong>
                as <strong>Absent</strong> on <strong id="confirmAbsentDate" style="color: #14390f;"></strong>?
            </p>

            <p style="margin: 0; font-size: 12.5px; color: #a89a6e;">
                This action cannot be undone from this screen.
            </p>

        </div>

        <div class="adjustment-modal-footer">
            <button type="button" class="cancel-adjustment-btn" onclick="closeConfirmAbsentModal()">Cancel</button>
            <button type="button" class="save-adjustment-btn" style="background: #c62828;" onclick="doConfirmAbsent()">
                Confirm Absent
            </button>
        </div>

    </div>

</div>

<!-- =========================================================
     DELETE ATTENDANCE ADJUSTMENT MODAL
========================================================= -->

<div class="adjustment-modal-backdrop" id="deleteAdjustmentModal">

    <div class="adjustment-modal" style="width: min(420px, 92%);">

        <div class="adjustment-modal-header">

            <div>
                <h2>Archive Adjustment</h2>
                <p>This will move the adjustment record to the Recycle Bin.</p>
            </div>

            <button type="button" class="adjustment-close-btn" onclick="closeDeleteAdjustmentModal()">×</button>

        </div>

        <div class="adjustment-form" style="grid-template-columns: 1fr;">

            <p style="margin: 0; font-size: 14px; color: #3a3527;">
                Are you sure you want to archive the adjustment for
                <strong id="deleteAdjustmentName" style="color:#14390f;"></strong>
                on <strong id="deleteAdjustmentDate" style="color:#14390f;"></strong>?
            </p>

            <p style="margin: 0; font-size: 12.5px; color: #a89a6e;">
                This record will be moved to the Recycle Bin and will not be permanently deleted. You can restore it later.
                If the day still needs an adjustment in the meantime, you'll need to add a new one.
            </p>

        </div>

        <div class="adjustment-modal-footer">
            <button type="button" class="cancel-adjustment-btn" onclick="closeDeleteAdjustmentModal()">Cancel</button>
            <button type="button" class="save-adjustment-btn" style="background: #c62828;" onclick="confirmDeleteAdjustment()">
                Archive
            </button>
        </div>

    </div>

</div>

<!-- APPLICATION ERROR MODAL -->
<div class="adjustment-modal-backdrop" id="appErrorModal">

    <div class="adjustment-modal" style="width: min(420px, 92%);">

        <div class="adjustment-modal-header">
            <div>
                <h2>Something Went Wrong</h2>
                <p id="appErrorModalMessage" style="margin-top:6px;"></p>
            </div>
            <button type="button" class="adjustment-close-btn" onclick="closeErrorModal()">×</button>
        </div>

        <div class="adjustment-modal-footer">
            <button type="button" class="save-adjustment-btn" onclick="closeErrorModal()">OK</button>
        </div>

    </div>

</div>

<!-- ==============================
     TOAST NOTIFICATION
================================= -->

<div id="toastNotification" class="toast-notification">
    <span id="toastMessage"></span>
</div>
@endsection

@push('scripts')
<script src="{{ asset_v('js/superadmin-adjustments.js') }}"></script>
@endpush
