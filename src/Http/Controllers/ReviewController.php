<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Actions\ValidateCashSession;
use Keneya\FinanceCaisse\Http\Requests\ApproveSessionRequest;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Le contrôle : sessions à valider, et validation.
 */
final class ReviewController extends FinanceController
{
    private const FILTERS = [CashSession::STATUS_CLOSED, CashSession::STATUS_VALIDATED, CashSession::STATUS_OPEN, 'all'];

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', CashSession::STATUS_CLOSED);
        $status = in_array($status, self::FILTERS, true) ? $status : CashSession::STATUS_CLOSED;

        $sessions = CashSession::query()
            ->with('register')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return view('finance::review.index', ['sessions' => $sessions, 'status' => $status]);
    }

    public function approve(ApproveSessionRequest $request, CashSession $session, ValidateCashSession $action): RedirectResponse
    {
        $validated = $action->handle($session, $this->user($request), $request->validated('note'));

        return redirect()
            ->route('finance.review.index')
            ->with('finance_status', "Session {$validated->number} validée.");
    }
}
