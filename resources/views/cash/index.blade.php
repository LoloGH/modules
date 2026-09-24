@extends('finance::layout')

@section('title', 'Caisse')

@section('content')
    <x-finance::page title="Ma caisse" sub="Ouvrez votre session pour encaisser et décaisser." />

    @if ($openSessions->isNotEmpty())
        <x-finance::card title="Mes sessions ouvertes"
                         hint="{{ $openSessions->count() }} caisse(s), {{ $drawers }} tiroir(s) sur {{ $limit }} autorisé(s)" flush>
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Caisse</th><th>Session</th><th>Ouverte le</th><th class="num">Fonds initial</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($openSessions as $item)
                        <tr>
                            <td data-l="Caisse" class="strong">
                                {{ $item->register->name }}
                                @if ($item->isGrouped()) <span class="badge info">Tiroir commun</span> @endif
                            </td>
                            <td data-l="Session" class="mono">{{ $item->number }}</td>
                            <td data-l="Ouverte le">{{ $item->opened_at?->format('d/m/Y H:i') }}</td>
                            <td data-l="Fonds initial" class="num">{{ $money($item->opening_float) }}</td>
                            <td data-l="" class="acts">
                                <a class="btn sm" href="{{ route('finance.cash.sessions.show', $item) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-finance::card>
    @endif

    <div class="cols wide">
        @can('finance.sessions.open')
            <x-finance::card title="Ouvrir ma session" hint="Une session par caisse et par caissier">
                @if (! $canOpenMore)
                    <x-finance::empty title="Limite atteinte" icon="verrou">
                        Vous tenez déjà {{ $drawers }} tiroir(s) ouvert(s) ({{ $openSessions->count() }} caisse(s)) sur les {{ $limit }} autorisé(s).
                        Clôturez-en un pour en ouvrir un autre.
                    </x-finance::empty>
                @elseif ($registers->isEmpty())
                    <x-finance::empty title="Aucune caisse active" icon="caisse">
                        @if ($isRestricted)
                            Les caisses auxquelles vous êtes affecté sont déjà tenues, ou désactivées.
                            Demandez à un administrateur de revoir vos affectations.
                        @else
                            Toutes les caisses actives sont déjà tenues, ou aucune n'a été créée.
                            Demandez à un administrateur d'en créer une avant d'ouvrir votre session.
                        @endif
                    </x-finance::empty>
                @elseif ($registers->count() > 1)
                    {{-- Plusieurs caisses libres : les ouvrir ensemble, avec un seul
                         fonds (un tiroir), ou séparément, avec un fonds chacune. --}}
                    @php ($mode = old('mode', 'groupees'))
                    <form method="post" action="{{ route('finance.cash.sessions.open-many') }}" data-open-many>
                        @csrf
                        <fieldset class="checks" style="border:0;padding:0;margin:0 0 .75rem">
                            <legend class="lbl" style="margin-bottom:.375rem">Comment ouvrir vos caisses ?</legend>
                            <label><input type="radio" name="mode" value="groupees" @checked($mode === 'groupees')> Groupées : un seul fonds, un seul tiroir</label>
                            <label><input type="radio" name="mode" value="separees" @checked($mode === 'separees')> Séparées : un fonds par caisse</label>
                        </fieldset>
                        <div class="tw">
                            <table class="stack">
                                <thead><tr><th style="width:3rem"></th><th>Caisse</th><th style="width:12rem" data-separees>Fonds initial</th></tr></thead>
                                <tbody>
                                @foreach ($registers as $register)
                                    @php ($checked = old('registers') === null || in_array((string) $register->id, array_map('strval', (array) old('registers')), true))
                                    <tr>
                                        <td data-l="">
                                            <input type="checkbox" name="registers[]" value="{{ $register->id }}"
                                                   aria-label="Ouvrir {{ $register->name }}" @checked($checked)>
                                        </td>
                                        <td data-l="Caisse" class="strong">{{ $register->name }}</td>
                                        <td data-l="Fonds initial" data-separees>
                                            <input name="floats[{{ $register->id }}]" class="money" inputmode="numeric"
                                                   value="{{ old('floats.'.$register->id, 0) }}" aria-label="Fonds initial de {{ $register->name }}">
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <label data-groupees style="margin-top:.75rem">Fonds initial unique
                            <input name="opening_float" class="money" inputmode="numeric" value="{{ old('opening_float', 0) }}">
                            <span class="help">En FCFA, une seule fois : ce que contient votre tiroir. Il est porté par la première caisse cochée ; chaque caisse se clôture séparément.</span>
                        </label>
                        <p class="help" data-separees>
                            @if ($remaining > 1)
                                Chaque caisse a son tiroir et compte dans votre limite ({{ $remaining }} tiroir(s) encore possible(s)).
                            @else
                                Vous ne pouvez tenir qu'un tiroir : pour des caisses séparées, un administrateur relève votre limite (écran « Caisses »). Groupées, elles n'en font qu'un.
                            @endif
                        </p>
                        <div class="actions">
                            <button type="submit" class="lg"><x-finance::icon name="caisse" /> Ouvrir les caisses cochées</button>
                        </div>
                    </form>
                    <script>
                        (() => {
                            const form = document.querySelector('[data-open-many]');
                            if (!form) return;
                            const sync = () => {
                                const grouped = form.querySelector('input[name=mode]:checked')?.value !== 'separees';
                                form.querySelectorAll('[data-groupees]').forEach((el) => { el.style.display = grouped ? '' : 'none'; });
                                form.querySelectorAll('[data-separees]').forEach((el) => { el.style.display = grouped ? 'none' : ''; });
                            };
                            form.addEventListener('change', sync);
                            sync();
                        })();
                    </script>
                @else
                    <form method="post" action="{{ route('finance.cash.sessions.open') }}">
                        @csrf
                        <div class="row">
                            <label>Caisse
                                <select name="cash_register_id" required>
                                    @foreach ($registers as $register)
                                        <option value="{{ $register->id }}" @selected((string) old('cash_register_id') === (string) $register->id)>{{ $register->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Fonds initial
                                <input name="opening_float" class="money" inputmode="numeric" value="{{ old('opening_float', 0) }}" required>
                                <span class="help">En FCFA, ce que contient le tiroir à l'ouverture.</span>
                            </label>
                        </div>
                        <div class="actions">
                            <button type="submit" class="lg"><x-finance::icon name="caisse" /> Ouvrir la session</button>
                        </div>
                    </form>
                @endif
            </x-finance::card>
        @endcan

        <x-finance::card title="Comment ça marche">
            <dl class="facts">
                <div class="f"><dt>1. Ouverture</dt><dd>Fonds initial</dd></div>
                <div class="f"><dt>2. Journée</dt><dd>Encaissements, décaissements</dd></div>
                <div class="f"><dt>3. Clôture</dt><dd>Comptage et écart</dd></div>
                <div class="f"><dt>4. Contrôle</dt><dd>Validation par un tiers</dd></div>
            </dl>
            <p class="muted" style="margin-bottom:0">
                Seules les espèces entrent dans le tiroir. Le Mobile Money, la carte ou le
                virement sont totalisés à part. Un caissier ne valide jamais sa propre clôture.
            </p>
        </x-finance::card>
    </div>

    <x-finance::card title="Mes dernières sessions" flush>
        @if ($recent->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune session pour l'instant" icon="horloge">
                    Vos sessions passées et leurs écarts s'afficheront ici.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>Session</th><th>Caisse</th><th>Ouverte le</th><th>Statut</th><th class="num">Écart</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($recent as $item)
                        <tr>
                            <td data-l="Session" class="mono">{{ $item->number }}</td>
                            <td data-l="Caisse">{{ $item->register->name }}</td>
                            <td data-l="Ouverte le">{{ $item->opened_at?->format('d/m/Y H:i') }}</td>
                            <td data-l="Statut"><span class="badge {{ $item->status }}">{{ $item->statusLabel() }}</span></td>
                            <td data-l="Écart" class="num">
                                @if ($item->variance === null) -
                                @else <span class="{{ $item->variance < 0 ? 'neg' : ($item->variance > 0 ? 'pos' : 'zero') }}">{{ $item->variance > 0 ? '+' : '' }}{{ $money($item->variance) }}</span>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.cash.sessions.show', $item) }}">Voir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
