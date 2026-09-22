<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * La case « ticket de consultation » de la fiche d'un acte. Une case non
 * cochée n'est pas envoyée : son absence veut dire « non ».
 */
final class ConsultationTicketRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_consultation_ticket' => ['sometimes', 'boolean'],
        ];
    }
}
