<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #1a1a1a; }
        table { border-collapse: collapse; width: 100%; margin-top: 16px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e5e5; font-size: 13px; }
        th { background: #f5f5f5; font-weight: 600; }
        .bad { color: #d33; font-weight: 600; }
        .meta { color: #666; font-size: 13px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <h2>{{ $count }} vulnerable request(s) detected</h2>

    <p class="meta">
        Thresholds — status code &ge; {{ $thresholds['status_code'] }},
        response time &gt; {{ $thresholds['response_ms'] }}ms,
        peak memory &gt; {{ $thresholds['peak_memory_mb'] }}MB
    </p>

    <table>
        <thead>
            <tr>
                <th>Project</th>
                <th>Method</th>
                <th>Path</th>
                <th>Status</th>
                <th>Response</th>
                <th>Peak Mem</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->project_name }}</td>
                    <td>{{ $row->method }}</td>
                    <td>{{ $row->path }}</td>
                    <td class="{{ $row->status_code >= $thresholds['status_code'] ? 'bad' : '' }}">{{ $row->status_code }}</td>
                    <td class="{{ $row->response_ms > $thresholds['response_ms'] ? 'bad' : '' }}">{{ $row->response_ms }}ms</td>
                    <td class="{{ $row->peak_memory_mb > $thresholds['peak_memory_mb'] ? 'bad' : '' }}">{{ $row->peak_memory_mb }}MB</td>
                    <td>{{ $row->created_at }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
