@php
use App\Services\StatsService;
use \Illuminate\Support\Str;
use \Illuminate\Support\Number;

$enabledVisitors = $trackingSettings['visitors'] ?? false;
$enabledUsers = $trackingSettings['users'] ?? false;
$enabledPayments = $trackingSettings['payments'] ?? false;

$formatChange = function ($current, $previous) {
    $current = (float) $current;
    $previous = (float) $previous;

    if ($previous == 0.0) {
        if ($current == 0.0) return '';
        return '<span style="color:#10b981;font-size:11px">▲ new</span>';
    }

    $change = round(($current - $previous) / abs($previous) * 100, 1);

    if ($change == 0.0) return '<span style="color:#6b7280;font-size:11px">0%</span>';
    if ($change > 0) return '<span style="color:#10b981;font-size:11px">▲ '.$change.'%</span>';
    return '<span style="color:#ef4444;font-size:11px">▼ '.abs($change).'%</span>';
};

$cell = function ($value, $change) {
    return $value.'<br>'.$change;
};

// ===== STATS ALL

$statsHeader = '';
if ($enabledVisitors) $statsHeader .= '|Visitors';
if ($enabledUsers) $statsHeader .= '|Registrations';
if ($enabledUsers && $enabledVisitors) $statsHeader .= '|Conversion';
if ($enabledUsers) $statsHeader .= '|'.$frequency->title()[0].'AU';
if ($enabledPayments) $statsHeader .= '|Revenue';
$statsHeader .= '|';

$statsSeparator = '';
$columnCount = substr_count($statsHeader, '|') - 1;
$statsSeparatorParts = array_fill(0, $columnCount, ':-:');
$statsSeparator = '|' . implode('|', $statsSeparatorParts) . '|';

$statsTH = $statsHeader . PHP_EOL . $statsSeparator;

$td = '';
if ($enabledVisitors) $td .= '|'.$cell($statsAll['pd_visitor'] >= 1000 ? Number::abbreviate($statsAll['pd_visitor'], 2) : $statsAll['pd_visitor'], $formatChange($statsAll['pd_visitor'] ?? 0, $statsAllPrev['pd_visitor'] ?? 0));
if ($enabledUsers) $td .= '|'.$cell($statsAll['pd_reg'] >= 1000 ? Number::abbreviate($statsAll['pd_reg'], 2) : $statsAll['pd_reg'], $formatChange($statsAll['pd_reg'] ?? 0, $statsAllPrev['pd_reg'] ?? 0));
if ($enabledUsers && $enabledVisitors) $td .= '|'.$cell(Number::percentage($statsAll['pd_cr'], 2), $formatChange($statsAll['pd_cr'] ?? 0, $statsAllPrev['pd_cr'] ?? 0));
if ($enabledUsers) $td .= '|'.$cell($statsAll['pd_dau'] >= 1000 ? Number::abbreviate($statsAll['pd_dau'], 2) : $statsAll['pd_dau'], $formatChange($statsAll['pd_dau'] ?? 0, $statsAllPrev['pd_dau'] ?? 0));
if ($enabledPayments) $td .= '|'.$cell(Number::currency($statsAll['pd_net']/100, in: $currency, precision: 2), $formatChange($statsAll['pd_net'] ?? 0, $statsAllPrev['pd_net'] ?? 0));

$statsTable = $statsTH. PHP_EOL.$td .'|'. PHP_EOL;

// ===== STATS GROUPS

$statsGroupsHeader = '|Name';
if ($enabledVisitors) $statsGroupsHeader .= '|Visitors';
if ($enabledUsers) $statsGroupsHeader .= '|Regs';
if ($enabledUsers && $enabledVisitors) $statsGroupsHeader .= '|Conv.';
if ($enabledUsers) $statsGroupsHeader .= '|'.$frequency->title()[0].'AU';
if ($enabledPayments) $statsGroupsHeader .= '|Revenue';
$statsGroupsHeader .= '|';

$statsGroupsSeparator = '';
$columnCount = substr_count($statsGroupsHeader, '|') - 1;
$statsGroupsSeparatorParts = array_fill(0, $columnCount, '-:');
$statsGroupsSeparatorParts[0] = ':-';
$statsGroupsSeparator = '|' . implode('|', $statsGroupsSeparatorParts) . '|';

$statsGroupsTH = $statsGroupsHeader . PHP_EOL . $statsGroupsSeparator;

$statsGroupsTables = [];
foreach ($statsGroups as $key => $statsGroup) {
    if (empty($statsGroup['stats'])) continue;

    if (!isset($statsGroupsTables[$key])) {
        $statsGroupsTables[$key] = $statsGroupsTH . PHP_EOL;
    }

    $prevByName = collect($statsGroupsPrev[$key]['stats'] ?? [])->keyBy('name');

    foreach ($statsGroup['stats'] as $stats) {
        $name = $stats['name'];
        $prev = $prevByName->get($name, []);

        if (Str::endsWith($name, '/')) {
            $name = '/' . Str::substr($name, 0, -1);
        }

        $td = '|'.e(Str::reverse(Str::limit(Str::reverse($name), 20)));
        if ($enabledVisitors) $td .= '|'.$cell($stats['visitors'] >= 1000 ? Number::abbreviate($stats['visitors'], 2) : $stats['visitors'], $formatChange($stats['visitors'] ?? 0, $prev['visitors'] ?? 0));
        if ($enabledUsers) $td .= '|'.$cell($stats['reg'] >= 1000 ? Number::abbreviate($stats['reg'], 2) : $stats['reg'], $formatChange($stats['reg'] ?? 0, $prev['reg'] ?? 0));
        if ($enabledUsers && $enabledVisitors) $td .= '|'.$cell(Number::percentage($stats['cr'], 2), $formatChange($stats['cr'] ?? 0, $prev['cr'] ?? 0));
        if ($enabledUsers) $td .= '|'.$cell($stats['dau'] >= 1000 ? Number::abbreviate($stats['dau'], 2) : $stats['dau'], $formatChange($stats['dau'] ?? 0, $prev['dau'] ?? 0));
        if ($enabledPayments) $td .= '|'.$cell(Number::currency($stats['net']/100, in: $currency, precision: 2), $formatChange($stats['net'] ?? 0, $prev['net'] ?? 0));

        $statsGroupsTables[$key] .= $td .'|'. PHP_EOL;
    }
}
@endphp

@component('mail::message')
![Chart]({{ asset('images/undraw/undraw_data_0ml2.png') }})<br>
<br>
# {{ $frequency->title() }} Report

## {{ $timeRange }}
<br>
📬 Here is your {{ $frequency->value }} report for <b>{{ $projectName }}</b>:<br>
@component('mail::table')
{!! $statsTable !!}
@endcomponent
<br>
@foreach($statsGroupsTables as $key => $table)
📊 Breakdown by <strong>{{ $statsGroups[$key]['title'] }}</strong>:
@component('mail::table')
{!! $table !!}
@endcomponent
<br>
@endforeach

---

@include('emails.partials.self-hosted-funding')
<small>Don't want to receive these notifications? <a href="{{ route('notifications.index') }}" target="_blank">Change notification settings</a></small>
@endcomponent
