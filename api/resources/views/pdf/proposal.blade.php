<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $quote->quote_no }}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1F2430; line-height: 1.5; margin: 0; }
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; }
  img { display: block; }
  .sheet { position: relative; width: 794px; height: 1118px; overflow: hidden; page-break-after: always; }
  .sheet.last { page-break-after: auto; }
  .flow { position: relative; width: 794px; page-break-after: always; }
  .pad { padding: 44px 50px 0 50px; }
  .red { color: #D71920; }
  .muted { color: #6B7280; }
  .caps { text-transform: uppercase; letter-spacing: 2px; font-size: 8px; font-weight: bold; }
  .h1 { font-size: 24px; font-weight: bold; margin: 4px 0 0; }
  .rule { height: 3px; width: 46px; background: #D71920; margin: 10px 0 18px; }
  .foot { position: fixed; bottom: 14px; left: 50px; right: 50px; font-size: 7.5px; color: #8A909C; letter-spacing: .4px; }
  .foot .pn:after { content: counter(page); }
  .bar { background: #D71920; height: 6px; }

  .coverpanel { position: absolute; left: 0; top: 700px; width: 794px; height: 418px; background: #1F2430; color: #fff; }
  .chip { position: absolute; left: 40px; top: 36px; background: #fff; padding: 9px 14px; }
  .meta td { padding: 0 18px 0 0; }
  .meta .l { font-size: 7px; letter-spacing: 1.6px; text-transform: uppercase; color: #9AA0AC; }
  .meta .v { font-size: 11.5px; font-weight: bold; margin-top: 2px; }

  .tl td { padding: 0; border-bottom: 1px solid #ECEEF2; }
  .tl .no { width: 54px; padding: 11px 0; }
  .dot { width: 34px; height: 24px; padding-top: 10px; line-height: 14px; background: #D71920; color: #fff; font-weight: bold; text-align: center; border-radius: 17px; font-size: 11px; }
  .tl .t { padding: 11px 0; font-size: 12px; font-weight: bold; }
  .tl .s { padding: 11px 0; width: 170px; text-align: right; color: #6B7280; font-size: 9px; }

  .day { margin: 0 0 22px; }
  .day .tx { padding-left: 20px; }
  .day .dn { color: #D71920; font-size: 8px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; }
  .day .dt { font-size: 14px; font-weight: bold; margin: 2px 0 2px; }
  .day .ds { font-size: 8.5px; color: #6B7280; margin-bottom: 6px; }
  .day ul { margin: 0; padding-left: 14px; }
  .day li { margin: 0 0 3px; }
  .noimg { width: 260px; height: 170px; background: #F1F2F5; }

  .stay { margin: 0 0 24px; }
  .stay .nm { font-size: 17px; font-weight: bold; margin: 2px 0 2px; }
  .star { color: #C9A24B; letter-spacing: 1px; font-size: 11px; }
  .trn { font-size: 9px; color: #4B5160; margin: 5px 0 0; padding: 4px 7px; background: #F3F4F6; border-left: 3px solid #D71920; }
  .tt th { font-size: 7.5px; letter-spacing: 1px; text-transform: uppercase; color: #8A909C; text-align: left; padding: 5px 6px; border-bottom: 1px solid #E4E6EB; }
  .tt td { padding: 7px 6px; font-size: 10px; border-bottom: 1px solid #ECEEF2; vertical-align: top; }
  .tag { display: inline-block; background: #FDECEC; color: #D71920; font-size: 7px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; padding: 2px 5px; }
  .tag.info { background: #F3F4F6; color: #6B7280; }
  .badge { display: inline-block; background: #F3F4F6; border: 1px solid #E4E6EB; padding: 2px 7px; font-size: 8px; margin: 0 4px 4px 0; }
  .kv td { padding: 3px 0; border-bottom: 1px solid #F0F1F4; }
  .kv .k { width: 78px; color: #8A909C; font-size: 8px; text-transform: uppercase; letter-spacing: .8px; }

  .opt { border: 1px solid #E4E6EB; margin: 0 0 18px; }
  .opt .oh { background: #1F2430; color: #fff; padding: 9px 14px; font-size: 12px; font-weight: bold; }
  .opt .ob { padding: 12px 14px; }
  .price th { font-size: 7.5px; letter-spacing: 1px; text-transform: uppercase; color: #8A909C; text-align: right; padding: 4px 6px; border-bottom: 1px solid #E4E6EB; }
  .price td { text-align: right; padding: 8px 6px; font-size: 14px; font-weight: bold; color: #D71920; }
  .price th.l, .price td.l { text-align: left; }
  .total { background: #F8F8FA; border-left: 4px solid #D71920; padding: 12px 16px; }
  .sec { font-size: 9px; font-weight: bold; letter-spacing: 1.6px; text-transform: uppercase; color: #D71920; margin: 0 0 6px; }
  .list { margin: 0 0 14px; padding-left: 15px; }
  .list li { margin: 0 0 3px; }
  .cancel td, .cancel th { padding: 6px 10px; border-bottom: 1px solid #ECEEF2; text-align: left; }
  .cancel th { background: #F3F4F6; font-size: 7.5px; letter-spacing: 1px; text-transform: uppercase; color: #6B7280; }
  .cancel td.r, .cancel th.r { text-align: right !important; }
  .dark { background: #1F2430; color: #fff; }
</style>
</head>
<body>
@php($inr = fn ($n) => '₹ '.number_format((float) $n, 0, '.', ','))
@php($pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'))
@php($dm = fn ($d) => $d ? $d->format('d M Y') : '')
@php($nights = (int) $trip['nights'])
@php($persons = $trip['adults'] + $trip['children_bed'] + $trip['children_no_bed'])
@php($dest = $trip['destination'] ?: ($quote->destination ?: 'Your trip'))

<div class="foot"><table><tr><td>{{ $company['name'] }} · {{ $company['website'] }} · {{ $quote->quote_no }}</td><td style="text-align:right">Page <span class="pn"></span></td></tr></table></div>

{{-- cover --}}
<div class="sheet" style="background:#1F2430">
  @if($cover)<img src="{{ $cover }}" style="position:absolute; left:0; top:0; width:794px; height:707px" alt="">@else<div style="position:absolute; left:0; top:0; width:794px; height:707px; background:#2A3040"></div>@endif
  @if($logo)<div class="chip"><img src="{{ $logo }}" style="width:150px; height:auto" alt=""></div>@endif
  <div class="coverpanel">
    <div style="padding: 38px 50px 0 50px">
      <div class="caps" style="color:#F6A6A9">Travel proposal</div>
      <div style="font-size:34px; font-weight:bold; line-height:1.15; margin-top:6px">{{ $dest }}</div>
      @if($quote->title && $quote->title !== $dest.' trip')<div style="font-size:13px; color:#C9CDD6; margin-top:4px">{{ $quote->title }}</div>@endif
      <div style="height:3px; width:46px; background:#D71920; margin:16px 0 18px"></div>
      <div style="font-size:11px; color:#C9CDD6">Prepared for</div>
      <div style="font-size:17px; font-weight:bold; margin:1px 0 20px">{{ $quote->customer?->name }}</div>
      <table class="meta"><tr>
        <td><div class="l">Travel date</div><div class="v">{{ $start ? $start->format('d M Y') : 'To be decided' }}</div></td>
        <td><div class="l">Duration</div><div class="v">{{ $nights }} night{{ $nights === 1 ? '' : 's' }} / {{ $nights + 1 }} days</div></td>
        <td><div class="l">Travellers</div><div class="v">{{ $persons }}</div></td>
        <td><div class="l">Quotation</div><div class="v">{{ $quote->quote_no }}</div></td>
      </tr></table>
    </div>
  </div>
</div>

{{-- trip at a glance --}}
<div class="sheet">
  <div class="bar"></div>
  <div class="pad">
    <div class="caps red">Your journey</div>
    <div class="h1">{{ $dest }} at a glance</div>
    <div class="rule"></div>
    @if(count($days))
    <table class="tl">
      @foreach($days as $d)
      <tr>
        <td class="no"><div class="dot">{{ $d['no'] }}</div></td>
        <td class="t">{{ $d['title'] }}</td>
        <td class="s">{{ $d['stay'] ?: '' }}{{ $d['date'] ? ($d['stay'] ? ' · ' : '').$d['date']->format('d M') : '' }}</td>
      </tr>
      @endforeach
    </table>
    @else
    <p class="muted">The day-by-day plan will be shared with you shortly.</p>
    @endif
  </div>
</div>

{{-- day by day --}}
@foreach(collect($days)->chunk(4) as $chunk)
<div class="sheet">
  <div class="bar"></div>
  <div class="pad">
    <div class="caps red">Day by day</div>
    <div class="h1">Your itinerary</div>
    <div class="rule"></div>
    @foreach($chunk as $d)
    <table class="day"><tr>
      <td style="width:260px">@if($d['img'])<img src="{{ $d['img'] }}" style="width:260px; height:170px" alt="">@else<div class="noimg"></div>@endif</td>
      <td class="tx">
        <div class="dn">Day {{ $d['no'] }}{{ $d['date'] ? ' · '.$d['date']->format('d M Y') : '' }}</div>
        <div class="dt">{{ $d['title'] }}</div>
        @if($d['stay'])<div class="ds">Stay: {{ $d['stay'] }}</div>@endif
        <ul>@foreach(array_slice($d['bullets'], 0, 7) as $b)<li>{{ $b }}</li>@endforeach</ul>
        @foreach(($journey['by_day'][$d['no']] ?? []) as $tl)<div class="trn">Transport: {{ $tl['label'] }}{{ $tl['title'] && $tl['title'] !== $tl['label'] ? ' · '.$tl['title'] : '' }}{{ $tl['detail'] ? ' · '.$tl['detail'] : '' }}</div>@endforeach
      </td>
    </tr></table>
    @endforeach
  </div>
</div>
@endforeach

{{-- hotels --}}
@php($stayCards = collect($options)->flatMap(fn ($o) => collect($o['stays'])->map(fn ($s) => $s + ['option' => $o['name'], 'multi' => count($options) > 1])))
@foreach($stayCards->chunk(2) as $chunk)
<div class="sheet">
  <div class="bar"></div>
  <div class="pad">
    <div class="caps red">Where you will stay</div>
    <div class="h1">Handpicked hotels</div>
    <div class="rule"></div>
    @foreach($chunk as $s)
    @php($h = $s['hotel'])
    <table class="stay"><tr>
      <td style="width:320px">@if($s['main'])<img src="{{ $s['main'] }}" style="width:320px; height:220px" alt="">@else<div style="width:320px; height:220px; background:#F1F2F5"></div>@endif</td>
      <td style="padding-left:20px">
        @if($s['multi'])<div class="caps red" style="font-size:7.5px">Option · {{ $s['option'] }}</div>@endif
        <div class="nm">{{ $h->name }}</div>
        <div>@if($h->stars)<span class="star">{{ str_repeat('★', (int) $h->stars) }}</span> @endif @if($h->google_rating)<span class="muted" style="font-size:9px">{{ number_format($h->google_rating, 1) }} on Google{{ $h->google_reviews ? ' · '.number_format($h->google_reviews).' reviews' : '' }}</span>@endif</div>
        <table class="kv" style="margin-top:8px">
          @if($h->address)<tr><td class="k">Address</td><td>{{ $h->address }}</td></tr>@endif
          @if($h->phone)<tr><td class="k">Contact</td><td>{{ $h->phone }}</td></tr>@endif
          @if($s['check_in'])<tr><td class="k">Stay</td><td>{{ $dm($s['check_in']) }} to {{ $dm($s['check_out']) }} · {{ $s['nights'] }} night{{ $s['nights'] === 1 ? '' : 's' }}</td></tr>
          @else<tr><td class="k">Stay</td><td>{{ $s['nights'] }} night{{ $s['nights'] === 1 ? '' : 's' }}</td></tr>@endif
          <tr><td class="k">Room</td><td>{{ $s['room'] }}</td></tr>
          <tr><td class="k">Meals</td><td>{{ $s['meal'] }}</td></tr>
        </table>
        @if(!empty($h->amenities))<div style="margin-top:8px">@foreach(array_slice($h->amenities, 0, 6) as $a)<span class="badge">{{ $a }}</span>@endforeach</div>@endif
      </td>
    </tr></table>
    @if(count($s['gallery']))
    <table style="margin:-10px 0 26px"><tr>
      @foreach($s['gallery'] as $g)<td style="width:33.3%; padding-right:{{ $loop->last ? 0 : 8 }}px"><img src="{{ $g }}" style="width:100%; height:auto" alt=""></td>@endforeach
      @for($i = count($s['gallery']); $i < 3; $i++)<td style="width:33.3%"></td>@endfor
    </tr></table>
    @endif
    @endforeach
  </div>
</div>
@endforeach

{{-- transport --}}
@if($journey)
<div class="sheet">
  <div class="bar"></div>
  <div class="pad">
    <div class="caps red">Getting around</div>
    <div class="h1">Your transport</div>
    <div class="rule"></div>
    @foreach($journey['blocks'] as $b)
    <div class="sec" style="margin-top:{{ $loop->first ? 0 : 14 }}px">{{ $b['destination'] }}</div>
    <table class="tt" style="width:100%">
      <tr><th style="width:62px">Day</th><th style="width:84px">Date</th><th style="width:120px">Transport</th><th>Details</th><th style="width:74px"></th></tr>
      @foreach($b['lines'] as $l)
      <tr>
        <td>Day {{ $l['day'] }}</td>
        <td>{{ $l['date'] ? \Illuminate\Support\Carbon::parse($l['date'])->format('d M Y') : '' }}</td>
        <td><b>{{ $l['label'] }}</b></td>
        <td>{{ $l['title'] && $l['title'] !== $l['label'] ? $l['title'] : '' }}{{ $l['detail'] ? ($l['title'] && $l['title'] !== $l['label'] ? ' · ' : '').$l['detail'] : '' }}</td>
        <td style="text-align:right"><span class="tag {{ $l['included'] ? '' : 'info' }}">{{ $l['tag'] }}</span></td>
      </tr>
      @endforeach
    </table>
    @endforeach
    <div class="total" style="margin-top:16px">
      <div class="caps muted" style="font-size:7.5px">Transport summary</div>
      <table style="margin-top:6px">
        @foreach($journey['summary'] as $x)<tr><td style="font-size:10px"><b>{{ $x['service'] }}</b> <span class="muted">{{ $x['details'] }}</span></td><td style="text-align:right; font-size:9px; color:#6B7280">{{ $x['included'] ? 'included below' : 'for information' }}</td></tr>@endforeach
        <tr><td style="font-size:12px; font-weight:bold; padding-top:6px">Transport total{{ $journey['tax_display'] === 'with_gst' ? ' (incl. GST)' : ' (before GST)' }}</td>
          <td style="text-align:right; font-size:14px; font-weight:bold; color:#D71920; padding-top:4px">{{ $inr($journey['tax_display'] === 'with_gst' ? $journey['total_incl_gst'] : $journey['selling']) }}</td></tr>
      </table>
    </div>
    @foreach($journey['notes'] as $n)<p class="muted" style="margin:8px 0 0; font-size:9px">{{ $n }}</p>@endforeach
  </div>
</div>
@endif

{{-- price --}}
<div class="sheet">
  <div class="bar"></div>
  <div class="pad">
    <div class="caps red">Investment</div>
    <div class="h1">Your quotation</div>
    <div class="rule"></div>
    <p class="muted" style="margin:-6px 0 14px">Price per person. The more of you travelling, the less each person pays. GST of {{ $pct($p['gst_rate']) }}% is extra.</p>
    @foreach($options as $o)
    <div class="opt">
      <div class="oh">{{ $o['name'] }}@if(count($options) > 1 && $o['selected']) <span style="font-size:8px; letter-spacing:1px; color:#F6A6A9">&nbsp;· QUOTED BELOW</span>@endif</div>
      <div class="ob">
        @if(count($o['stays']))<div class="muted" style="margin-bottom:8px; font-size:9px">@foreach($o['stays'] as $s){{ $s['hotel']->name }} ({{ $s['nights'] }}N){{ $loop->last ? '' : '  ·  ' }}@endforeach</div>@endif
        @if($o['prices'])
        <table class="price">
          <tr><th class="l">Group size</th>@foreach($o['prices']['tiers'] as $t)<th>{{ $t['pax'] }} persons</th>@endforeach</tr>
          <tr><td class="l" style="font-size:9px; color:#6B7280; font-weight:normal">Per person</td>@foreach($o['prices']['tiers'] as $t)<td>{{ $t['per_person'] > 0 ? $inr($t['per_person']) : 'on request' }}</td>@endforeach</tr>
        </table>
        @endif
      </div>
    </div>
    @endforeach

    <div class="total">
      <div class="caps muted" style="font-size:7.5px">For your group of {{ $persons }}@if(count($options) > 1) · {{ $options[$p['selected_option']]['name'] ?? '' }}@endif</div>
      <table style="margin-top:6px">
        <tr><td>Package price</td><td style="text-align:right">{{ $inr($quote->subtotal - $quote->discount_total) }}</td></tr>
        <tr><td>GST ({{ $pct($p['gst_rate']) }}%)</td><td style="text-align:right">{{ $inr($quote->tax_total) }}</td></tr>
        @if((float) $quote->tcs_total > 0)<tr><td>TCS</td><td style="text-align:right">{{ $inr($quote->tcs_total) }}</td></tr>@endif
        <tr><td style="font-size:14px; font-weight:bold; padding-top:6px">Total</td><td style="text-align:right; font-size:17px; font-weight:bold; color:#D71920; padding-top:4px">{{ $inr($quote->total) }}</td></tr>
      </table>
    </div>
    @if($quote->valid_until)<p class="muted" style="margin-top:12px">This quotation is valid until <b>{{ $quote->valid_until->format('d M Y') }}</b>. Hotel rates and availability are subject to confirmation.</p>@endif
  </div>
</div>

{{-- inclusions and exclusions --}}
@php($c = $p['content'])
@if(count($c['inclusions']) || count($c['exclusions']))
<div class="flow">
  <div class="bar"></div>
  <div class="pad" style="padding-bottom:40px">
    <div class="caps red">The details</div>
    <div class="h1">What is included</div>
    <div class="rule"></div>
    <table><tr>
      <td style="width:50%; padding-right:18px">
        <div class="sec">Inclusions</div>
        <ul class="list">@foreach($c['inclusions'] as $x)<li>{{ $x }}</li>@endforeach</ul>
      </td>
      <td style="width:50%; padding-left:18px">
        <div class="sec" style="color:#6B7280">Exclusions</div>
        <ul class="list">@foreach($c['exclusions'] as $x)<li>{{ $x }}</li>@endforeach</ul>
      </td>
    </tr></table>
  </div>
</div>
@endif

{{-- policies --}}
@if(count($c['cancellation']['tiers']) || count($c['cancellation']['items']) || count($c['notes']) || count($c['terms']))
<div class="flow">
  <div class="bar"></div>
  <div class="pad" style="padding-bottom:40px">
    <div class="caps red">Good to know</div>
    <div class="h1">Policies</div>
    <div class="rule"></div>
    @if(count($c['cancellation']['tiers']))
    <div class="sec">Cancellation charges</div>
    <table class="cancel" style="margin-bottom:8px">
      <tr><th>When you cancel</th><th class="r">Charge</th></tr>
      @foreach($c['cancellation']['tiers'] as $t)<tr><td>{{ $t['label'] }}</td><td class="r"><b>{{ $pct($t['percent']) }}%</b></td></tr>@endforeach
    </table>
    @endif
    @if(count($c['cancellation']['items']))<ul class="list">@foreach($c['cancellation']['items'] as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
    @if(count($c['notes']))<div class="sec" style="margin-top:12px">Important notes</div><ul class="list">@foreach($c['notes'] as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
    @if(count($c['terms']))<div class="sec" style="margin-top:12px">Terms and conditions</div><ul class="list">@foreach($c['terms'] as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
  </div>
</div>
@endif

{{-- closing --}}
<div class="sheet dark last">
  <div style="padding: 150px 70px 0 70px; text-align:center">
    @if($logo)<div style="background:#fff; display:inline-block; padding:12px 20px"><img src="{{ $logo }}" style="width:200px; height:auto" alt=""></div>@endif
    <div style="height:3px; width:46px; background:#D71920; margin:34px auto 28px"></div>
    <div style="font-size:26px; font-weight:bold; line-height:1.3">Thank you for considering us</div>
    @foreach($c['closing'] as $x)<p style="font-size:12px; color:#C9CDD6; margin:14px 40px 0">{{ $x }}</p>@endforeach
    <div style="margin-top:70px; font-size:12px; line-height:1.9">
      <div style="font-weight:bold; font-size:14px">{{ $sign['name'] }}</div>
      <div style="color:#C9CDD6">{{ $sign['title'] }}</div>
      <div>{{ $sign['phone'] }}</div>
      <div style="color:#F6A6A9">{{ $company['email'] }}</div>
      <div style="color:#C9CDD6">{{ $company['website'] }}</div>
    </div>
    <div style="margin-top:46px; font-size:8.5px; color:#8A909C; white-space:pre-line">{{ $company['address'] }}</div>
  </div>
</div>
</body>
</html>
