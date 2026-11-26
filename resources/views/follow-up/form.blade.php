@extends('bamboo-connector::follow-up.layout')

@section('title', 'A few more details')

@section('content')
    <h1 class="h2">
        {{ $request['greeting_name'] ? 'Hello '.$request['greeting_name'] : 'A few more details' }}
    </h1>

    @if ($request['message'] ?? null)
        <div class="bard mt-4">
            <p>{{ $request['message'] }}</p>
        </div>
    @endif

    @if (session('bamboo_follow_up_error'))
        <div class="mt-6 border border-error bg-error/20 px-8 py-4 text-sm text-error">
            <p>We could not send that just now. Please try again in a moment.</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 border border-error bg-error/20 px-8 py-4 text-sm text-error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url(config('bamboo.follow_up_path', 'follow-up')."/{$id}/{$token}") }}" enctype="multipart/form-data" class="mt-8 space-y-12">
        @csrf

        @foreach ($request['ticket_fields'] ?? [] as $field)
            @include('bamboo-connector::follow-up.field', ['field' => $field, 'name' => "answers[{$field['key']}]"])
        @endforeach

        @foreach ($request['sections'] ?? [] as $section)
            <fieldset>
                <legend class="h3">{{ $section['label'] }}</legend>

                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($section['fields'] as $field)
                        @include('bamboo-connector::follow-up.field', [
                            'field' => $field,
                            'name' => "answers[{$section['key']}][{$field['key']}]",
                            'section' => $section['key'],
                        ])
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <button type="submit" class="inline-flex items-baseline justify-center gap-0.5 rounded-full border-2 border-primary bg-primary px-4 py-1.5 text-white transition-all hover:bg-highlight">
            Send these details
        </button>
    </form>

    <script>
        fetch('{{ url(config('bamboo.follow_up_path', 'follow-up')."/{$id}/{$token}/rendered") }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        })
    </script>
@endsection
