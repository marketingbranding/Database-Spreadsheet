@php
    $tabsQuery = request()->query();
    unset($tabsQuery['page']);
    $tabsQuery['tab'] = 'antrean';
    $antreanUrl = request()->url() . '?' . http_build_query($tabsQuery);
    $tabsQuery['tab'] = 'riwayat';
    $riwayatUrl = request()->url() . '?' . http_build_query($tabsQuery);
@endphp
<nav class="stage-tabs" aria-label="Tab tahapan">
    <a href="{{ $antreanUrl }}" @class(['active' => $active !== 'riwayat']) @if(($active ?? 'antrean') !== 'riwayat') aria-current="page" @endif>Antrean</a>
    <a href="{{ $riwayatUrl }}" @class(['active' => ($active ?? 'antrean') === 'riwayat']) @if(($active ?? 'antrean') === 'riwayat') aria-current="page" @endif>Riwayat</a>
</nav>
