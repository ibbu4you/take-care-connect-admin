{{--
  | A proposal as a document: what the buyer opens from the WhatsApp link, and
  | what the team prints to PDF.
  |
  | Deliberately standalone — plain HTML and inline CSS, no app bundle — so it
  | renders the same on a cheap phone, in a print dialog, and if the app's own
  | assets are mid-deploy. "Save as PDF" in any browser's print dialog produces
  | the file, which avoids shipping a PDF library to a shared host.
--}}
@php
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $client = $proposal->deal?->client;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $proposal->code }} · {{ $proposal->title }} · Take Care Connect</title>
    <style>
        @font-face { font-family: 'Inter'; font-weight: 400; src: url('/fonts/inter/inter-latin-400-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Inter'; font-weight: 600; src: url('/fonts/inter/inter-latin-600-normal.woff2') format('woff2'); }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7fa; color: #0e121b; font: 14px/1.55 Inter, system-ui, sans-serif; }
        .page { max-width: 820px; margin: 32px auto; background: #fff; border: 1px solid #e1e4ea; border-radius: 16px; padding: 48px; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding-bottom: 24px; border-bottom: 1px solid #e1e4ea; }
        .brand { display: flex; gap: 12px; align-items: center; }
        .brand img { width: 52px; height: 52px; }
        .brand strong { display: block; font-size: 16px; }
        .muted { color: #525866; }
        .small { font-size: 12px; }
        h1 { font-size: 22px; font-weight: 600; margin: 32px 0 4px; letter-spacing: -0.01em; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin: 24px 0; }
        .meta div { background: #f5f7fa; border-radius: 10px; padding: 12px 14px; }
        .label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #525866; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; font-size: 12px; font-weight: 600; color: #525866; background: #f5f7fa; padding: 10px 12px; }
        td { padding: 12px; border-bottom: 1px solid #e1e4ea; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .totals { margin-left: auto; width: 300px; margin-top: 16px; }
        .totals div { display: flex; justify-content: space-between; padding: 6px 0; }
        .totals .grand { border-top: 2px solid #0e121b; margin-top: 6px; padding-top: 10px; font-size: 18px; font-weight: 600; }
        .terms { margin-top: 32px; white-space: pre-line; }
        footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e1e4ea; display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
        .print { position: fixed; right: 24px; bottom: 24px; background: #335cff; color: #fff; border: 0; border-radius: 10px; padding: 12px 18px; font: 600 14px Inter, system-ui, sans-serif; cursor: pointer; box-shadow: 0 8px 24px rgba(51, 92, 255, .3); }
        @media (max-width: 640px) { .page { margin: 0; border-radius: 0; border: 0; padding: 24px; } header { flex-direction: column; } .totals { width: 100%; } }
        @media print { body { background: #fff; } .page { margin: 0; border: 0; border-radius: 0; padding: 0; max-width: none; } .print { display: none; } }
    </style>
</head>
<body>
    <main class="page">
        <header>
            <div class="brand">
                <img src="/img/logo.webp" alt="">
                <div>
                    <strong>Take Care Connect</strong>
                    <span class="muted small">A Take Care International Foundation initiative · takecareconnect.com</span>
                </div>
            </div>
            <div style="text-align: right">
                <span class="label">Proposal</span>
                <strong>{{ $proposal->code }}</strong>
                @if ($proposal->version > 1)
                    <span class="muted small">· version {{ $proposal->version }}</span>
                @endif
            </div>
        </header>

        <h1>{{ $proposal->title }}</h1>
        @if ($client)
            <p class="muted" style="margin: 0">Prepared for <strong style="color:#0e121b">{{ $client->name }}</strong>@if ($client->contact_person), attention {{ $client->contact_person }}@endif</p>
        @endif

        <section class="meta">
            <div><span class="label">Date</span>{{ ($proposal->sent_at ?? $proposal->updated_at)?->format('j M Y') }}</div>
            <div><span class="label">Valid until</span>{{ $proposal->valid_until?->format('j M Y') ?? '—' }}</div>
            <div><span class="label">Total</span><strong>{{ $money($proposal->total) }}</strong></div>
        </section>

        <table>
            <thead>
                <tr><th>What is included</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($proposal->items as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.') }}</td>
                        <td class="num">{{ $money($item['rate']) }}</td>
                        <td class="num">{{ $money($item['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <div><span class="muted">Subtotal</span><span class="num">{{ $money($proposal->subtotal) }}</span></div>
            @if ((float) $proposal->discount_amount > 0)
                <div><span class="muted">Discount ({{ rtrim(rtrim(number_format((float) $proposal->discount_percent, 2), '0'), '.') }}%)</span><span class="num">−{{ $money($proposal->discount_amount) }}</span></div>
            @endif
            <div class="grand"><span>Total</span><span class="num">{{ $money($proposal->total) }}</span></div>
        </div>

        @if ($proposal->terms)
            <section class="terms">
                <span class="label">Terms</span>
                <p style="margin: 6px 0 0">{{ $proposal->terms }}</p>
            </section>
        @endif

        <footer>
            <div>
                <span class="label">Prepared by</span>
                {{ $proposal->creator?->name ?? 'Take Care Connect' }}
                @if ($proposal->creator?->email)
                    <span class="muted small">· {{ $proposal->creator->email }}</span>
                @endif
            </div>
            <div class="muted small" style="max-width: 360px">
                Take Care Connect is a non-profit that tells the stories of small businesses. Reply to the person who sent you this link with any question.
            </div>
        </footer>
    </main>

    <button type="button" class="print" onclick="window.print()">Print / Save as PDF</button>
</body>
</html>
