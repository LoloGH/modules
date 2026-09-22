{{--
    Mise en page d'impression : sans menu, en-tête de l'établissement, un
    bouton « Imprimer » qui disparaît à l'impression. `?auto=1` lance
    l'impression dès l'ouverture (bouton « Imprimer le reçu » après une
    opération).
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #1d4ed8; --danger: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .toolbar { display: flex; gap: .5rem; justify-content: center; padding: 1rem; }
        .toolbar button, .toolbar a { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
        .toolbar button { background: var(--brand); color: #fff; border-color: var(--brand); }
        .paper { position: relative; background: #fff; margin: 0 auto 2rem; box-shadow: 0 1px 3px rgba(15, 23, 42, .12); }
        .paper.a4 { width: 210mm; min-height: 297mm; padding: 16mm 15mm; }
        .paper.ticket { width: 80mm; padding: 5mm 4mm; font-size: 12px; }
        .head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; border-bottom: 2px solid var(--ink); padding-bottom: .75rem; margin-bottom: 1rem; }
        .ticket .head { display: block; text-align: center; border-bottom: 1px dashed var(--ink); }
        .facility strong { display: block; font-size: 1.15em; }
        .facility span { display: block; color: var(--muted); font-size: .9em; }
        .doc { text-align: right; }
        .ticket .doc { text-align: center; margin-top: .5rem; }
        .doc h1 { margin: 0; font-size: 1.5em; letter-spacing: .02em; }
        .ticket .doc h1 { font-size: 1.15em; }
        .doc .num { font-family: ui-monospace, Consolas, monospace; font-weight: 600; }
        .muted { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .45rem .5rem; text-align: left; vertical-align: top; }
        th { font-size: .8em; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); border-bottom: 1px solid var(--ink); }
        td { border-bottom: 1px solid var(--line); }
        .r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .totals { margin-left: auto; width: 45%; margin-top: 1rem; }
        .totals td { border: 0; padding: .3rem .5rem; }
        .totals tr.grand td { border-top: 2px solid var(--ink); font-weight: 700; font-size: 1.1em; }
        dl.rows { margin: 0; }
        dl.rows div { display: flex; justify-content: space-between; gap: .75rem; padding: .25rem 0; border-bottom: 1px dotted var(--line); }
        dl.rows dt { color: var(--muted); }
        dl.rows dd { margin: 0; text-align: right; font-weight: 600; }
        .amount { text-align: center; font-size: 1.6em; font-weight: 800; margin: .75rem 0; }
        .badge { display: inline-block; padding: .1rem .5rem; border-radius: 1rem; border: 1px solid currentColor; font-size: .8em; font-weight: 700; }
        .stamp { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 3.5em; font-weight: 900; color: rgba(185, 28, 28, .22); border: .12em solid rgba(185, 28, 28, .22); padding: .1em .4em; letter-spacing: .1em; pointer-events: none; }
        .ticket .stamp { font-size: 2.2em; }
        .foot { margin-top: 1.5rem; color: var(--muted); font-size: .85em; text-align: center; }
        .signs { display: flex; gap: 1rem; margin-top: 1.5rem; }
        .signs div { flex: 1; border-top: 1px solid var(--ink); padding-top: .25rem; text-align: center; font-size: .85em; color: var(--muted); min-height: 3rem; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .paper { box-shadow: none; margin: 0; }
            @page { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="@yield('back')">Retour</a>
    </div>

    @yield('paper')

    @if (request()->boolean('auto'))
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
