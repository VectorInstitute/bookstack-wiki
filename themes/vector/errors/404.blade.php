{{--
    Overrides BookStack's resources/views/errors/404.blade.php (v26.05.4).

    Public viewing is enabled, so a guest opening a restricted page gets a 404
    rather than a login redirect (BookStack hides whether the page exists).
    For guests, say plainly that they need to log in instead of "not found".
    Logged-in users still see the stock message.

    Re-check against the upstream view when bumping the BookStack image.
--}}
@extends('layouts.simple')
@inject('popular', \BookStack\Entities\Queries\QueryPopular::class)
@section('content')
    <div class="container mt-l">

        <div class="card mb-xl px-l pb-l pt-l">
            <div class="grid half v-center">
                <div>
                    @if(user()->isGuest())
                        @include('errors.parts.not-found-text', [
                            'title' => 'Log in to view this page',
                            'subtitle' => 'This page is only visible to signed-in users.',
                            'details' => 'Log in with your Vector Institute account to continue. If you still can\'t see it after logging in, it may have been moved or deleted, or your account may not have access.',
                        ])
                    @else
                        @include('errors.parts.not-found-text', [
                            'title' => $message ?? trans('errors.404_page_not_found'),
                            'subtitle' => $subtitle ?? trans('errors.sorry_page_not_found'),
                            'details' => $details ?? trans('errors.sorry_page_not_found_permission_warning'),
                        ])
                    @endif
                </div>
                <div class="text-right">
                    @if(user()->isGuest())
                        <a href="{{ url('/login') }}" class="button">{{ trans('auth.log_in') }}</a>
                    @endif
                    <a href="{{ url('/') }}" class="button outline">{{ trans('errors.return_home') }}</a>
                </div>
            </div>

        </div>

        @if (setting('app-public') || !user()->isGuest())
            <div class="grid third gap-xxl">
                <div>
                    <div class="card mb-xl">
                        <h3 class="card-title">{{ trans('entities.pages_popular') }}</h3>
                        <div class="px-m">
                            @include('entities.list', ['entities' => $popular->run(10, 0, ['page']), 'style' => 'compact'])
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card mb-xl">
                        <h3 class="card-title">{{ trans('entities.books_popular') }}</h3>
                        <div class="px-m">
                            @include('entities.list', ['entities' => $popular->run(10, 0, ['book']), 'style' => 'compact'])
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card mb-xl">
                        <h3 class="card-title">{{ trans('entities.chapters_popular') }}</h3>
                        <div class="px-m">
                            @include('entities.list', ['entities' => $popular->run(10, 0, ['chapter']), 'style' => 'compact'])
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

@stop
