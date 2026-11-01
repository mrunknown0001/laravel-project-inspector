<div>
    {!! csrf_field() !!}
    {!! $comment->body !!}
    <p>You searched for {!! request('q') !!}</p>
    {{ $comment->author }}
</div>
