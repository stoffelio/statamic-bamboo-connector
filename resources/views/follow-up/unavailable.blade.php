@extends('bamboo-connector::follow-up.layout')

@section('title', 'This form is no longer available')

@section('content')
    <h1 class="h2">
        @if ($state === 'submitted')
            Thank you — we have your details
        @else
            This form is no longer available
        @endif
    </h1>

    <div class="bard mt-4">
        @if ($state === 'submitted')
            <p>You have already sent these details, so there is nothing more to do here. We will be in touch about the rest.</p>
        @else
            <p>This link cannot be used any more. If you still need to send us your details, reply to our email and we will send you a new one.</p>
        @endif
    </div>
@endsection
