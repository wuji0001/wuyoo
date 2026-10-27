@extends('layouts.app')
@section('title', $title)
@section('content')
    <h1>{{ $message }}</h1>
    <p>轻量 PHP MVC · Eloquent · 原版 Blade</p>
    <p><a href="/api/health">API 状态</a></p>
@endsection
