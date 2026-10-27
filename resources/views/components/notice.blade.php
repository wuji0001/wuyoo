@props(['type' => 'info'])
<aside {{ $attributes->merge(['class' => 'notice notice-'.$type]) }}>{{ $slot }}</aside>
