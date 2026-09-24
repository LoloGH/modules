@extends('pharmacie::layout')

@section('title', 'Utilisateurs')

@section('content')
    <x-pharmacie::page title="Utilisateurs"
        sub="Qui a droit à quoi dans la pharmacie. L'application hôte décide qui entre dans le module ; les capacités se règlent ici, sans rien changer chez elle." />

    <x-pharmacie::card title="Capacités dans la pharmacie" hint="{{ count($users) }} personne(s)">
        <p class="muted">
            Tant que rien n'est réglé ici, une personne garde les droits que lui donnent
            ses rôles et ses capacités dans l'application hôte : ils sont cochés comme
            point de départ. Une fois enregistrées, ses capacités sont exactement celles
            cochées : ce qui ne l'est pas lui est refusé, quels que soient ses rôles.
        </p>

        @unless ($directoryConnected)
            <p class="muted">
                L'application hôte ne déclare pas encore son personnel de pharmacie
                (<code>Contracts\StaffDirectory</code>). Seules les personnes déjà réglées
                ici apparaissent.
            </p>
        @endunless

        @if ($users === [])
            <x-pharmacie::empty title="Aucune personne" icon="utilisateur">
                L'application hôte n'ouvre la pharmacie à personne pour l'instant.
            </x-pharmacie::empty>
        @endif
    </x-pharmacie::card>

    @foreach ($users as $user)
        @php($checked = old('user_id') === $user['id'] ? (array) old('permissions', []) : $user['permissions'])

        <x-pharmacie::card :title="$user['name']" class="user-perms">
            <x-slot:actions>
                @if ($user['configured'])
                    <span class="badge info">Réglé dans la pharmacie</span>
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

                    <form method="post" action="{{ route('pharmacie.users.permissions.store') }}" data-permissions>
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
                            <button type="submit"><x-pharmacie::icon name="check" /> Enregistrer</button>
                        </div>
                    </form>

                    @if ($user['configured'])
                        <form method="post" action="{{ route('pharmacie.users.permissions.reset') }}" class="inline">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $user['id'] }}">
                            <button type="submit" class="ghost sm">Revenir aux rôles de l'application hôte</button>
                        </form>
                    @endif
                </details>
            @endif
        </x-pharmacie::card>
    @endforeach

    {{--
        Les modèles cochent d'un coup les capacités de départ d'un rôle du
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
