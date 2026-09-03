<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 42px 42px 50px; }
        * { box-sizing: border-box; }
        body { color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.45; }
        h1 { color: #102a43; font-size: 22px; margin: 0 0 3px; }
        h2 { border-bottom: 1px solid #d9e2ec; color: #102a43; font-size: 12px; margin: 18px 0 7px; padding-bottom: 4px; }
        p { margin: 0 0 5px; }
        .subtitle { color: #627d98; font-size: 10px; margin-bottom: 15px; }
        .bar { background: #0f766e; height: 5px; margin-bottom: 18px; width: 100%; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border-bottom: 1px solid #e6edf3; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f4f7fa; color: #486581; font-size: 8px; text-transform: uppercase; }
        .key { color: #627d98; width: 27%; }
        .value { font-weight: 600; }
        .money-total td { background: #edf8f7; color: #0b5f59; font-weight: 700; }
        .id { color: #486581; font-family: "DejaVu Sans Mono", monospace; font-size: 7.5px; word-break: break-all; }
        .notice { background: #f4f7fa; border-left: 3px solid #0f766e; color: #486581; margin-top: 18px; padding: 8px 10px; }
        .footer { bottom: -32px; color: #829ab1; font-size: 7px; left: 0; position: fixed; }
        .avoid { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="footer">Procura - {{ $report['labels']['title'] }}</div>
    <div class="bar"></div>
    <h1>{{ $report['labels']['title'] }}</h1>
    <p class="subtitle">{{ $report['labels']['subtitle'] }}</p>

    <section class="avoid">
        <h2>{{ $report['labels']['report_details'] }}</h2>
        <table>
            <tr><td class="key">{{ $report['labels']['report_version'] }}</td><td class="value">{{ $report['meta']['version'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['locale'] }}</td><td class="value">{{ $report['meta']['locale'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['generated_at'] }}</td><td class="value">{{ $report['meta']['generated_at'] }}</td></tr>
        </table>
    </section>

    <section>
        <h2>{{ $report['labels']['request'] }}</h2>
        <table>
            <tr><td class="key">{{ $report['labels']['request_id'] }}</td><td class="id">{{ $report['request']['id'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['description'] }}</td><td>{{ $report['request']['title'] }}<br>{{ $report['request']['product_description'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['brand_model'] }}</td><td>{{ $report['request']['brand_model'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['condition'] }}</td><td>{{ $report['request']['condition'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['quantity'] }}</td><td>{{ $report['request']['quantity'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['budget'] }}</td><td>{{ $report['request']['budget'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['target_countries'] }}</td><td>{{ $report['request']['countries'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['needed_by'] }}</td><td>{{ $report['request']['needed_by_display'] }}</td></tr>
        </table>
    </section>

    <section>
        <h2>{{ $report['labels']['accepted_offer'] }}</h2>
        <table>
            <tr><td class="key">{{ $report['labels']['supplier'] }}</td><td>{{ $report['offer']['supplier_display_name'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['item'] }}</td><td>{{ $report['offer']['item_description'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['condition'] }}</td><td>{{ $report['offer']['condition_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['quantity'] }}</td><td>{{ $report['offer']['quantity'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['unit_price'] }}</td><td>{{ $report['offer']['unit_price'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['subtotal'] }}</td><td>{{ $report['offer']['subtotal'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['shipping'] }}</td><td>{{ $report['offer']['shipping'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['tax_and_duty'] }}</td><td>{{ $report['offer']['tax'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['other_costs'] }}</td><td>{{ $report['offer']['other'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['supplier_total'] }}</td><td>{{ $report['offer']['total'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['commission'] }}</td><td>{{ $report['offer']['commission'] }}</td></tr>
            <tr class="money-total"><td>{{ $report['labels']['payable_total'] }}</td><td>{{ $report['offer']['payable'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['origin'] }}</td><td>{{ $report['offer']['origin'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['delivery'] }}</td><td>{{ $report['offer']['delivery'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['warranty'] }}</td><td>{{ $report['offer']['warranty'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['return_policy'] }}</td><td>{{ $report['offer']['return_policy'] }}</td></tr>
        </table>
    </section>

    <section>
        <h2>{{ $report['labels']['transaction'] }}</h2>
        <table>
            <tr><td class="key">{{ $report['labels']['transaction_id'] }}</td><td class="id">{{ $report['transaction']['id'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['status'] }}</td><td>{{ $report['transaction']['status_display'] }}</td></tr>
        </table>
        <h2>{{ $report['labels']['timeline'] }}</h2>
        <table>
            <thead><tr><th>#</th><th>{{ $report['labels']['event'] }}</th><th>{{ $report['labels']['status'] }}</th><th>{{ $report['labels']['occurred_at'] }}</th></tr></thead>
            <tbody>
            @foreach ($report['transaction']['timeline'] as $event)
                <tr><td>{{ $event['sequence'] }}</td><td>{{ $event['event_display'] }}</td><td>{{ $event['status_display'] }}</td><td>{{ $event['occurred_display'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="avoid">
        <h2>{{ $report['labels']['commission_details'] }}</h2>
        <table>
            <tr><td class="key">{{ $report['labels']['status'] }}</td><td>{{ $report['commission']['status_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['rule'] }}</td><td>{{ $report['commission']['rule_version'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['rate'] }}</td><td>{{ $report['commission']['rate_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['base'] }}</td><td>{{ $report['commission']['base_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['amount'] }}</td><td>{{ $report['commission']['amount_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['recorded_at'] }}</td><td>{{ $report['commission']['recorded_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['earned_at'] }}</td><td>{{ $report['commission']['earned_display'] }}</td></tr>
            <tr><td class="key">{{ $report['labels']['settled_at'] }}</td><td>{{ $report['commission']['settled_display'] }}</td></tr>
        </table>
    </section>

    <p class="notice">{{ $report['labels']['integrity_notice'] }}</p>
</body>
</html>
