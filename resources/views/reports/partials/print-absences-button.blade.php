{{-- Print Absences: confirmed absences in this tab's range, with its
     Personnel filter (reports.absences; no scan photos). --}}
<button type="button" class="print-roster-btn print-absences-btn" data-url="{{ $url }}"
        data-error="Unable to generate the absence report. Please try again." onclick="printServerReport(this)">
    <i class="fa-solid fa-user-xmark"></i> Print Absences
</button>
