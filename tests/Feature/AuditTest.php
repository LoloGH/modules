<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Tests\TestCase;
use LogicException;

/**
 * Le journal d'audit : on y ajoute, on n'y change rien.
 */
class AuditTest extends TestCase
{
    public function test_an_entry_records_who_what_and_the_values(): void
    {
        $user = $this->makeUser(['name' => 'Salif Konaté']);
        $subject = $this->makeUser(['name' => 'Objet audité']);

        $log = app(Auditor::class)->record(
            'tariff_changed',
            $subject,
            'Tarif modifié',
            ['amount' => 5000],
            ['amount' => 7500],
            $user,
        );

        $log = AuditLog::findOrFail($log->id);

        $this->assertSame('tariff_changed', $log->event);
        $this->assertSame((string) $user->id, $log->user_id);
        $this->assertSame('Salif Konaté', $log->user_name);
        $this->assertSame($subject->getMorphClass(), $log->subject_type);
        $this->assertSame((string) $subject->id, $log->subject_id);
        $this->assertSame(['amount' => 5000], $log->old_values);
        $this->assertSame(['amount' => 7500], $log->new_values);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_current_user_is_used_when_none_is_given(): void
    {
        $user = $this->makeUser(['name' => 'Caissier connecté']);
        $this->actingAs($user);

        $log = app(Auditor::class)->record('created');

        $this->assertSame((string) $user->id, $log->user_id);
        $this->assertSame('Caissier connecté', $log->user_name);
    }

    public function test_an_entry_can_exist_without_user_or_subject(): void
    {
        $log = app(Auditor::class)->record('created');

        $this->assertNull($log->user_id);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->old_values);
    }

    public function test_an_entry_cannot_be_modified(): void
    {
        $log = app(Auditor::class)->record('created');

        $this->expectException(LogicException::class);

        $log->update(['event' => 'autre']);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        $log = app(Auditor::class)->record('created');

        try {
            $log->delete();
            $this->fail('La suppression aurait dû être refusée.');
        } catch (LogicException) {
            $this->assertSame(1, AuditLog::count());
        }
    }

    public function test_a_denied_access_is_recorded(): void
    {
        $this->grantHostAccess(false);
        $user = $this->makeUser(['name' => 'Sans accès']);

        $this->actingAs($user)->get('/finance')->assertForbidden();

        $log = AuditLog::where('event', 'access_denied')->sole();

        $this->assertSame((string) $user->id, $log->user_id);
        $this->assertStringContainsString('finance', (string) $log->description);
    }

    public function test_a_granted_access_is_not_recorded(): void
    {
        $this->actingAs($this->makeUser())->get('/finance')->assertOk();

        $this->assertSame(0, AuditLog::count());
    }
}
