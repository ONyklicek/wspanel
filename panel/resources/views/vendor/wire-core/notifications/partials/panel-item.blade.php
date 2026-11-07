{{-- One notification's text. Its own partial because the row renders it inside an
     anchor when the notification has a page and bare when it does not, and the
     alternative — the same six lines written twice — is the copy that drifts.

     The title carries the weight when there is one; without it the message is
     the heading, rather than a muted line under nothing. --}}
@if($item['title'])
    <p class="truncate pr-6 text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
@endif

@if($item['message'] !== '')
    <p @class([
        'text-sm',
        'text-gray-600 dark:text-gray-300' => $item['title'],
        'pr-6 font-medium text-gray-900 dark:text-white' => ! $item['title'],
    ])>{{ $item['message'] }}</p>
@endif

<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['when'] }}</p>
