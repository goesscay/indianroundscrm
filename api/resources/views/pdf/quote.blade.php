<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $quote->quote_no }}</title>
<style>
  @page { margin: 28px 34px 40px 34px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.45; }
  h1 { font-size: 20px; margin: 0; letter-spacing: 1px; }
  h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #1a1a1a; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 5px 6px; vertical-align: top; }
  .muted { color: #666; }
  .right { text-align: right; }
  .head td { padding: 0; }
  .brand { font-size: 16px; font-weight: bold; }
  .box { border: 1px solid #d4d4d4; padding: 8px 10px; }
  .lines th { background: #1a1a1a; color: #fff; font-size: 9px; text-align: left; text-transform: uppercase; letter-spacing: .5px; }
  .lines td { border-bottom: 1px solid #e5e5e5; }
  .totals td { padding: 3px 6px; }
  .grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #1a1a1a; padding-top: 6px; }
  .cols td { width: 50%; padding-right: 14px; }
  .pre { white-space: pre-line; }
  .day { margin-bottom: 5px; }
  .foot { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; font-size: 8px; color: #888; }
</style>
</head>
<body>
@php($c = config('crm.company'))
@php($inr = fn ($n) => '₹'.number_format((float) $n, 2, '.', ','))
<table class="head">
  <tr>
    <td>
      <div class="brand">{{ $c['name'] }}</div>
      @if($c['tagline'])<div class="muted">{{ $c['tagline'] }}</div>@endif
      @if($c['address'])<div class="muted pre">{{ $c['address'] }}</div>@endif
      <div class="muted">{{ trim($c['email'].($c['phone'] ? '  ·  '.$c['phone'] : '')) }}</div>
      @if($c['gstin'])<div class="muted">GSTIN {{ $c['gstin'] }}</div>@endif
    </td>
    <td class="right">
      <h1>QUOTATION</h1>
      <div><strong>{{ $quote->quote_no }}</strong></div>
      <div class="muted">Date {{ $quote->issue_date->format('d M Y') }}</div>
      @if($quote->valid_until)<div class="muted">Valid until {{ $quote->valid_until->format('d M Y') }}</div>@endif
    </td>
  </tr>
</table>

<table style="margin-top:14px"><tr>
  <td style="width:50%; padding-left:0">
    <div class="box">
      <div class="muted" style="text-transform:uppercase; font-size:8px; letter-spacing:1px">Prepared for</div>
      <div style="font-size:12px"><strong>{{ $quote->customer->name }}</strong></div>
      @if($quote->customer->company)<div>{{ $quote->customer->company }}</div>@endif
      @php($addr = collect([$quote->customer->address_line, $quote->customer->city, $quote->customer->state, $quote->customer->pincode])->filter()->implode(', '))
      @if($addr)<div class="muted">{{ $addr }}</div>@endif
      @if($quote->customer->phone)<div class="muted">{{ $quote->customer->phone }}</div>@endif
      @if($quote->customer->email)<div class="muted">{{ $quote->customer->email }}</div>@endif
      @if($quote->customer->gstin)<div class="muted">GSTIN {{ $quote->customer->gstin }}</div>@endif
    </div>
  </td>
  <td style="width:50%; padding-right:0">
    <div class="box">
      <div class="muted" style="text-transform:uppercase; font-size:8px; letter-spacing:1px">Trip</div>
      <div style="font-size:12px"><strong>{{ $quote->title }}</strong></div>
      @if($quote->destination)<div>{{ $quote->destination }}</div>@endif
      <div class="muted">
        @if($quote->travel_date)Travel {{ $quote->travel_date->format('d M Y') }}@endif
        @if($quote->duration_days) · {{ max(0, $quote->duration_days - 1) }}N / {{ $quote->duration_days }}D @endif
      </div>
      <div class="muted">{{ $quote->adults }} adult{{ $quote->adults == 1 ? '' : 's' }}@if($quote->children), {{ $quote->children }} child{{ $quote->children == 1 ? '' : 'ren' }}@endif</div>
    </div>
  </td>
</tr></table>

@if($quote->intro)
  <p class="pre" style="margin-top:12px">{{ $quote->intro }}</p>
@endif

<h2>Price</h2>
<table class="lines">
  <thead><tr><th style="width:44%">Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">GST</th><th class="right">Amount</th></tr></thead>
  <tbody>
  @foreach($quote->lines as $l)
    <tr>
      <td>{{ $l->description }}</td>
      <td class="right">{{ rtrim(rtrim(number_format($l->qty, 2, '.', ''), '0'), '.') }}</td>
      <td class="right">{{ $inr($l->unit_price) }}</td>
      <td class="right">{{ $l->discount > 0 ? $inr($l->discount) : '—' }}</td>
      <td class="right">{{ rtrim(rtrim(number_format($l->tax_rate, 2, '.', ''), '0'), '.') }}%</td>
      <td class="right">{{ $inr($l->amount + $l->tax) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
<table class="totals" style="width:46%; margin-left:54%; margin-top:8px">
  <tr><td>Subtotal</td><td class="right">{{ $inr($quote->subtotal) }}</td></tr>
  @if($quote->discount_total > 0)<tr><td>Discount</td><td class="right">− {{ $inr($quote->discount_total) }}</td></tr>@endif
  <tr><td>GST</td><td class="right">{{ $inr($quote->tax_total) }}</td></tr>
  @if($quote->tcs_total > 0)<tr><td>TCS ({{ rtrim(rtrim(number_format($quote->tcs_rate, 2, '.', ''), '0'), '.') }}%)</td><td class="right">{{ $inr($quote->tcs_total) }}</td></tr>@endif
  <tr class="grand"><td>Total</td><td class="right">{{ $inr($quote->total) }}</td></tr>
</table>

@php($days = $quote->itineraryArray())
@if(count($days))
  <h2>Itinerary</h2>
  @foreach($days as $i => $d)
    <div class="day"><strong>Day {{ $d['day_no'] ?? $i + 1 }} — {{ $d['title'] ?? '' }}</strong>
      @if(!empty($d['description']))<div class="pre muted">{{ $d['description'] }}</div>@endif
    </div>
  @endforeach
@endif

@if($quote->inclusions || $quote->exclusions)
  <table class="cols" style="margin-top:6px"><tr>
    <td>@if($quote->inclusions)<h2>Inclusions</h2><div class="pre">{{ $quote->inclusions }}</div>@endif</td>
    <td>@if($quote->exclusions)<h2>Exclusions</h2><div class="pre">{{ $quote->exclusions }}</div>@endif</td>
  </tr></table>
@endif

@if($quote->terms)
  <h2>Terms &amp; conditions</h2>
  <div class="pre muted">{{ $quote->terms }}</div>
@endif

<div class="foot">{{ $c['name'] }} · {{ $c['email'] }}@if($c['website']) · {{ $c['website'] }}@endif · Quote {{ $quote->quote_no }}</div>
</body>
</html>
