<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $inv->invoice_no }}</title>
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
  .pre { white-space: pre-line; }
  .tag { display: inline-block; border: 1px solid #1a1a1a; padding: 1px 8px; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; margin-top: 4px; }
  .void { color: #b91c1c; border-color: #b91c1c; }
  .foot { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; font-size: 8px; color: #888; }
</style>
</head>
<body>
@php($c = config('crm.company'))
@php($inr = fn ($n) => '₹'.number_format((float) $n, 2, '.', ','))
@php($pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'))
<table class="head">
  <tr>
    <td>
      <div class="brand">{{ $c['name'] }}</div>
      @if($c['tagline'])<div class="muted">{{ $c['tagline'] }}</div>@endif
      @if($c['address'])<div class="muted pre">{{ $c['address'] }}</div>@endif
      <div class="muted">{{ trim($c['email'].($c['phone'] ? '  ·  '.$c['phone'] : '')) }}</div>
      @if($c['gstin'])<div class="muted">GSTIN {{ $c['gstin'] }}@if($c['state']) · {{ $c['state'] }}@endif</div>@endif
    </td>
    <td class="right">
      <h1>{{ $c['gstin'] ? 'TAX INVOICE' : 'INVOICE' }}</h1>
      <div><strong>{{ $inv->invoice_no }}</strong></div>
      <div class="muted">Date {{ $inv->issue_date->format('d M Y') }}</div>
      @if($inv->status === 'cancelled')<div class="tag void">Cancelled</div>@endif
    </td>
  </tr>
</table>

<table style="margin-top:14px"><tr>
  <td style="width:50%; padding-left:0">
    <div class="box">
      <div class="muted" style="text-transform:uppercase; font-size:8px; letter-spacing:1px">Bill to</div>
      <div style="font-size:12px"><strong>{{ $inv->bill_name }}</strong></div>
      @if($inv->bill_address)<div class="muted">{{ $inv->bill_address }}</div>@endif
      @if($inv->customer->phone)<div class="muted">{{ $inv->customer->phone }}</div>@endif
      @if($inv->customer->email)<div class="muted">{{ $inv->customer->email }}</div>@endif
      @if($inv->bill_gstin)<div class="muted">GSTIN {{ $inv->bill_gstin }}</div>@endif
    </div>
  </td>
  <td style="width:50%; padding-right:0">
    <div class="box">
      <div class="muted" style="text-transform:uppercase; font-size:8px; letter-spacing:1px">For</div>
      <div style="font-size:12px"><strong>{{ $inv->title }}</strong></div>
      @if($inv->destination)<div>{{ $inv->destination }}</div>@endif
      @if($inv->travel_date)<div class="muted">Travel {{ $inv->travel_date->format('d M Y') }}</div>@endif
      <div class="muted">{{ $inv->adults }} adult{{ $inv->adults == 1 ? '' : 's' }}@if($inv->children), {{ $inv->children }} child{{ $inv->children == 1 ? '' : 'ren' }}@endif</div>
      @if($inv->place_of_supply)<div class="muted">Place of supply: {{ $inv->place_of_supply }}</div>@endif
      @if($inv->sac_code)<div class="muted">SAC {{ $inv->sac_code }}</div>@endif
    </div>
  </td>
</tr></table>

<h2>Charges</h2>
<table class="lines">
  <thead><tr><th style="width:42%">Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">Taxable</th><th class="right">GST</th><th class="right">Amount</th></tr></thead>
  <tbody>
  @foreach($inv->lines as $l)
    <tr>
      <td>{{ $l->description }}</td>
      <td class="right">{{ $pct($l->qty) }}</td>
      <td class="right">{{ $inr($l->unit_price) }}</td>
      <td class="right">{{ $l->discount > 0 ? $inr($l->discount) : '—' }}</td>
      <td class="right">{{ $inr($l->amount) }}</td>
      <td class="right">{{ $pct($l->tax_rate) }}%</td>
      <td class="right">{{ $inr($l->amount + $l->tax) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
<table class="totals" style="width:50%; margin-left:50%; margin-top:8px">
  <tr><td>Subtotal</td><td class="right">{{ $inr($inv->subtotal) }}</td></tr>
  @if($inv->discount_total > 0)<tr><td>Discount</td><td class="right">− {{ $inr($inv->discount_total) }}</td></tr>@endif
  @if($inv->gst_mode === 'cgst_sgst')
    <tr><td>CGST</td><td class="right">{{ $inr($inv->cgst_total) }}</td></tr>
    <tr><td>SGST</td><td class="right">{{ $inr($inv->sgst_total) }}</td></tr>
  @elseif($inv->gst_mode === 'igst')
    <tr><td>IGST</td><td class="right">{{ $inr($inv->igst_total) }}</td></tr>
  @else
    <tr><td>GST</td><td class="right">{{ $inr($inv->tax_total) }}</td></tr>
  @endif
  @if($inv->tcs_total > 0)<tr><td>TCS ({{ $pct($inv->tcs_rate) }}%)</td><td class="right">{{ $inr($inv->tcs_total) }}</td></tr>@endif
  <tr class="grand"><td>Invoice total</td><td class="right">{{ $inr($inv->total) }}</td></tr>
  @if($inv->credited_total > 0)<tr><td>Credit notes</td><td class="right">− {{ $inr($inv->credited_total) }}</td></tr>@endif
  @if($inv->paid_total != 0)<tr><td>Received</td><td class="right">− {{ $inr($inv->paid_total) }}</td></tr>@endif
  <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $inr($inv->balance()) }}</strong></td></tr>
</table>

@if(count($schedule))
  <h2>Payment schedule</h2>
  <table class="lines">
    <thead><tr><th>Payment</th><th>Due date</th><th class="right">Amount</th><th class="right">Received</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($schedule as $s)
      <tr><td>{{ $s['label'] }}</td><td>{{ \Illuminate\Support\Carbon::parse($s['due_date'])->format('d M Y') }}</td><td class="right">{{ $inr($s['amount']) }}</td><td class="right">{{ $inr($s['paid']) }}</td><td>{{ ['paid' => 'Paid', 'partial' => 'Part paid', 'due' => 'Due', 'overdue' => 'Overdue'][$s['status']] ?? '' }}</td></tr>
    @endforeach
    </tbody>
  </table>
@endif

@if($c['bank'])
  <h2>Pay to</h2>
  <div class="pre">{{ $c['bank'] }}</div>
@endif
@if($inv->notes)<h2>Notes</h2><div class="pre">{{ $inv->notes }}</div>@endif
@if($inv->terms)<h2>Terms &amp; conditions</h2><div class="pre muted">{{ $inv->terms }}</div>@endif

<div class="foot">{{ $c['name'] }} · {{ $c['email'] }}@if($c['website']) · {{ $c['website'] }}@endif · Invoice {{ $inv->invoice_no }}</div>
</body>
</html>
