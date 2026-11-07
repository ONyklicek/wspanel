{{-- The trail above a page, from NyonCode\WireCore\Foundation\View\Breadcrumbs.

     A crumb with a URL is a link; the last one is the page you are on, and is
     marked `aria-current` rather than linked — a link to the page you are
     already on is noise for everyone and a trap for a screen reader. --}}
<nav aria-label="{{ __('wire-core::messages.breadcrumbs') }}" data-testid="breadcrumbs" @wireEl('breadcrumbs') class="mb-3">
    <ol class="flex flex-wrap items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
        @foreach ($crumbs as $crumb)
            <li class="flex items-center gap-1" data-testid="breadcrumb" @wireEl('breadcrumb')>
                @if ($crumb->getUrl() && ! $loop->last)
                    <a href="{{ $crumb->getUrl() }}" @wireNavigate($crumb->getUrl()) class="hover:text-gray-700 hover:underline dark:hover:text-gray-200">
                        {{ $crumb->getLabel() }}
                    </a>
                @else
                    <span @if ($loop->last) aria-current="page" class="text-gray-700 dark:text-gray-200" @endif>
                        {{ $crumb->getLabel() }}
                    </span>
                @endif

                @unless ($loop->last)
                    <span aria-hidden="true" class="text-gray-300 dark:text-gray-600">/</span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
