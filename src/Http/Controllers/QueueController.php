<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;
use Keneya\Pharmacie\Support\Text;

/**
 * La file d'attente de la pharmacie : qui attend, et où il en est.
 *
 * Le module ne range personne : il lit la file que l'hôte lui fournit
 * (`Contracts\PharmacyQueueProvider`) et lui demande d'appeler le suivant.
 * Sans hôte branché, l'écran le dit.
 *
 * Ce que l'écran doit savoir en plus, c'est **ce qui est déjà engagé** pour
 * chaque patient. Un patient revenu de la caisse n'est pas un nouvel
 * arrivant : une préparation l'attend, et il faut la délivrer, pas en écrire
 * une seconde. Un patient servi n'attend plus rien à la pharmacie : il se
 * clôt, ou part vers un autre service. Sans cette lecture, le comptoir
 * tournerait en rond : préparer, encaisser, revenir, préparer.
 */
final class QueueController extends PharmacieController
{
    public function index(Request $request): View
    {
        $queues = Pharmacie::queue()->queues();
        $current = $this->currentQueue($queues, $request->query('file'));
        $patients = $current === null ? [] : Pharmacie::queue()->pendingPatients($current->ref);

        return view('pharmacie::queue.index', [
            'queues' => $queues,
            'current' => $current,
            'patients' => $patients,
            // Où en est chacun : rien, une préparation à délivrer, ou servi.
            'work' => $this->workInProgress($patients),
            // Les services où envoyer un patient servi, si l'hôte en propose.
            'destinations' => Pharmacie::discharge()->destinations(),
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
     * Le patient est servi et n'attend plus rien ici : son passage se clôt.
     */
    public function close(Request $request): RedirectResponse
    {
        return $this->discharge($request, function (string $file, string $patient, ?string $reason) use ($request): string {
            Pharmacie::discharge()->close($file, $patient, $reason, $this->user($request));

            return 'Le passage du patient est clos : il quitte la file de la pharmacie.';
        });
    }

    /**
     * Le patient est servi, mais attendu ailleurs : on l'y envoie.
     */
    public function refer(Request $request): RedirectResponse
    {
        $destination = Text::clean($request->input('destination'));

        if ($destination === null) {
            return back()->with('pharmacie_error', 'Choisissez le service vers lequel envoyer le patient.');
        }

        return $this->discharge($request, function (string $file, string $patient, ?string $reason) use ($request, $destination): string {
            Pharmacie::discharge()->refer($file, $patient, $destination, $reason, $this->user($request));

            return 'Le patient est envoyé vers le service choisi : il quitte la file de la pharmacie.';
        });
    }

    /**
     * Les deux sorties se ressemblent : mêmes paramètres, même retour, même
     * façon de rendre un refus de l'hôte.
     *
     * @param  callable(string, string, ?string): string  $geste
     */
    private function discharge(Request $request, callable $geste): RedirectResponse
    {
        $file = Text::clean($request->input('file'));
        $patient = Text::clean($request->input('patient'));
        $reason = Text::clean($request->input('reason'));

        if ($file === null || $patient === null) {
            return back()->with('pharmacie_error', 'Ce patient ne vient pas de la file : rien à clore ici.');
        }

        try {
            $message = $geste($file, $patient, $reason);
        } catch (PharmacieRuleViolation $e) {
            return redirect()->route('pharmacie.queue.index', ['file' => $file])
                ->with('pharmacie_error', $e->getMessage());
        }

        return redirect()->route('pharmacie.queue.index', ['file' => $file])
            ->with('pharmacie_status', $message);
    }

    /**
     * Ce qui est engagé pour chacun de ces patients.
     *
     * Une préparation en cours se délivre ; une dispensation déjà servie
     * attend une sortie de file. Le reste est un nouvel arrivant.
     *
     * @param  list<QueuedPatient>  $patients
     * @return array<string, array{preparation: ?Dispensation, served: ?Dispensation}>
     */
    private function workInProgress(array $patients): array
    {
        $refs = array_values(array_filter(array_map(
            static fn (QueuedPatient $patient): string => $patient->ref,
            $patients,
        )));

        if ($refs === []) {
            return [];
        }

        $dispensations = Dispensation::query()->ofFacility()
            ->whereIn('queue_ref', $refs)
            ->where('status', '!=', Dispensation::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        $work = [];

        foreach ($dispensations as $dispensation) {
            $ref = (string) $dispensation->queue_ref;
            $work[$ref] ??= ['preparation' => null, 'served' => null];

            if ($dispensation->status === Dispensation::STATUS_DRAFT) {
                $work[$ref]['preparation'] = $dispensation;

                continue;
            }

            // La dernière servie : c'est elle qu'on montre au pharmacien.
            $work[$ref]['served'] = $dispensation;
        }

        return $work;
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
