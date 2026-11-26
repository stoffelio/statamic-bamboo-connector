@php
    $input = 'w-full h-10 rounded-md border-2 border-white bg-white px-4 py-1.5 text-black focus:border-highlight';
    $wide = in_array($field['type'], ['passport', 'file', 'question'], true) || ($field['input'] ?? null) === 'textarea';
@endphp

<div class="flex flex-col {{ $wide ? 'md:col-span-2' : '' }}">
    @if ($field['type'] === 'passport')
        <div x-data="{ typing: false }">
            <p class="text-sm font-semibold">{{ $field['label'] }}</p>

            @if ($field['instructions'] ?? null)
                <p class="pb-2 text-sm">{{ $field['instructions'] }}</p>
            @endif

            <div x-show="!typing">
                <input type="file" name="files[{{ $field['key'] }}][]" accept="image/*,application/pdf"
                    class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-gray-900 file:mr-4 file:rounded-md file:border-0 file:bg-surface file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700">

                <button type="button" class="mt-3 text-sm underline" x-on:click="typing = true">
                    I would rather type them in
                </button>
            </div>

            <div x-show="typing" x-cloak class="grid gap-4 md:grid-cols-2">
                @foreach ($field['fields'] as $part)
                    @include('bamboo-connector::follow-up.field', [
                        'field' => $part,
                        'name' => "answers[{$section}][{$part['key']}]",
                    ])
                @endforeach

                <button type="button" class="text-left text-sm underline md:col-span-2" x-on:click="typing = false">
                    Upload my passport instead
                </button>
            </div>
        </div>
    @elseif ($field['type'] === 'file')
        <label class="mb-2 text-sm font-semibold" for="{{ $field['key'] }}">{{ $field['label'] }}</label>

        @if ($field['instructions'] ?? null)
            <p class="pb-2 text-sm">{{ $field['instructions'] }}</p>
        @endif

        <input id="{{ $field['key'] }}" type="file" name="files[{{ $field['key'] }}][]{{ ($field['multiple'] ?? false) ? '' : '' }}"
            @if ($field['multiple'] ?? false) multiple @endif
            @if ($field['required'] ?? false) required @endif
            class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-gray-900 file:mr-4 file:rounded-md file:border-0 file:bg-surface file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700">
    @else
        <label class="mb-2 text-sm font-semibold" for="{{ $field['key'] }}">
            {{ $field['label'] }}
            @if ($field['required'] ?? false)<sup aria-label="Required">*</sup>@endif
        </label>

        @if (($field['options'] ?? null))
            <select id="{{ $field['key'] }}" name="{{ $name }}" class="{{ $input }}" @if ($field['required'] ?? false) required @endif>
                <option value="">Please select...</option>
                @foreach ($field['options'] as $value => $label)
                    <option value="{{ $value }}" @selected(old($name, $field['value'] ?? '') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        @elseif (($field['input'] ?? 'text') === 'textarea')
            <textarea id="{{ $field['key'] }}" name="{{ $name }}" rows="5"
                class="w-full rounded-md border-2 border-white bg-white px-4 py-1.5 text-black focus:border-highlight"
                @if ($field['required'] ?? false) required @endif>{{ old($name, $field['value'] ?? '') }}</textarea>
        @else
            <input id="{{ $field['key'] }}" type="{{ ($field['input'] ?? 'text') === 'date' ? 'date' : (($field['input'] ?? '') === 'email' ? 'email' : 'text') }}"
                name="{{ $name }}" value="{{ old($name, $field['value'] ?? '') }}" class="{{ $input }}"
                @if ($field['required'] ?? false) required @endif>
        @endif
    @endif
</div>
