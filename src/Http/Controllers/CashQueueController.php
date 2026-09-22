<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Support\Actor;

/**
 * La file d'attente d'une caisse, dans Finance : le caissier y voit les
 * patients du jour, appelle le suivant et encaisse.
 *
 * La file appartient à l'hôte (`Finance::cashQueue()`) ; Finance ne fait que
 * la montrer et y agir par le contrat. L'encaissement, lui, se fait dans la
 * session de caisse de Finance (sessions, écart, audit) : on n'encaisse pas
 * sans tiroir ouvert, l'écran invite d'abord à l'ouvrir.
 *
 * Un patient de la file « Caisse Ticket » s'encaisse dans la session de la
 * caisse « Caisse Ticket », et ainsi de suite : la session est choisie d'elle-
 * même quand une caisse ouverte porte le nom de la file. Sinon, ou si le
 * caissier en décide autrement, il choisit.
 */
final class CashQueueController extends FinanceController
{
    public function index(Request $request): View
    {
        $queues = collect(Finance::cashQueue()->queues());
        $current = $this->selectedQueue($request, $queues);
        $sessions = $this->openSessions($request);
        [$session, $matched] = $this->sessionFor($request, $sessions, $current);

        return view('finance::queue.index', [
            'queues' => $queues,
            'current' => $current,
            'visits' => $current === null ? [] : Finance::cashQueue()->pendingVisits($current->ref),
            // Le point rouge des onglets : combien attendent à chaque caisse.
            'waiting' => $queues->mapWithKeys(fn (CashQueue $queue): array => [
                $queue->ref => count(Finance::cashQueue()->pendingVisits($queue->ref)),
            ])->all(),
            'sessions' => $sessions,
            'session' => $session,
            'matched' => $matched,
        ]);
    }

    public function callNext(Request $request): RedirectResponse
    {
        $queues = collect(Finance::cashQueue()->queues());
        $current = $this->selectedQueue($request, $queues);

        abort_if($current === null, 404, 'Aucune file de caisse.');

        [$session] = $this->sessionFor($request, $this->openSessions($request), $current);
        $back = ['file' => $current->ref, 'session' => $session?->id];

        // Appeler un patient qu'on ne pourra pas encaisser le ferait attendre
        // devant un guichet fermé.
        if ($session === null) {
            return redirect()->route('finance.queue.index', $back)
                ->with('finance_error', 'Ouvrez d\'abord votre session de caisse : sans elle, aucun encaissement n\'est possible.');
        }

        $visit = Finance::cashQueue()->callNext($current->ref, $this->user($request));

        return redirect()->route('finance.queue.index', $back)->with(
            $visit === null ? 'finance_error' : 'finance_status',
            $visit === null
                ? "Aucun patient en attente à {$current->name}."
                : sprintf('Ticket n° %d appelé : %s (%s).', $visit->token, $visit->patientName, $visit->patientRef),
        );
    }

    /**
     * @param  Collection<int, CashQueue>  $queues
     */
    private function selectedQueue(Request $request, Collection $queues): ?CashQueue
    {
        $ref = $request->input('file');

        return $queues->first(fn (CashQueue $queue): bool => $queue->ref === $ref) ?? $queues->first();
    }

    /**
     * @return Collection<int, CashSession>
     */
    private function openSessions(Request $request): Collection
    {
        return CashSession::query()->open()->with('register')
            ->where('cashier_id', Actor::id($this->user($request)))
            ->orderBy('id')
            ->get();
    }

    /**
     * La session où encaisser les patients de cette file, et si elle
     * correspond à la file.
     *
     *  1. celle que le caissier a choisie (paramètre `session`), si elle est
     *     bien à lui et ouverte ;
     *  2. sinon celle de la caisse qui porte le nom de la file (« Caisse
     *     Ticket » pour la file « Caisse Ticket ») ;
     *  3. sinon la première de ses sessions ouvertes — l'écran lui demande
     *     alors de choisir.
     *
     * @param  Collection<int, CashSession>  $sessions
     * @return array{0: ?CashSession, 1: bool}
     */
    private function sessionFor(Request $request, Collection $sessions, ?CashQueue $queue): array
    {
        $chosen = $sessions->firstWhere('id', (int) $request->input('session'));
        $matching = $queue === null ? null : $sessions->first(
            fn (CashSession $session): bool => self::sameName($session->register->name, $queue->name)
        );

        if ($chosen !== null) {
            return [$chosen, $matching !== null && $chosen->is($matching)];
        }

        if ($matching !== null) {
            return [$matching, true];
        }

        return [$sessions->first(), false];
    }

    private static function sameName(string $a, string $b): bool
    {
        $normalize = static fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));

        return $normalize($a) === $normalize($b);
    }
}
