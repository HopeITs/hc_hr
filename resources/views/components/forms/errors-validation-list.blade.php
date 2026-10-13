@if($errors->any())
    <ul class="text-body p-3 text-red-500">
        @foreach($errors->all() as $error)
            <li>{{$error}}</li>
        @endforeach
    </ul>
@endif
