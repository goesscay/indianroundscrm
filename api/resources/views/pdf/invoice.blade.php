<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $inv->invoice_no }}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1F2430; line-height: 1.45; margin: 0; }
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; }
  .page { padding: 0 34px; }
  .muted { color: #6B7280; }
  .right { text-align: right; }
  .center { text-align: center; }
  .caps { text-transform: uppercase; letter-spacing: 1px; font-size: 7.5px; color: #8A909C; }
  .topbar { height: 7px; background: #D71920; }
  .title { font-size: 21px; font-weight: bold; letter-spacing: 3px; color: #1F2430; }
  .pill { display: inline-block; border: 1px solid #D71920; color: #D71920; padding: 2px 9px; font-size: 7.5px; letter-spacing: 1.2px; text-transform: uppercase; font-weight: bold; }
  .void { border-color: #B91C1C; background: #B91C1C; color: #fff; }
  .meta td { border: 1px solid #E4E6EB; padding: 7px 10px; }
  .meta .v { font-size: 11px; font-weight: bold; margin-top: 2px; }
  .card { border-left: 3px solid #D71920; background: #F8F8FA; padding: 9px 12px; }
  .card .name { font-size: 12px; font-weight: bold; margin: 2px 0 3px; }
  .lines th { background: #1F2430; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; padding: 7px 8px; text-align: left; }
  .lines td { padding: 8px; border-bottom: 1px solid #ECEEF2; }
  .lines tr.alt td { background: #FAFAFB; }
  .sum td { padding: 4px 10px; }
  .sum .grand td { background: #D71920; color: #fff; font-size: 12px; font-weight: bold; padding: 8px 10px; }
  .sum .bal td { background: #F3F4F6; font-size: 11px; font-weight: bold; padding: 8px 10px; }
  .words { border: 1px solid #E4E6EB; padding: 9px 12px; }
  .h { font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: bold; color: #D71920; margin: 0 0 4px; }
  .pre { white-space: pre-line; }
  .sched td, .sched th { padding: 5px 8px; border-bottom: 1px solid #ECEEF2; font-size: 8.5px; text-align: left; }
  .sched th { background: #F3F4F6; text-transform: uppercase; letter-spacing: .5px; font-size: 7.5px; color: #6B7280; }
  .thanks { font-size: 11px; font-weight: bold; }
  .sign { border-top: 1px solid #1F2430; width: 150px; margin-left: auto; padding-top: 4px; text-align: center; font-size: 8px; }
  .foot { position: fixed; bottom: 0; left: 0; right: 0; }
  .foot .band { background: #1F2430; color: #C9CDD6; text-align: center; padding: 7px 34px; font-size: 8px; letter-spacing: .3px; }
  .foot .band b { color: #F6A6A9; letter-spacing: 1.6px; }
</style>
</head>
<body>
@php($c = config('crm.company'))
@php($inr = fn ($n) => '₹ '.number_format((float) $n, 2, '.', ','))
@php($pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'))
@php($taxed = (float) $inv->tax_total > 0)
@php($docTitle = $taxed ? 'TAX INVOICE' : ($c['gstin'] ? 'BILL OF SUPPLY' : 'INVOICE'))
@php($logo = is_file(resource_path('brand/indian-rounds-logo.png')) ? 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('brand/indian-rounds-logo.png'))) : null)
@php($lastDue = $inv->installments->max(fn ($i) => $i->due_date))
@php($hasDisc = $inv->lines->contains(fn ($l) => $l->discount > 0))

<div class="topbar"></div>
<div class="page">
  <table style="margin-top:18px">
    <tr>
      <td style="width:58%">
        @if($logo)<img src="{{ $logo }}" style="width:205px; height:auto" alt="{{ $c['name'] }}">@else<div style="font-size:18px; font-weight:bold; color:#D71920">{{ $c['name'] }}</div>@endif
        <div class="muted pre" style="margin-top:8px; font-size:8.5px">{{ $c['address'] }}</div>
        <div class="muted" style="font-size:8.5px">
          @if($c['phone'])Mobile: {{ $c['phone'] }}<br>@endif
          @if($c['email'])Email: {{ $c['email'] }}<br>@endif
          @if($c['website'])Web: {{ $c['website'] }}<br>@endif
        </div>
        @if($c['gstin'])<div style="margin-top:3px; font-size:8.5px"><strong>GSTIN:</strong> {{ $c['gstin'] }}</div>@endif
      </td>
      <td class="right">
        <div class="title">{{ $docTitle }}</div>
        <div style="margin-top:6px">@if($inv->status === 'cancelled')<span class="pill void">Cancelled</span>@else<span class="pill">Original for recipient</span>@endif</div>
      </td>
    </tr>
  </table>

  <table class="meta" style="margin-top:16px">
    <tr>
      <td style="width:25%"><div class="caps">Invoice no.</div><div class="v">{{ $inv->invoice_no }}</div></td>
      <td style="width:25%"><div class="caps">Invoice date</div><div class="v">{{ $inv->issue_date->format('d/m/Y') }}</div></td>
      <td style="width:25%"><div class="caps">Due date</div><div class="v">{{ $lastDue ? $lastDue->format('d/m/Y') : '—' }}</div></td>
      <td style="width:25%"><div class="caps">Place of supply</div><div class="v">{{ $inv->place_of_supply ?: '—' }}</div></td>
    </tr>
  </table>

  <table style="margin-top:14px"><tr>
    <td style="width:50%; padding-right:7px">
      <div class="card">
        <div class="caps">Bill to</div>
        <div class="name">{{ $inv->bill_name }}</div>
        @if($inv->bill_address)<div class="muted pre">{{ $inv->bill_address }}</div>@endif
        @if($inv->customer->phone)<div class="muted">Mobile: {{ $inv->customer->phone }}</div>@endif
        @if($inv->customer->email)<div class="muted">{{ $inv->customer->email }}</div>@endif
        @if($inv->bill_gstin)<div class="muted">GSTIN: {{ $inv->bill_gstin }}</div>@endif
      </div>
    </td>
    <td style="width:50%; padding-left:7px">
      <div class="card">
        <div class="caps">Trip details</div>
        <div class="name">{{ $inv->title }}</div>
        @if($inv->destination)<div>{{ $inv->destination }}</div>@endif
        @if($inv->travel_date)<div class="muted">Travel date: {{ $inv->travel_date->format('d/m/Y') }}</div>@endif
        <div class="muted">{{ $inv->adults }} adult{{ $inv->adults == 1 ? '' : 's' }}@if($inv->children), {{ $inv->children }} child{{ $inv->children == 1 ? '' : 'ren' }}@endif @if($inv->deal)· Ref {{ $inv->deal->deal_no }}@endif</div>
        @if($inv->sac_code)<div class="muted">SAC: {{ $inv->sac_code }}</div>@endif
      </div>
    </td>
  </tr></table>

  <table class="lines" style="margin-top:16px">
    <thead>
      <tr>
        <th style="width:6%">S.No.</th>
        <th>Items / Services</th>
        <th class="right" style="width:9%">Qty</th>
        <th class="right" style="width:14%">Rate</th>
        @if($hasDisc)<th class="right" style="width:12%">Discount</th>@endif
        @if($taxed)<th class="right" style="width:8%">GST</th>@endif
        <th class="right" style="width:16%">Amount</th>
      </tr>
    </thead>
    <tbody>
    @foreach($inv->lines as $i => $l)
      <tr class="{{ $i % 2 ? 'alt' : '' }}">
        <td>{{ $i + 1 }}</td>
        <td><strong>{{ $l->description }}</strong></td>
        <td class="right">{{ $pct($l->qty) }}</td>
        <td class="right">{{ $inr($l->unit_price) }}</td>
        @if($hasDisc)<td class="right">{{ $l->discount > 0 ? $inr($l->discount) : '—' }}</td>@endif
        @if($taxed)<td class="right">{{ $pct($l->tax_rate) }}%</td>@endif
        <td class="right"><strong>{{ $inr($l->amount + $l->tax) }}</strong></td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <table style="margin-top:12px">
    <tr>
      <td style="width:52%; padding-right:14px">
        <div class="words">
          <div class="h">Total amount (in words)</div>
          <div style="font-size:10px"><strong>{{ \App\Support\AmountWords::rupees((float) $inv->total) }}</strong></div>
        </div>
        @if($inv->terms)
          <div style="margin-top:12px"><div class="h">Terms &amp; conditions</div><div class="pre muted">{{ $inv->terms }}</div></div>
        @endif
        @if($inv->notes)
          <div style="margin-top:12px"><div class="h">Notes</div><div class="pre muted">{{ $inv->notes }}</div></div>
        @endif
      </td>
      <td style="width:48%">
        <table class="sum">
          <tr><td>Subtotal</td><td class="right">{{ $inr($inv->subtotal) }}</td></tr>
          @if($inv->discount_total > 0)<tr><td>Discount</td><td class="right">− {{ $inr($inv->discount_total) }}</td></tr>@endif
          @if($inv->gst_mode === 'cgst_sgst')
            <tr><td>CGST</td><td class="right">{{ $inr($inv->cgst_total) }}</td></tr>
            <tr><td>SGST</td><td class="right">{{ $inr($inv->sgst_total) }}</td></tr>
          @elseif($inv->gst_mode === 'igst')
            <tr><td>IGST</td><td class="right">{{ $inr($inv->igst_total) }}</td></tr>
          @elseif($taxed)
            <tr><td>GST</td><td class="right">{{ $inr($inv->tax_total) }}</td></tr>
          @endif
          @if($inv->tcs_total > 0)<tr><td>TCS ({{ $pct($inv->tcs_rate) }}%)</td><td class="right">{{ $inr($inv->tcs_total) }}</td></tr>@endif
          <tr class="grand"><td>Total amount</td><td class="right">{{ $inr($inv->total) }}</td></tr>
          @if($inv->credited_total > 0)<tr><td>Credit notes</td><td class="right">− {{ $inr($inv->credited_total) }}</td></tr>@endif
          <tr><td>Received amount</td><td class="right">{{ $inr($inv->paid_total) }}</td></tr>
          <tr class="bal"><td>Balance</td><td class="right">{{ $inr($inv->balance()) }}</td></tr>
          @if(($previousBalance ?? 0) > 0)
            <tr><td>Previous balance</td><td class="right">{{ $inr($previousBalance) }}</td></tr>
            <tr><td><strong>Current balance</strong></td><td class="right"><strong>{{ $inr($inv->balance() + $previousBalance) }}</strong></td></tr>
          @endif
        </table>
      </td>
    </tr>
  </table>

  @if(count($schedule))
    <div style="margin-top:14px">
      <div class="h">Payment schedule</div>
      <table class="sched">
        <thead><tr><th>Payment</th><th>Due date</th><th class="right">Amount</th><th class="right">Received</th><th>Status</th></tr></thead>
        <tbody>
        @foreach($schedule as $s)
          <tr><td>{{ $s['label'] }}</td><td>{{ \Illuminate\Support\Carbon::parse($s['due_date'])->format('d/m/Y') }}</td><td class="right">{{ $inr($s['amount']) }}</td><td class="right">{{ $inr($s['paid']) }}</td><td>{{ ['paid' => 'Paid', 'partial' => 'Part paid', 'due' => 'Due', 'overdue' => 'Overdue'][$s['status']] ?? '' }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($c['bank'])
    <div style="margin-top:14px"><div class="h">Pay to</div><div class="pre">{{ $c['bank'] }}</div></div>
  @endif

  <table style="margin-top:22px">
    <tr>
      <td style="width:60%">
        <div class="thanks">Thank you for travelling with us.</div>
        <div class="muted" style="font-size:8.5px">Create memories. Travel beyond borders.</div>
      </td>
      <td>
        <div style="height:34px"></div>
        <div class="sign">Authorised signatory<br><strong>{{ $c['name'] }}</strong></div>
      </td>
    </tr>
  </table>
</div>

<div class="foot">
  <div class="band"><b>TRAVEL BEYOND BORDERS</b> &nbsp;|&nbsp; {{ $c['website'] }} &nbsp;|&nbsp; {{ $c['email'] }}@if($c['phone']) &nbsp;|&nbsp; {{ $c['phone'] }}@endif &nbsp;|&nbsp; This is a computer-generated invoice.</div>
</div>
</body>
</html>
