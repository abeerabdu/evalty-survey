<!DOCTYPE html>
<html>
<head>
    <title>Survey Builder</title>

    {{-- @vite([
        'resources/js/survey/app.js'
    ]) --}}
        @if(file_exists(public_path('vendor/survey/survey.css')))
            <link rel="stylesheet" href="{{ asset('vendor/survey/survey.css') }}">
        @endif

        <script
            type="module"
            src="{{ asset('vendor/survey/survey.js') }}">
        </script>
</head>

<body>
<div
    id="survey-app"
    data-surveys='@json($surveys)'
></div>
</body>
</html>
