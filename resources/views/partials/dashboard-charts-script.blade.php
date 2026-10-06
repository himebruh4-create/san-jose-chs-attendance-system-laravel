{{-- Today doughnut + weekly bar charts shared by the Super Admin and Admin
     dashboards. Needs $todayCounts and $week. --}}
@php
    $todayValues = [$todayCounts['Present'], $todayCounts['Late'], $todayCounts['Absent'], $todayCounts['On Leave']];
@endphp
<script>
const todayLabels = ['Present', 'Late', 'Absent', 'On Leave'];
const todayValues = @json($todayValues);

new Chart(document.getElementById('todayChart'), {
    type: 'doughnut',
    data: {
        labels: todayLabels,
        datasets: [{
            data: todayValues,
            backgroundColor: ['#1b5e20', '#e65100', '#b71c1c', '#a3720f'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    },
    plugins: [{
        id: 'todayEmptyState',
        afterDraw(chart) {
            const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
            if (total === 0) {
                const { ctx, chartArea: { left, right, top, bottom } } = chart;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '600 13px Segoe UI, Arial, sans-serif';
                ctx.fillStyle = '#8a7d5c';
                ctx.fillText('No attendance data for today', (left + right) / 2, (top + bottom) / 2);
                ctx.restore();
            }
        }
    }]
});

const weekLabels = @json($week['labels']);
const weekData = @json($week['data']);

new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: {
        labels: weekLabels,
        datasets: [
            { label: 'Present', data: weekData['Present'], backgroundColor: '#1b5e20' },
            { label: 'Late', data: weekData['Late'], backgroundColor: '#e65100' },
            { label: 'Absent', data: weekData['Absent'], backgroundColor: '#b71c1c' },
            { label: 'On Leave', data: weekData['On Leave'], backgroundColor: '#a3720f' }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: false },
            y: { stacked: false, beginAtZero: true, ticks: { precision: 0 } }
        },
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>
