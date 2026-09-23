<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Models\Dispensation;

/**
 * Ce que la pharmacie rend au dossier médical après avoir servi.
 *
 * L'hôte implémente ce contrat pour que le DME sache qu'une ordonnance a été
 * délivrée, entièrement ou en partie, et par qui. C'est le seul chemin de
 * retour : la pharmacie n'écrit jamais directement dans le dossier médical.
 *
 * Rien n'est renvoyé du détail des lots ni des prix : cela regarde la
 * pharmacie, pas le dossier du patient.
 */
interface PrescriptionSink
{
    /**
     * Une ordonnance vient d'être servie.
     *
     * `$complete` dit si tout ce qui était prescrit a été délivré : au DME de
     * décider ce qu'il en fait (passer l'ordonnance à « délivrée », ou la
     * laisser ouverte).
     */
    public function dispensed(string $reference, Dispensation $dispensation, bool $complete): void;
}
