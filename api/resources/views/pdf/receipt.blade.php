<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $r->receipt_no }}</title>
<style>
  @page { margin: 28px 34px 40px 34px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.5; }
  h1 { font-size: 20px; margin: 0; letter-spacing: 1px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 6px 8px; vertical-align: top; }
  .muted { color: #666; }
  .right { text-align: right; }
  .brand { font-size: 16px; font-weight: bold; }
  .box { border: 1px solid #d4d4d4; padding: 10px 12px; margin-top: 16px; }
  .amount { font-size: 22px; font-weight: bold; }
  .kv td { border-bottom: 1px solid #e5e5e5; }
  .tag { display: inline-block; border: 1px solid #b91c1c; color: #b91c1c; padding: 1px 8px; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; margin-top: 4px; }
  .pre { white-space: pre-line; }
  .foot { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; font-size: 8px; color: #888; }
</style>
</head>
<body>
@php($c = config('crm.company'))
@php($inr = fn ($n) => '₹'.number_format((float) $n, 2, '.', ','))
@php($refund = $r->kind === 'refund')
<table>
  <tr>
    <td style="padding-left:0">
      <div class="brand">{{ $c['name'] }}</div>
      @if($c['address'])<div class="muted pre">{{ $c['address'] }}</div>@endif
      <div class="muted">{{ trim($c['email'].($c['phone'] ? '  ·  '.$c['phone'] : '')) }}</div>
      @if($c['gstin'])<div class="muted">GSTIN {{ $c['gstin'] }}</div>@endif
    </td>
    <td class="right" style="padding-right:0">
      <h1>{{ $refund ? 'REFUND VOUCHER' : 'PAYMENT RECEIPT' }}</h1>
      <div><strong>{{ $r->receipt_no }}</strong></div>
      <div class="muted">Date {{ $r->received_date->format('d M Y') }}</div>
      @if($r->status === 'cancelled')<div class="tag">Cancelled</div>@endif
    </td>
  </tr>
</table>

<div class="box">
  <div class="muted" style="text-transform:uppercase; font-size:8px; letter-spacing:1px">{{ $refund ? 'Refunded to' : 'Received from' }}</div>
  <div style="font-size:13px"><strong>{{ $r->customer->name }}</strong></div>
  @if($r->customer->phone)<div class="muted">{{ $r->customer->phone }}</div>@endif
  <div class="amount" style="margin-top:10px">{{ $inr($r->amount) }}</div>
</div>

<table class="kv" style="margin-top:14px">
  @if($r->invoice)<tr><td class="muted" style="width:30%">Against invoice</td><td>{{ $r->invoice->invoice_no }} — {{ $r->invoice->title }}</td></tr>
  @else<tr><td class="muted" style="width:30%">Against</td><td>Advance on account (not yet applied to an invoice)</td></tr>@endif
  <tr><td class="muted">Payment mode</td><td>{{ ucwords(str_replace('_', ' ', $r->mode)) }}</td></tr>
  @if($r->reference)<tr><td class="muted">Reference</td><td>{{ $r->reference }}</td></tr>@endif
  @if($r->notes)<tr><td class="muted">Notes</td><td class="pre">{{ $r->notes }}</td></tr>@endif
</table>

<p class="muted" style="margin-top:24px">This is a computer-generated receipt and does not need a signature.</p>
<div class="foot">{{ $c['name'] }} · {{ $c['email'] }}@if($c['website']) · {{ $c['website'] }}@endif · {{ $r->receipt_no }}</div>
</body>
</html>
