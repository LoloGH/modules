{{--
    Report du tarif de l'acte dans le champ « Montant ».

    Seul script du module : pas de bibliothèque, pas de dépendance, et la page
    reste entièrement utilisable sans lui — le tarif figure de toute façon dans
    l'intitulé de chaque option, et le caissier peut toujours saisir le montant
    à la main.

    Règle de politesse : on ne piétine jamais une saisie manuelle. Le montant
    n'est rempli que s'il est vide, ou s'il porte encore une valeur que ce
    script avait lui-même posée. Dès que quelqu'un tape dans le champ, il
    devient le sien.

    Un acte sans tarif, ou « Aucun », ne touche pas au montant : effacer ce que
    le caissier a déjà saisi serait pire que de ne rien faire.
--}}
<script>
    (function () {
        'use strict';

        document.querySelectorAll('select[data-fills]').forEach(function (select) {
            var amount = document.getElementById(select.dataset.fills);

            if (amount === null) {
                return;
            }

            // Une saisie au clavier reprend la main sur le remplissage auto.
            amount.addEventListener('input', function () {
                delete amount.dataset.filled;
            });

            select.addEventListener('change', function () {
                var option = select.options[select.selectedIndex];
                var price = option && option.dataset ? option.dataset.amount : null;

                if (!price) {
                    return;
                }

                var typed = amount.value.replace(/\D/g, '');

                if (typed !== '' && typed !== amount.dataset.filled) {
                    return;
                }

                // « 2 000 » plutôt que « 2000 » : le formulaire accepte les
                // espaces et c'est plus lisible pour relire un montant.
                amount.value = price.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                amount.dataset.filled = price;
            });
        });
    })();
</script>
