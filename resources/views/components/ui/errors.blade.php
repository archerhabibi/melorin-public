{{-- خلاصه‌ی خطاهای فرم؛ جایگزین بلوک تکراری @if($errors->any()) در Viewها --}}
@if($errors->any())
    <x-ui.alert type="danger" {{ $attributes }}>
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </x-ui.alert>
@endif
