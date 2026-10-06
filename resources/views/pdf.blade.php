<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; }
        h1 { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 2px 4px; text-align: left; }
        th { background: #eee; }
        td.num { text-align: right; }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>
<table>
    <thead>
    <tr>
        @foreach ($headings as $heading)
            <th>{{ $heading }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            @foreach ($row as $i => $cell)
                <td @class(['num' => ($numeric[$i] ?? false)])>{{ $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
