@if ($paginator->hasPages())
<nav aria-label="分页">
    @if ($paginator->onFirstPage())
        <span aria-disabled="true">上一页</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>
    @endif
    @foreach ($elements as $element)
        @if (is_string($element))
            <span>{{ $element }}</span>
        @else
            @foreach ($element as $page => $url)
                @if ($page === $paginator->currentPage())
                    <span aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>
    @else
        <span aria-disabled="true">下一页</span>
    @endif
</nav>
@endif
