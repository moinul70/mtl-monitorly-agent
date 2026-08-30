<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1a1a1a; line-height: 1.5; }
        .wrap { max-width: 480px; }
        h2 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 13px; margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        td { padding: 8px 12px; font-size: 14px; border-bottom: 1px solid #eee; }
        td:first-child { color: #666; }
        td:last-child { text-align: right; font-weight: 600; }
        .note { background: #f7f7f7; border-radius: 6px; padding: 12px 14px; font-size: 13px; color: #555; margin-top: 16px; }
        .footer { font-size: 12px; color: #999; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2>⚠️ {{ $count }} vulnerable request(s) detected</h2>
        <p class="meta"></p>

        <table>
            <tr><td>Status code &ge; {{ $thresholds['status_code'] }}</td><td>{{ $breakdown['status'] }}</td></tr>
            <tr><td>Response time &gt; {{ $thresholds['response_ms'] }}ms</td><td>{{ $breakdown['slow'] }}</td></tr>
            <tr><td>Peak memory &gt; {{ $thresholds['peak_memory_mb'] }}MB</td><td>{{ $breakdown['memory'] }}</td></tr>
        </table>

        <p class="note">
            Full details for all {{ $count }} request(s) — including path, IP, and user agent —
            are attached as a CSV file.
        </p>

        <p class="footer">Sent automatically by Monitoring Agent.</p>
    </div>
</body>
</html>
