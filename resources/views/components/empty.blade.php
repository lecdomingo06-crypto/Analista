@props(['title'=>'Nothing here yet','text'=>'Check back soon.'])
<div class="empty"><x-icon name="folder"/><h3>{{ $title }}</h3><p>{{ $text }}</p>{{ $slot }}</div>
