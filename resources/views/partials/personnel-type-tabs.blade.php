{{-- Personnel type tabs (All Personnel | Teaching Staff | Non-Teaching
     Staff), the Settings pill style. Links that keep the page's other
     filters ($tabUrl) and start again at page 1; the selected tab
     ($personnelType, an ?academic_status= value) stays in the URL. --}}
<nav class="dept-tabs type-tabs" aria-label="Personnel type">
    @foreach (\App\Domain\PersonnelDirectory::TYPE_TABS as $type => $label)
        <a href="{{ $tabUrl($type) }}" class="dept-tab-btn {{ $personnelType === $type ? 'active' : '' }}"
           @if ($personnelType === $type) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
