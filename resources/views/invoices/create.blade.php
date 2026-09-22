@extends('finance::layout')

@section('title', 'Nouvelle facture')

@section('content')
    <a class="back" href="{{ route('finance.invoices.index') }}"><x-finance::icon name="retour" /> Retour aux factures</a>

    <x-finance::page title="Nouvelle facture" sub="Les actes sont facturés à leur tarif standard du jour." />

    <form method="post" action="{{ route('finance.invoices.store') }}" data-invoice-form>
        @csrf
        <div class="cols wide">
            <x-finance::card title="Lignes de la facture" flush>
                @if ($acts->isEmpty())
                    <div class="bd">
                        <x-finance::empty title="Aucun acte tarifé" icon="acte">
                            Le catalogue n'a encore aucun acte avec un tarif : rien ne peut être facturé.
                        </x-finance::empty>
                    </div>
                @else
                    <div class="tw">
                        <table class="stack">
                            <thead><tr><th>Acte ou prestation</th><th style="width:7rem">Quantité</th><th class="num" style="width:9rem">Montant</th></tr></thead>
                            <tbody>
                            @for ($i = 0; $i < $rows; $i++)
                                <tr>
                                    <td data-l="Acte">
                                        <select name="lines[{{ $i }}][act_id]" aria-label="Acte de la ligne {{ $i + 1 }}">
                                            <option value="">—</option>
                                            @foreach ($acts->groupBy(fn ($act) => $act->center?->name ?? 'Sans centre analytique') as $centre => $group)
                                                <optgroup label="{{ $centre }}">
                                                    @foreach ($group as $act)
                                                        <option value="{{ $act->id }}" data-amount="{{ (int) $act->standardTariff->amount }}"
                                                                @selected((string) old("lines.$i.act_id") === (string) $act->id)>
                                                            {{ $act->name }} — {{ $money((int) $act->standardTariff->amount) }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td data-l="Quantité">
                                        <input name="lines[{{ $i }}][quantity]" inputmode="numeric" value="{{ old("lines.$i.quantity", 1) }}" aria-label="Quantité de la ligne {{ $i + 1 }}">
                                    </td>
                                    <td data-l="Montant" class="num" data-line-total>—</td>
                                </tr>
                            @endfor
                            </tbody>
                        </table>
                    </div>
                    <div class="bd">
                        <p class="strong" style="margin:0">Total : <span data-invoice-total>—</span></p>
                    </div>
                @endif
            </x-finance::card>

            <x-finance::card title="Patient">
                <label>Identifiant du patient
                    <input name="patient_id" value="{{ old('patient_id') }}" placeholder="ex. PAT-00001">
                </label>
                <label>Nom du patient
                    <input name="patient_name" value="{{ old('patient_name') }}">
                </label>
                <label>Note
                    <input name="note" value="{{ old('note') }}" placeholder="Facultatif">
                </label>
                <div class="actions">
                    <button type="submit" class="lg" @disabled($acts->isEmpty())><x-finance::icon name="facture" /> Émettre la facture</button>
                </div>
            </x-finance::card>
        </div>
    </form>

    <script>
        (() => {
            const form = document.querySelector('[data-invoice-form]');
            if (!form) return;
            const fmt = (n) => n.toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' ') + ' FCFA';
            const sync = () => {
                let total = 0;
                form.querySelectorAll('tbody tr').forEach((row) => {
                    const option = row.querySelector('select').selectedOptions[0];
                    const qty = parseInt(row.querySelector('input').value, 10) || 0;
                    const amount = option && option.dataset.amount ? parseInt(option.dataset.amount, 10) * qty : 0;
                    row.querySelector('[data-line-total]').textContent = amount ? fmt(amount) : '—';
                    total += amount;
                });
                form.querySelector('[data-invoice-total]').textContent = fmt(total);
            };
            form.addEventListener('input', sync);
            form.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
