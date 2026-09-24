<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

/**
 * Les événements du journal d'audit, avec leur libellé et leur famille.
 *
 * Le journal enregistre un code (`payment_recorded`) ; cet écran le lit en
 * français. Un code inconnu, écrit par une version plus récente, ou par
 * l'hôte, s'affiche tel quel plutôt que de disparaître du filtre : le
 * journal ne cache jamais une ligne qu'il ne sait pas nommer.
 */
final class AuditEvents
{
    /**
     * Les événements connus, groupés comme on les cherche.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Caisse' => [
                'session_opened' => 'Session de caisse ouverte',
                'session_closed' => 'Session de caisse clôturée',
                'session_validated' => 'Clôture validée',
                'cashier_limit_set' => 'Limite de sessions d\'un caissier',
                'cashier_registers_set' => 'Caisses autorisées d\'un caissier',
                'register_created' => 'Caisse créée',
                'register_activated' => 'Caisse activée',
                'register_deactivated' => 'Caisse désactivée',
            ],
            'Encaissements et décaissements' => [
                'payment_recorded' => 'Encaissement enregistré',
                'payment_cancelled' => 'Encaissement annulé',
                'disbursement_recorded' => 'Décaissement enregistré',
                'disbursement_cancelled' => 'Décaissement annulé',
                'queued_payment_rolled_back' => 'Encaissement de la file annulé',
            ],
            'Factures' => [
                'invoice_created' => 'Facture créée',
                'invoice_cancelled' => 'Facture annulée',
            ],
            'Avances et comptes patients' => [
                'deposit_recorded' => 'Avance enregistrée',
                'deposit_cancelled' => 'Avance annulée',
            ],
            'Remises et remboursements' => [
                'discount_requested' => 'Remise demandée',
                'discount_approved' => 'Remise approuvée',
                'discount_refused' => 'Remise refusée',
                'refund_requested' => 'Remboursement demandé',
                'refund_approved' => 'Remboursement approuvé',
                'refund_refused' => 'Remboursement refusé',
                'refund_paid' => 'Remboursement payé',
            ],
            'Assurances' => [
                'insurer_created' => 'Organisme créé',
                'insurer_activated' => 'Organisme activé',
                'insurer_deactivated' => 'Organisme désactivé',
                'insurer_coverage_set' => 'Couverture d\'un organisme modifiée',
                'insurance_settlement_recorded' => 'Règlement d\'assureur enregistré',
                'insurance_rejection_recorded' => 'Rejet d\'assureur enregistré',
            ],
            'Catalogue et tarifs' => [
                'act_created' => 'Acte créé',
                'act_activated' => 'Acte activé',
                'act_deactivated' => 'Acte désactivé',
                'act_center_set' => 'Acte rattaché à un centre',
                'tariff_changed' => 'Tarif modifié',
                'consultation_ticket_set' => 'Ticket de consultation désigné',
                'consultation_ticket_unset' => 'Ticket de consultation retiré',
                'analytic_center_created' => 'Centre analytique créé',
                'analytic_center_updated' => 'Centre analytique modifié',
                'analytic_center_activated' => 'Centre analytique activé',
                'analytic_center_deactivated' => 'Centre analytique désactivé',
            ],
            'Droits et accès' => [
                'user_permissions_set' => 'Capacités d\'un utilisateur réglées',
                'user_permissions_reset' => 'Capacités ramenées aux rôles de l\'hôte',
                'access_denied' => 'Accès au module refusé',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function label(string $event): string
    {
        return self::labels()[$event] ?? $event;
    }

    /**
     * La couleur du badge : ce qui annule, refuse ou refuse l'accès se
     * remarque ; ce qui valide ou approuve se lit comme acquis.
     */
    public static function tone(string $event): string
    {
        return match (true) {
            str_contains($event, 'cancelled'), str_contains($event, 'refused'), $event === 'access_denied' => 'danger',
            str_contains($event, 'validated'), str_contains($event, 'approved'), str_contains($event, 'paid') => 'ok',
            str_contains($event, 'requested') => 'warn',
            default => 'muted',
        };
    }

    /**
     * Les groupes du filtre, enrichis des codes réellement présents dans le
     * journal mais inconnus d'ici.
     *
     * @param  iterable<string>  $present
     * @return array<string, array<string, string>>
     */
    public static function groupsWith(iterable $present): array
    {
        $groups = self::groups();
        $known = self::labels();
        $unknown = [];

        foreach ($present as $event) {
            if (! isset($known[$event])) {
                $unknown[$event] = $event;
            }
        }

        if ($unknown !== []) {
            ksort($unknown);
            $groups['Autres'] = $unknown;
        }

        return $groups;
    }
}
