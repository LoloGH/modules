<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Queue\PharmacyQueue;

/**
 * La file d'attente de la pharmacie : qui attend, et qui on sert.
 *
 * Le module ne range personne : il lit la file que l'hôte lui fournit
 * (`Contracts\PharmacyQueueProvider`) et lui demande d'appeler le suivant.
 * Sans hôte branché, l'écran le dit.
 */
final class QueueController extends PharmacieController
{
    public function index(Request $request): View
    {
        $queues = Pharmacie::queue()->queues();
        $current = $this->currentQueue($queues, $request->query('file'));

        return view('pharmacie::queue.index', [
            'queues' => $queues,
            'current' => $current,
            'patients' => $current === null ? [] : Pharmacie::queue()->pendingPatients($current->ref),
        ]);
    }

    public function callNext(Request $request): RedirectResponse
    {
        $queues = Pharmacie::queue()->queues();
        $current = $this->currentQueue($queues, $request->input('file'));

        if ($current === null) {
            return redirect()->route('pharmacie.queue.index')
                ->with('pharmacie_error', 'Aucune file n\'est fournie par l\'application hôte.');
        }

        $patient = Pharmacie::queue()->callNext($current->ref, $this->user($request));

        return redirect()->route('pharmacie.queue.index', ['file' => $current->ref])->with(
            $patient === null ? 'pharmacie_error' : 'pharmacie_status',
            $patient === null
                ? 'Personne n\'attend dans cette file.'
                : sprintf('%s est appelé au comptoir.', $patient->patientName),
        );
    }

    /**
     * La file regardée : celle demandée si elle existe, sinon la première.
     *
     * @param  list<PharmacyQueue>  $queues
     */
    private function currentQueue(array $queues, mixed $ref): ?PharmacyQueue
    {
        foreach ($queues as $queue) {
            if (is_string($ref) && $queue->ref === $ref) {
                return $queue;
            }
        }

        return $queues[0] ?? null;
    }
}
