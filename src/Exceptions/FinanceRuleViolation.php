<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Exceptions;

use DomainException;

/**
 * Une règle métier de la caisse est violée (session fermée, montant nul,
 * écart non justifié…). Le message est en français et destiné à l'utilisateur.
 */
final class FinanceRuleViolation extends DomainException {}
