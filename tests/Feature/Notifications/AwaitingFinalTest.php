<?php

use App\Mail\AwaitingFinalMail;
use App\Models\Company;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceDepartmentApproval;
use App\Models\User;
use App\Services\Approvals\InvoiceWorkflow;
use App\Services\Notifications\AwaitingFinalDigest;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->company = Company::factory()->create();
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->charters = Department::query()->whereNotNull('code')->first();
});

function awaitingInvoice(array $attributes = [], array $approval = []): Invoice
{
    $invoice = Invoice::factory()->for(test()->company)->create([
        'partener_type' => 'furnizor',
        'approval_status' => InvoiceWorkflow::FINAL,
        'final_decided_at' => null,
        'val_mon' => 5_000,
        'val_mon_paid' => 0,
        'moneda' => 'Lei',
        'curs' => 1,
        ...$attributes,
    ]);

    InvoiceDepartmentApproval::query()->create([
        'invoice_id' => $invoice->id,
        'department_id' => test()->charters->id,
        'status' => InvoiceDepartmentApproval::APPROVED,
        'decided_by_id' => test()->boss->id,
        'decided_at' => now()->subDays(4),
        ...$approval,
    ]);

    return $invoice->fresh();
}

test('the digest gathers what the departments approved and nobody signed yet', function () {
    $waiting = awaitingInvoice();
    // Decisă final: nu mai are ce căuta în listă.
    awaitingInvoice(['approval_status' => 'approved', 'final_decided_at' => now()]);
    // Încă la departamente.
    awaitingInvoice(['approval_status' => InvoiceWorkflow::DEPARTMENT]);

    $digest = app(AwaitingFinalDigest::class)->gather();

    expect($digest['count'])->toBe(1)
        ->and($digest['to_pay'])->toHaveCount(1)
        ->and($digest['to_pay'][0]['nr_doc'])->toBe(trim((string) $waiting->nr_doc))
        ->and($digest['to_pay'][0]['departments'])->toBe([test()->charters->name])
        ->and($digest['to_pay'][0]['waiting_days'])->toBe(4)
        // Peste pragul de trei zile: se scrie cu roșu în mail.
        ->and($digest['stale'])->toHaveCount(1)
        ->and($digest['totals'])->toBe([['moneda' => 'Lei', 'count' => 1, 'rest' => 5_000.0]]);
});

test('an invoice already paid waits for the signature only for the record, and sits apart', function () {
    awaitingInvoice(['val_mon_paid' => 5_000]);

    $digest = app(AwaitingFinalDigest::class)->gather();

    expect($digest['count'])->toBe(1)
        ->and($digest['to_pay'])->toBe([])
        ->and($digest['recorded'])->toHaveCount(1)
        // Nu intră în totalul de plătit: banii au plecat deja.
        ->and($digest['totals'])->toBe([]);
});

test('the mail goes to Top Management, and only when there is something to sign', function () {
    $this->artisan('invoices:mail-awaiting-final')->assertSuccessful();
    Mail::assertNothingSent();

    awaitingInvoice();

    $this->artisan('invoices:mail-awaiting-final')->assertSuccessful();

    Mail::assertSent(AwaitingFinalMail::class, function (AwaitingFinalMail $mail) {
        $html = $mail->render();

        return $mail->hasTo($this->boss->email)
            && str_contains($mail->envelope()->subject, 'De aprobat final: 1 factură')
            && str_contains($html, 'așteaptă aprobarea finală')
            && str_contains($html, 'de 4 zile');
    });

    // Celorlalți nu li se trimite: decizia e a Top Management-ului.
    Mail::assertNotSent(AwaitingFinalMail::class, fn (AwaitingFinalMail $mail) => $mail->hasTo(
        User::factory()->withRoles('finance')->create()->email,
    ));
});

test('it can be sent to a given address, for a look', function () {
    awaitingInvoice();

    $this->artisan('invoices:mail-awaiting-final', ['--to' => ['stefan.petre@christiantour.ro']])->assertSuccessful();

    Mail::assertSent(AwaitingFinalMail::class, fn (AwaitingFinalMail $mail) => $mail->hasTo('stefan.petre@christiantour.ro'));
    Mail::assertNotSent(AwaitingFinalMail::class, fn (AwaitingFinalMail $mail) => $mail->hasTo($this->boss->email));
});
