{{--
    Design system de Keneya Finance.

    Tout tient dans une feuille en ligne : le module n'a ni Vite, ni Tailwind,
    ni npm, et ne doit pas en introduire. Les couleurs, rayons, ombres et
    espacements sont des variables : on change l'identité à un seul endroit.
--}}
<style>
    :root {
        --brand: #2563eb;
        --brand-dark: #1d4ed8;
        --brand-soft: #eff6ff;
        --brand-ring: #bfdbfe;

        --ink: #0f172a;
        --ink-2: #334155;
        --muted: #64748b;
        --muted-2: #94a3b8;

        --bg: #f6f8fb;
        --surface: #ffffff;
        --line: #e6ebf2;
        --line-soft: #f1f5f9;

        --ok: #15803d;
        --ok-bg: #dcfce7;
        --warn: #b45309;
        --warn-bg: #fef3c7;
        --danger: #b91c1c;
        --danger-bg: #fee2e2;
        --info: #1d4ed8;
        --info-bg: #dbeafe;

        --r-sm: .375rem;
        --r: .625rem;
        --r-lg: .875rem;
        --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 1px 3px rgba(15, 23, 42, .04);
        --shadow-pop: 0 4px 16px rgba(15, 23, 42, .08);

        --sidebar: 15rem;
        --header: 4rem;
    }

    * { box-sizing: border-box; }

    html { -webkit-text-size-adjust: 100%; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--ink);
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 15px;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    a { color: var(--brand); text-decoration: none; }
    a:hover { text-decoration: underline; }

    svg.ic { width: 1.125rem; height: 1.125rem; flex: none; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }

    /* ---------------------------------------------------------- Ossature */

    .shell { display: flex; min-height: 100vh; }

    .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

    .content { flex: 1; width: 100%; max-width: 84rem; margin: 0 auto; padding: 1.5rem; }

    /* ---------------------------------------------------------- Sidebar */

    .sidebar {
        width: var(--sidebar);
        flex: none;
        background: var(--surface);
        border-right: 1px solid var(--line);
        display: flex;
        flex-direction: column;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
    }

    .brand { display: flex; align-items: center; gap: .625rem; padding: 1rem 1.125rem; border-bottom: 1px solid var(--line-soft); }
    .brand:hover { text-decoration: none; }
    .brand .mark { width: 2.25rem; height: 2.25rem; flex: none; }
    .brand .name { font-size: 1.0625rem; font-weight: 700; color: var(--ink); letter-spacing: -.01em; line-height: 1.15; }
    .brand .name em { font-style: normal; color: var(--brand); }
    .brand .tag { display: block; font-size: .6875rem; font-weight: 500; color: var(--muted); letter-spacing: .01em; }

    .nav { padding: .75rem .625rem 1.25rem; display: flex; flex-direction: column; gap: .125rem; }
    .nav .group { margin: 1rem 0 .25rem; padding: 0 .625rem; font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted-2); }

    .nav a, .nav span.soon {
        display: flex; align-items: center; gap: .625rem;
        padding: .5rem .625rem; border-radius: var(--r);
        color: var(--ink-2); font-size: .9063rem; font-weight: 500;
    }
    .nav a:hover { background: var(--line-soft); text-decoration: none; color: var(--ink); }
    .nav a.on { background: var(--brand-soft); color: var(--brand); font-weight: 600; }
    .nav a.on svg.ic { stroke-width: 2; }
    .nav span.soon { color: var(--muted-2); cursor: default; }
    .nav span.soon .chip { margin-left: auto; font-size: .625rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--muted-2); background: var(--line-soft); padding: .0625rem .375rem; border-radius: 1rem; }

    .sidebar .version { margin-top: auto; padding: .875rem 1.25rem; font-size: .75rem; color: var(--muted-2); border-top: 1px solid var(--line-soft); }

    .scrim { display: none; }

    /* ---------------------------------------------------------- Header */

    .topbar {
        position: sticky; top: 0; z-index: 20;
        height: var(--header); flex: none;
        display: flex; align-items: center; gap: .75rem;
        padding: 0 1.5rem;
        background: rgba(255, 255, 255, .88);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid var(--line);
    }

    .burger { display: none; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: var(--r); color: var(--ink-2); cursor: pointer; }
    .burger:hover { background: var(--line-soft); }

    .topbar .spacer { flex: 1; }

    .topbar .bell { position: relative; display: flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: var(--r); color: var(--ink-2); }
    .topbar .bell:hover { background: var(--line-soft); text-decoration: none; }
    .topbar .bell .dot { position: absolute; top: .25rem; right: .25rem; min-width: 1.0625rem; height: 1.0625rem; padding: 0 .25rem; border-radius: 1rem; background: #ef4444; color: #fff; font-size: .625rem; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid var(--surface); }

    .topbar .sep { width: 1px; height: 1.75rem; background: var(--line); }

    .who { display: flex; align-items: center; gap: .625rem; min-width: 0; }
    .who .av { width: 2.25rem; height: 2.25rem; flex: none; border-radius: 50%; background: var(--brand-soft); color: var(--brand); display: flex; align-items: center; justify-content: center; font-size: .8125rem; font-weight: 700; }
    .who .id { min-width: 0; line-height: 1.25; }
    .who .id strong { display: block; font-size: .875rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .who .id small { display: block; font-size: .75rem; color: var(--muted); }

    /* ---------------------------------------------------------- En-tête de page */

    .page { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
    .page .titles { flex: 1; min-width: 12rem; }
    .page h1 { margin: 0; font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; }
    .page p.sub { margin: .25rem 0 0; color: var(--muted); font-size: .9063rem; }
    .page .acts { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }

    .back { display: inline-flex; align-items: center; gap: .375rem; font-size: .875rem; color: var(--muted); margin-bottom: .75rem; }
    .back:hover { color: var(--brand); text-decoration: none; }

    /* ---------------------------------------------------------- Cartes */

    .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--shadow); margin-bottom: 1.25rem; }
    .card > .hd { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--line-soft); }
    .card > .hd h2 { margin: 0; font-size: 1rem; font-weight: 650; flex: 1; min-width: 8rem; }
    .card > .hd .hint { font-size: .8125rem; color: var(--muted); font-weight: 400; }
    .card > .bd { padding: 1.25rem; }
    .card > .bd > :first-child { margin-top: 0; }
    .card > .bd > :last-child { margin-bottom: 0; }
    .card.flat { box-shadow: none; }

    .cols { display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr)); align-items: start; }
    .cols > .card { margin-bottom: 0; }
    .cols.wide { grid-template-columns: minmax(0, 1.9fr) minmax(0, 1fr); }

    /* ---------------------------------------------------------- KPI */

    .kpis { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); margin-bottom: 1.25rem; }

    .kpi { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--shadow); padding: 1.125rem; display: flex; gap: .875rem; align-items: flex-start; }
    .kpi .ico { width: 2.75rem; height: 2.75rem; flex: none; border-radius: var(--r); display: flex; align-items: center; justify-content: center; background: var(--line-soft); color: var(--ink-2); }
    .kpi .ico svg.ic { width: 1.375rem; height: 1.375rem; }
    .kpi .ico.green { background: var(--ok-bg); color: var(--ok); }
    .kpi .ico.red { background: var(--danger-bg); color: var(--danger); }
    .kpi .ico.blue { background: var(--info-bg); color: var(--info); }
    .kpi .ico.violet { background: #ede9fe; color: #6d28d9; }
    .kpi .ico.amber { background: var(--warn-bg); color: var(--warn); }
    .kpi .body { min-width: 0; flex: 1; }
    .kpi .label { font-size: .8125rem; font-weight: 600; color: var(--muted); }
    .kpi .value { margin-top: .125rem; font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.2; word-break: break-word; }
    .kpi .value .cur { font-size: .8125rem; font-weight: 600; color: var(--muted); margin-left: .125rem; }
    .kpi .foot { margin-top: .25rem; font-size: .75rem; color: var(--muted); }
    .kpi.green .value { color: var(--ok); }
    .kpi.red .value { color: var(--danger); }
    .kpi.blue .value { color: var(--info); }
    .kpi.violet .value { color: #6d28d9; }

    /* ---------------------------------------------------------- Badges */

    .badge { display: inline-flex; align-items: center; gap: .25rem; padding: .1875rem .5rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; line-height: 1.25; white-space: nowrap; }
    .badge.open, .badge.ok { background: var(--ok-bg); color: var(--ok); }
    .badge.closed, .badge.warn { background: var(--warn-bg); color: var(--warn); }
    .badge.validated, .badge.info { background: var(--info-bg); color: var(--info); }
    .badge.off, .badge.muted { background: var(--line-soft); color: var(--muted); }
    .badge.danger { background: var(--danger-bg); color: var(--danger); }
    .badge .pt { width: .4375rem; height: .4375rem; border-radius: 50%; background: currentColor; }

    /* ---------------------------------------------------------- Boutons */

    .btn, button, input[type="submit"] {
        display: inline-flex; align-items: center; justify-content: center; gap: .4375rem;
        padding: .5rem .875rem; border: 1px solid transparent; border-radius: var(--r);
        background: var(--brand); color: #fff;
        font: inherit; font-size: .875rem; font-weight: 600; line-height: 1.25;
        cursor: pointer; white-space: nowrap;
    }
    .btn:hover, button:hover { background: var(--brand-dark); text-decoration: none; color: #fff; }
    .btn:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 2px solid var(--brand-ring); outline-offset: 1px; }

    .btn.ghost, button.ghost, .btn.secondary, button.secondary { background: var(--surface); color: var(--ink-2); border-color: var(--line); }
    .btn.ghost:hover, button.ghost:hover, .btn.secondary:hover, button.secondary:hover { background: var(--line-soft); color: var(--ink); }

    .btn.danger, button.danger { background: var(--surface); color: var(--danger); border-color: #fecaca; }
    .btn.danger:hover, button.danger:hover { background: var(--danger-bg); color: var(--danger); }

    .btn.sm, button.sm { padding: .3125rem .625rem; font-size: .8125rem; }
    .btn.block, button.block { width: 100%; }
    .btn.lg, button.lg { padding: .625rem 1.125rem; font-size: .9375rem; }

    /* ---------------------------------------------------------- Formulaires */

    form .row { display: grid; gap: .875rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
    form .actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: 1rem; }

    label { display: block; font-size: .8125rem; font-weight: 600; color: var(--ink-2); margin-bottom: .875rem; }
    label .help { display: block; font-weight: 400; color: var(--muted); font-size: .75rem; margin-top: .125rem; }
    label > input, label > select, label > textarea { margin-top: .3125rem; }

    input, select, textarea {
        display: block; width: 100%; max-width: 100%;
        padding: .5rem .625rem;
        border: 1px solid var(--line); border-radius: var(--r);
        background: var(--surface); color: var(--ink);
        font: inherit; font-size: .9063rem; font-weight: 400;
    }
    input::placeholder, textarea::placeholder { color: var(--muted-2); }
    input:focus, select:focus, textarea:focus { border-color: var(--brand-ring); }
    textarea { resize: vertical; }
    select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%2364748b' stroke-width='1.6' stroke-linecap='round'%3E%3Cpath d='M4 6.5 8 10.5 12 6.5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .55rem center; background-size: 1rem; padding-right: 2rem; }

    input.money { font-variant-numeric: tabular-nums; }

    form.inline { display: flex; flex-wrap: wrap; gap: .375rem; align-items: center; }
    form.inline input { width: auto; min-width: 9rem; flex: 1; }

    /* ---------------------------------------------------------- Tableaux */

    .tw { width: 100%; overflow-x: auto; }

    table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    /* Un tableau large défile dans sa carte plutôt que d'écraser ses colonnes. */
    table.wide { min-width: 54rem; }
    thead th { text-align: left; padding: .625rem .875rem; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); background: #fbfcfe; border-bottom: 1px solid var(--line); white-space: nowrap; }
    tbody td { padding: .75rem .875rem; border-bottom: 1px solid var(--line-soft); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: 0; }
    tbody tr:hover { background: #fbfcfe; }
    td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    td.strong { font-weight: 600; }
    td .sub { display: block; font-size: .75rem; color: var(--muted); }
    td.acts { text-align: right; white-space: nowrap; }
    td.acts > * { display: inline-flex; vertical-align: middle; }

    .card > .bd > .tw > table, .card > .tw > table { margin: 0; }
    .card > .tw { border-radius: 0 0 var(--r-lg) var(--r-lg); }
    .card > .tw thead th:first-child, .card > .tw tbody td:first-child { padding-left: 1.25rem; }
    .card > .tw thead th:last-child, .card > .tw tbody td:last-child { padding-right: 1.25rem; }

    tr.cancelled td:not(.acts) { color: var(--muted-2); text-decoration: line-through; }
    tr.cancelled td .why { text-decoration: none; }

    .mono { font-variant-numeric: tabular-nums; font-size: .8125rem; color: var(--muted); white-space: nowrap; }

    /* ---------------------------------------------------------- Divers */

    .muted { color: var(--muted); }
    .neg { color: var(--danger); font-weight: 600; }
    .pos { color: var(--warn); font-weight: 600; }
    .zero { color: var(--ok); font-weight: 600; }

    .empty { padding: 2.25rem 1.25rem; text-align: center; color: var(--muted); }
    .empty svg.ic { width: 1.75rem; height: 1.75rem; color: var(--muted-2); margin-bottom: .5rem; }
    .empty p { margin: 0 0 .25rem; font-weight: 600; color: var(--ink-2); }
    .empty small { display: block; margin-bottom: 1rem; }
    .empty small:last-child { margin-bottom: 0; }

    .flash { display: flex; gap: .625rem; align-items: flex-start; padding: .75rem 1rem; border-radius: var(--r); margin-bottom: 1.25rem; font-size: .9063rem; border: 1px solid transparent; }
    .flash svg.ic { margin-top: .125rem; }
    .flash.ok { background: var(--ok-bg); color: var(--ok); border-color: #bbf7d0; }
    .flash.err { background: var(--danger-bg); color: var(--danger); border-color: #fecaca; }
    .flash ul { margin: 0; padding-left: 1.1rem; }

    .facts { display: flex; flex-direction: column; gap: .0625rem; }
    .facts .f { display: flex; gap: 1rem; align-items: baseline; justify-content: space-between; padding: .5rem 0; border-bottom: 1px solid var(--line-soft); font-size: .875rem; }
    .facts .f:last-child { border-bottom: 0; }
    .facts .f dt { color: var(--muted); }
    .facts .f dd { margin: 0; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
    .facts .f.total { margin-top: .375rem; padding: .75rem; border-radius: var(--r); background: var(--ok-bg); border: 0; }
    .facts .f.total dt { color: var(--ok); font-weight: 650; }
    .facts .f.total dd { color: var(--ok); font-size: 1.0625rem; }
    .facts .f.gap { margin-top: .375rem; padding: .75rem; border-radius: var(--r); background: var(--line-soft); border: 0; }
    .facts .f.gap dt { font-weight: 650; color: var(--ink-2); }
    .facts .f.gap dd { font-size: 1.0625rem; }

    .tabs { display: flex; flex-wrap: wrap; gap: .25rem; margin-bottom: 1.25rem; padding: .25rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); width: fit-content; max-width: 100%; }
    .tabs a { padding: .375rem .75rem; border-radius: var(--r-sm); font-size: .8438rem; font-weight: 600; color: var(--muted); }
    .tabs a:hover { background: var(--line-soft); color: var(--ink); text-decoration: none; }
    .tabs a.on { background: var(--brand-soft); color: var(--brand); }

    .tree-in { display: inline-block; color: var(--muted-2); }

    /* ---------------------------------------------------------- Graphiques (SVG, sans dépendance) */

    .chart { display: flex; align-items: flex-end; gap: .5rem; height: 12.5rem; padding-top: 1.5rem; }
    .chart .bar { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; gap: .5rem; }
    .chart .bar .fill { width: 100%; max-width: 3rem; border-radius: var(--r-sm) var(--r-sm) 0 0; background: #bfdbfe; position: relative; min-height: .125rem; }
    .chart .bar.last .fill { background: var(--brand); }
    .chart .bar .tip { position: absolute; bottom: 100%; left: 50%; transform: translate(-50%, -.375rem); font-size: .6875rem; font-weight: 700; color: var(--brand); background: var(--brand-soft); border-radius: 1rem; padding: .0625rem .4375rem; white-space: nowrap; }
    .chart .bar .x { font-size: .6875rem; color: var(--muted); white-space: nowrap; }

    .donut { display: flex; flex-wrap: wrap; gap: 1.25rem; align-items: center; justify-content: center; }
    .donut .ring { position: relative; width: 10.5rem; height: 10.5rem; flex: none; }
    .donut .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .donut .ring .mid { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .donut .ring .mid strong { font-size: 1.0625rem; font-weight: 700; letter-spacing: -.02em; }
    .donut .ring .mid small { font-size: .6875rem; color: var(--muted); }
    .donut .keys { flex: 1; min-width: 11rem; display: flex; flex-direction: column; gap: .5rem; }
    .donut .keys .k { display: flex; align-items: center; gap: .5rem; font-size: .8438rem; }
    .donut .keys .k .sw { width: .625rem; height: .625rem; border-radius: 50%; flex: none; }
    .donut .keys .k .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ink-2); }
    .donut .keys .k .pc { font-weight: 700; font-variant-numeric: tabular-nums; }

    /* ---------------------------------------------------------- Responsive */

    @media (max-width: 1024px) {
        .cols.wide { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 900px) {
        .sidebar {
            position: fixed; z-index: 40; top: 0; left: 0;
            transform: translateX(-100%);
            transition: transform .18s ease-out;
            box-shadow: var(--shadow-pop);
        }
        .nav-switch:checked ~ .shell .sidebar { transform: translateX(0); }
        .nav-switch:checked ~ .shell .scrim { display: block; position: fixed; inset: 0; z-index: 30; background: rgba(15, 23, 42, .4); }
        .burger { display: flex; }
        .topbar { padding: 0 1rem; }
        .content { padding: 1.25rem 1rem; }
    }

    @media (max-width: 720px) {
        body { font-size: 14.5px; }
        .page h1 { font-size: 1.25rem; }
        .kpis { grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: .75rem; }
        .kpi { padding: 1rem; }
        .kpi .value { font-size: 1.3125rem; }
        .who .id { display: none; }
        .topbar .sep { display: none; }

        /* Tableaux empilés : chaque ligne devient une fiche lisible au pouce. */
        table.stack, table.stack thead, table.stack tbody, table.stack tr, table.stack th, table.stack td { display: block; }
        table.stack.wide { min-width: 0; }
        table.stack .mono { white-space: normal; }
        table.stack thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        table.stack tbody tr { border: 1px solid var(--line); border-radius: var(--r); margin: 0 0 .625rem; padding: .25rem .875rem; background: var(--surface); }
        table.stack tbody tr:last-child { margin-bottom: 0; }
        table.stack tbody td { border: 0; border-bottom: 1px solid var(--line-soft); padding: .5rem 0; display: flex; gap: 1rem; align-items: baseline; justify-content: space-between; text-align: right; }
        table.stack tbody td:last-child { border-bottom: 0; }
        table.stack tbody td::before { content: attr(data-l); flex: none; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); text-align: left; }
        table.stack tbody td:empty { display: none; }
        table.stack tbody td.acts { justify-content: flex-end; }
        table.stack tbody td > .sub { text-align: right; }
        .card > .tw { overflow-x: visible; }
        .card > .tw thead th:first-child, .card > .tw tbody td:first-child { padding-left: 0; }
        .card > .tw thead th:last-child, .card > .tw tbody td:last-child { padding-right: 0; }
        .card > .tw { padding: 1rem 1.25rem 1.25rem; }
        table.stack tbody tr:hover { background: var(--surface); }
        tr.cancelled td::before { text-decoration: none; }
        .chart { height: 10rem; }
        .chart .bar .x { font-size: .625rem; }
    }

    @media (max-width: 420px) {
        .content { padding: 1rem .75rem; }
        .topbar { padding: 0 .75rem; }
        .page .acts { width: 100%; }
        .page .acts .btn, .page .acts button { flex: 1; }
        .donut .ring { width: 9rem; height: 9rem; }
    }

    @media print {
        .sidebar, .topbar, .page .acts, form { display: none !important; }
        .content { max-width: none; padding: 0; }
        .card { break-inside: avoid; box-shadow: none; }
    }
</style>
