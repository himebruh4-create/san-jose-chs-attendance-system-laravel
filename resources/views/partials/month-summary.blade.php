{{-- "This month" row of the three dashboards: this month's totals up to
     today, the same numbers as Reports -> Monthly ($monthTotals, from
     DashboardStats::thisMonth()). Only the Super Admin's Pending Review
     number is a link (Attendance Adjustments -> Pending Review). --}}
@php
    $role = auth()->user()->role;
    $statusClasses = ['Present' => 'present', 'Late' => 'late', 'Absent' => 'absent', 'On Leave' => 'leave', 'Pending Review' => 'pending'];
@endphp

<section class="month-summary" aria-labelledby="monthSummaryTitle">
    <h2 class="month-summary-title" id="monthSummaryTitle">This month:</h2>

    <ul class="month-summary-counts">
        @foreach ($statusClasses as $status => $class)
            <li class="ms-{{ $class }}">
                <span>{{ $status }}</span>
                @if ($status === 'Pending Review' && $role === 'superadmin')
                    <a href="{{ route('superadmin.adjustments', ['tab' => 'pending']) }}" title="Open Pending Review">{{ $monthTotals[$status] }}</a>
                @else
                    <strong>{{ $monthTotals[$status] }}</strong>
                @endif
            </li>
        @endforeach
    </ul>

    <a class="month-summary-link" href="{{ route($role.'.reports', ['tab' => 'monthly', 'monthPicker' => date('Y-m')]) }}">View calendar &rarr;</a>
</section>
