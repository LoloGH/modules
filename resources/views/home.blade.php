@extends('finance::layout')

@section('content')
    <div class="card">
        <h1>{{ __('finance::messages.home.title') }}</h1>
        <p class="muted">{{ $facility }} — {{ __('finance::messages.home.subtitle') }}</p>

        @canany(['finance.sessions.view', 'finance.sessions.validate', 'finance.registers.manage'])
            <ul>
                @can('finance.sessions.view')<li><a href="{{ route('finance.cash.index') }}">Ma caisse</a> : ouvrir ma session, encaisser, clôturer.</li>@endcan
                @can('finance.sessions.validate')<li><a href="{{ route('finance.review.index') }}">Sessions à valider</a> : contrôler les clôtures et les écarts.</li>@endcan
                @can('finance.registers.manage')<li><a href="{{ route('finance.registers.index') }}">Caisses</a> : créer, activer ou désactiver une caisse.</li>@endcan
            </ul>
        @else
            <p>{{ __('finance::messages.home.placeholder') }}</p>
        @endcanany
    </div>
@endsection
