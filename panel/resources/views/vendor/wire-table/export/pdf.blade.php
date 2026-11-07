<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
        }
        td {
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        tfoot td {
            background-color: #f3f4f6;
            border-top: 2px solid #d1d5db;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table>
        @if(!empty($headings))
            <thead>
                <tr>
                    @foreach($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        @if(!empty($summaryRows ?? []))
            <tfoot>
                @foreach($summaryRows as $summaryRow)
                    <tr>
                        @foreach($summaryRow as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tfoot>
        @endif
    </table>
</body>
</html>
