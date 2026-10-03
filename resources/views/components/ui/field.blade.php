{{--
    <x-ui.field name="email" label="ایمیل" type="email" required autofocus hint="..." />
    خطای اعتبارسنجی همان name را خودکار نشان می‌دهد؛ old() خودکار پر می‌شود (به‌جز password).
--}}
@props(['name', 'label' => null, 'type' => 'text', 'hint' => null])
@php
    $hasError = $errors->has($name);
    $value = $type === 'password' ? null : old($name, $attributes->get('value'));
@endphp
<div>
    @if($label)<label for="f-{{ $name }}" class="label">{{ $label }}</label>@endif
    <input id="f-{{ $name }}" type="{{ $type }}" name="{{ $name }}"
           @if($value !== null) value="{{ $value }}" @endif
           @if($hasError) aria-invalid="true" aria-describedby="e-{{ $name }}" @endif
           {{ $attributes->except('value')->merge(['class' => 'input']) }}>
    @if($hint && ! $hasError)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p id="e-{{ $name }}" class="field-error">{{ $message }}</p>@enderror
</div>
