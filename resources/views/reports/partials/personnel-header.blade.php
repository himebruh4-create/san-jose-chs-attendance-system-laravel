{{-- Profile at the top of a one-person report (screen and print). --}}
<div class="personnel-header">
    <img class="personnel-photo" src="{{ \App\Domain\PersonnelDirectory::photoUrl($person->photo) }}" alt="Photo of {{ $person->fullname }}">
    <div class="personnel-info">
        <div class="personnel-name">{{ $person->fullname }}</div>
        <dl class="personnel-meta">
            <div><dt>ID Number</dt><dd>{{ $person->id_number }}</dd></div>
            <div><dt>Position</dt><dd>{{ $person->department }}</dd></div>
            <div><dt>Personnel Type</dt><dd>{{ \App\Domain\PersonnelDirectory::TYPE_LABELS[$person->academic_status] ?? $person->academic_status }}</dd></div>
        </dl>
    </div>
</div>
