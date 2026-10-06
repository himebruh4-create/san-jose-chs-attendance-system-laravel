@if ($status === 'On-Time')
<span class="status-pill status-ontime"><i class="fa-solid fa-circle-check"></i> On-Time</span>
@elseif ($status === 'Late')
<span class="status-pill status-late"><i class="fa-solid fa-triangle-exclamation"></i> Late</span>
@else
<span class="status-none">—</span>
@endif
