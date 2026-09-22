@extends('finance::layout')

@section('title', 'Utilisateurs')

@section('content')
    <x-finance::page title="Utilisateurs"
                     sub="Ce que chacun peut faire dans Finance. L'application hôte décide qui entre dans le module ; les capacités se règlent ici, sans rien changer chez elle." />

    <x-finance::card title="Capacités dans Finance" hint="{{ count($users) }} utilisateur(s)">
        <p class="muted">
            Tant que rien n'est réglé ici, un utilisateur garde les droits que lui donnent
            ses rôles dans l'application hôte : ils sont cochés comme point de départ.
            Une fois enregistrées, ses capacités sont exactement celles cochées : ce qui
            ne l'est pas lui est refusé, quels que soient ses rôles.
        </p>

        @if ($users === [])
            <x-finance::empty title="Aucun utilisateur" icon="utilisateur">
                L'application hôte ne donne accès au module à aucun membre du personnel.
            </x-finance::empty>
        @endif
    </x-finance::card>

    @foreach ($users as $user)
        @php($formId = 'capacites-'.$loop->index)
        @php($checked = old('user_id') === $user['id'] ? (array) old('permissions', []) : $user['permissions'])

        <x-finance::card :title="$user['name']" class="user-perms">
            <x-slot:actions>
                @if ($user['configured'])
                    <span class="badge info">Réglé dans Finance</span>
                @else
                    <span class="badge muted">Rôles de l'application hôte</span>
                @endif
                @unless ($user['declared'])
                    <span class="badge warn">N'a plus accès au module</span>
                @endunless
            </x-slot:actions>

            <p class="sub">
                @if ($user['function']){{ $user['function'] }} · @endif
                {{ count($user['permissions']) }} capacité(s)
            </p>

            @if ($user['self'])
                <p class="muted">Vous ne pouvez pas régler vos propres capacités.</p>
            @else
                <details @if (old('user_id') === $user['id']) open @endif>
                    <summary>Régler les capacités</summary>

                    <form id="{{ $formId }}" method="post" action="{{ route('finance.users.permissions.store') }}" data-permissions>
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $user['id'] }}">

                        <div class="switch">
                            <span class="lbl">Partir du modèle :</span>
                            @foreach ($templates as $label => $permissions)
                                <button type="button" class="ghost sm" data-template='@json($permissions)'>{{ $label }}</button>
                            @endforeach
                            <button type="button" class="ghost sm" data-template="[]">Aucune</button>
                        </div>

                        <div class="perm-groups">
                            @foreach ($groups as $group => $permissions)
                                <fieldset>
                                    <legend>{{ $group }}</legend>
                                    @foreach ($permissions as $permission => $label)
                                        <label>
                                            <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                                                   @checked(in_array($permission, $checked, true))>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endforeach
                        </div>

                        <div class="actions">
                            <button type="submit"><x-finance::icon name="check" /> Enregistrer</button>
                        </div>
                    </form>

                    @if ($user['configured'])
                        <form method="post" action="{{ route('finance.users.permissions.reset') }}" class="inline">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $user['id'] }}">
                            <button type="submit" class="ghost sm">Revenir aux rôles de l'application hôte</button>
                        </form>
                    @endif
                </details>
            @endif
        </x-finance::card>
    @endforeach

    {{--
        Les modèles cochent d'un coup les permissions de départ d'un rôle du
        module. La page reste utilisable sans ce script : on coche à la main.
    --}}
    <script>
        (function () {
            'use strict';

            document.querySelectorAll('form[data-permissions]').forEach(function (form) {
                form.querySelectorAll('button[data-template]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var wanted = JSON.parse(button.dataset.template || '[]');

                        form.querySelectorAll('input[name="permissions[]"]').forEach(function (box) {
                            box.checked = wanted.indexOf(box.value) !== -1;
                        });
                    });
                });
            });
        })();
    </script>
@endsection
