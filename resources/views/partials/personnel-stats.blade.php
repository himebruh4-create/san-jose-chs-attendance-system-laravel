{{-- Total / employment type / personnel type boxes. Needs $stats. --}}
<div class="stats-wrapper">

    <div class="stat-headline">
        <span class="stat-label">Total Personnel</span>
        <span class="stat-number">{{ $stats['total'] }}</span>
    </div>

    <div class="stat-group">
        <span class="stat-group-label">Employment Type</span>
        <div class="stat-group-boxes">
            @foreach ($stats['employment'] as $label => $count)
                <div class="stat-box-mini">
                    <span class="stat-mini-number">{{ $count }}</span>
                    <span class="stat-mini-label">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="stat-group">
        <span class="stat-group-label">Personnel Type</span>
        <div class="stat-group-boxes">
            @foreach ($stats['types'] as $label => $count)
                <div class="stat-box-mini">
                    <span class="stat-mini-number">{{ $count }}</span>
                    <span class="stat-mini-label">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>

</div>
