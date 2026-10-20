{{-- One personnel card (all three roles). Everything View More shows travels
     in data-personnel; the Super Admin's ($canManage) hidden Regenerate form
     is here too, submitted by the regenerate confirmation modal. --}}
@php
    $isAcademic = $teacher['academic_status'] === 'Academic';
    $typeLabel = \App\Domain\PersonnelDirectory::TYPE_LABELS[$teacher['academic_status']] ?? $teacher['academic_status'];

    $scheduleLines = array_map(function (array $schedule) use ($breakConfig) {
        $line = $schedule['day'].': '.date('g:i A', strtotime($schedule['time_in'])).' - '.date('g:i A', strtotime($schedule['time_out']));
        $paidBreak = $breakConfig ? \App\Domain\Attendance\dtrBreakForSchedule($breakConfig, $schedule) : null;

        return $paidBreak
            ? $line.' (paid break '.date('g:i A', strtotime($paidBreak['start'])).' - '.date('g:i A', strtotime($paidBreak['end'])).')'
            : $line;
    }, $teacher['schedules']);

    $details = [
        'id' => (int) $teacher['id'],
        'number' => $number,
        'id_number' => $teacher['id_number'],
        'fullname' => $teacher['fullname'],
        'department' => $teacher['department'],
        'academic_status' => $teacher['academic_status'],
        'type_label' => $typeLabel,
        'employment_type' => $teacher['employment_type'],
        'barcode' => $teacher['barcode'],
        'photo' => $teacher['photo'] ?? '',
        'photo_url' => \App\Domain\PersonnelDirectory::photoUrl($teacher['photo'] ?? null),
        'schedules' => $scheduleLines,
    ];
@endphp

<article class="personnel-card">
    <img class="pc-photo" src="{{ $details['photo_url'] }}" alt="Photo of {{ $teacher['fullname'] }}" loading="lazy">

    <div class="pc-body">
        <div class="pc-id">{{ $teacher['id_number'] }}</div>
        <h3 class="pc-name">{{ $teacher['fullname'] }}</h3>
        <div class="pc-position">{{ $teacher['department'] }}</div>
        <span class="type-badge {{ $isAcademic ? 'type-academic' : 'type-non-academic' }}">{{ $typeLabel }}</span>
    </div>

    <button type="button" class="pc-view-more" data-personnel="{{ json_encode($details) }}" onclick="openPersonnelDetails(this)">
        View More
    </button>

    @if ($canManage)
        <form method="POST" action="{{ route('superadmin.personnel.regenerate-barcode') }}" id="regenerateForm-{{ (int) $teacher['id'] }}" hidden>
            @csrf
            @include('superadmin.partials.return-fields')
            <input type="hidden" name="id" value="{{ (int) $teacher['id'] }}">
        </form>
    @endif
</article>
