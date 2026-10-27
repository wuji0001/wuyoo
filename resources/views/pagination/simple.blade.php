@if ($paginator->hasPages())
<nav aria-label="分页">
    @if ($paginator->previousPageUrl())
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>
    @endif
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>
    @endif
</nav>
@endif
