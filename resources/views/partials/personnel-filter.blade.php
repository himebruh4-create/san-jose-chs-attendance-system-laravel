{{-- Search + Personnel Type + Employment filter of the personnel card lists
     (all three roles). Print All ID Badges is the Super Admin's ($canManage)
     and always prints the full, unfiltered list. --}}
<form method="GET" action="{{ route($route) }}" class="personnel-filter">

    <div class="pf-field pf-search">
        <label for="pfSearch">Search</label>
        <input type="text" id="pfSearch" name="search" placeholder="Search by Name or ID No." value="{{ $search }}">
    </div>

    <div class="pf-field">
        <label for="pfType">Personnel Type</label>
        <select id="pfType" name="academic_status">
            <option value="">All Personnel Types</option>
            @foreach (\App\Domain\PersonnelDirectory::TYPE_LABELS as $value => $label)
                <option value="{{ $value }}" @selected($filterAcademic === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="pf-field">
        <label for="pfEmployment">Employment</label>
        <select id="pfEmployment" name="employment_type">
            <option value="">All Employment Types</option>
            @foreach (['Regular', 'Part-Time', 'Contractual'] as $type)
                <option value="{{ $type }}" @selected($filterEmployment === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>

    <div class="pf-actions">
        <button type="submit" class="pf-btn pf-apply"><i class="fa-solid fa-check"></i> Apply</button>
        <a href="{{ route($route) }}" class="pf-btn pf-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
        @if ($canManage)
            <button type="button" class="pf-btn pf-badges" onclick="printAllTeacherBadges()">
                <i class="fa-solid fa-id-card"></i> Print All ID Badges
            </button>
        @endif
    </div>

</form>
