{{--
    Prise en charge : le montant proposé devient la part patient de l'acte.

    Le serveur recalcule toujours — ce script ne fait que montrer d'avance ce
    que la facture dira, pour que le caissier ne découvre pas le partage après
    coup.

    Il s'applique à TOUS les formulaires de la page : la file d'attente en
    porte un par patient appelé, et chacun a sa propre prise en charge.
--}}
<script>
    (() => {
        document.querySelectorAll('[data-coverage-form]').forEach((form) => {
            const map = JSON.parse(form.dataset.coverage || '{}');
            const act = form.querySelector('select[name=act_id]');
            const insurer = form.querySelector('select[name=insurer_id]');
            const amount = form.querySelector('input[name=amount]');
            const hint = form.querySelector('[data-coverage-hint]');
            if (!act || !insurer || !amount || !hint) return;
            const fmt = (n) => n.toLocaleString('fr-FR').replace(/ | /g, ' ') + ' FCFA';
            const sync = () => {
                const option = act.selectedOptions[0];
                const price = option && option.dataset.amount ? parseInt(option.dataset.amount, 10) : 0;
                const rates = insurer.value && map[insurer.value] ? map[insurer.value].rates : null;
                const rate = rates && act.value ? (rates[act.value] || 0) : 0;
                if (!insurer.value) { hint.textContent = ''; return; }
                if (!act.value) { hint.textContent = "Choisissez l'acte : la prise en charge s'applique à un acte."; return; }
                if (rate === 0) { hint.textContent = map[insurer.value].name + ' ne couvre pas cet acte.'; return; }
                const share = Math.round(price * rate / 100);
                amount.value = String(price - share).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                hint.textContent = map[insurer.value].name + ' prend en charge ' + rate + ' % (' + fmt(share) + ') ; part patient : ' + fmt(price - share) + '.';
            };
            act.addEventListener('change', () => setTimeout(sync, 0));
            insurer.addEventListener('change', sync);
            sync();
        });
    })();
</script>
