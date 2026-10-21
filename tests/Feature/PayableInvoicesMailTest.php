<?php

use App\Mail\PayableInvoicesMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow('2026-10-22 12:00:00');
    Mail::fake();
    cache()->flush();

    $this->company = Company::factory()->create();
    $this->partner = Partner::factory()->create(['company_id' => $this->company->id, 'name' => 'Hotel Alfa SRL']);
    $this->boss = User::factory()->withRoles('top_management')->create();
    $this->treasury = User::factory()->withRoles('treasury')->create(['email' => 'trezorerie@christiantour.ro']);
});

function payable(array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'company_id' => test()->company->id,
        'partner_id' => test()->partner->id,
        'partener_type' => 'furnizor',
        'tip_doc' => 'FactFI',
        'moneda' => 'RON',
        'curs' => 1,
        'val_mon' => 1000,
        'val_mon_tva' => 0,
        'val_mon_paid' => 0,
        'val_mon_storno' => 0,
        'approval_status' => 'approved',
        'final_decided_by_id' => test()->boss->id,
        'final_decided_at' => Carbon::now()->subDays(2),
        'data_doc' => '2026-10-01',
        'data_scadenta' => '2026-10-30',
        ...$attributes,
    ]);
}

test('the treasury is told what Top Management approved and nobody has paid', function () {
    payable();
    // Plătită: n-are ce căuta în listă.
    payable(['val_mon_paid' => 1000, 'nr_doc' => 'PLATITA']);
    // Aprobată de departamente, dar nu și de Top Management.
    payable(['approval_status' => 'final', 'final_decided_at' => null, 'final_decided_by_id' => null, 'nr_doc' => 'ASTEAPTA']);
    // Amânată pentru la anul.
    payable(['postponed_until' => Carbon::now()->addMonth(), 'nr_doc' => 'AMANATA']);

    $this->artisan('invoices:mail-payable')->assertSuccessful();

    Mail::assertSent(PayableInvoicesMail::class, function (PayableInvoicesMail $mail) {
        $numbers = collect($mail->digest['rows'])->pluck('nr_doc');

        return $mail->hasTo('trezorerie@christiantour.ro')
            && $mail->digest['count'] === 1
            && ! $numbers->contains('PLATITA')
            && ! $numbers->contains('ASTEAPTA')
            && ! $numbers->contains('AMANATA');
    });
});

test('the overdue ones are counted apart, so they get paid first', function () {
    // A doua săptămână, nu prima: atunci subiectul vorbește despre restanțe.
    cache()->forever('invoices.payable-mail.last', now()->subWeek()->toIso8601String());

    payable(['data_scadenta' => '2026-10-10', 'nr_doc' => 'RESTANTA']);
    payable(['data_scadenta' => '2026-11-20', 'nr_doc' => 'ARE-TIMP']);

    $this->artisan('invoices:mail-payable')->assertSuccessful();

    Mail::assertSent(PayableInvoicesMail::class, function (PayableInvoicesMail $mail) {
        return count($mail->digest['overdue']) === 1
            && $mail->digest['overdue'][0]['nr_doc'] === 'RESTANTA'
            && str_contains($mail->envelope()->subject, 'restante');
    });
});

test('the list travels as a spreadsheet too, for the work that follows', function () {
    payable(['nr_doc' => 'F-1']);
    payable(['nr_doc' => 'F-2', 'moneda' => 'EUR', 'curs' => 5, 'val_mon' => 200]);

    $this->artisan('invoices:mail-payable')->assertSuccessful();

    Mail::assertSent(PayableInvoicesMail::class, function (PayableInvoicesMail $mail) {
        $attachments = $mail->attachments();

        return count($attachments) === 1
            && str_contains($attachments[0]->as, 'facturi-de-platit')
            && str_ends_with($attachments[0]->as, '.xlsx');
    });
});

test('with nothing to pay, nobody is bothered', function () {
    $this->artisan('invoices:mail-payable')->assertSuccessful();

    Mail::assertNothingSent();

    // Dar cine cere anume, primește și vestea bună.
    $this->artisan('invoices:mail-payable', ['--force' => true])->assertSuccessful();

    Mail::assertSent(PayableInvoicesMail::class, fn (PayableInvoicesMail $mail) => $mail->digest['count'] === 0
        && str_contains($mail->envelope()->subject, 'Nicio factură'));
});

test('the totals keep each currency apart', function () {
    payable(['moneda' => 'RON', 'val_mon' => 1000]);
    payable(['moneda' => 'EUR', 'val_mon' => 500, 'curs' => 5, 'nr_doc' => 'EURO']);

    $this->artisan('invoices:mail-payable')->assertSuccessful();

    Mail::assertSent(PayableInvoicesMail::class, function (PayableInvoicesMail $mail) {
        $totals = collect($mail->digest['totals'])->keyBy('moneda');

        return $totals->count() === 2
            && (float) $totals['RON']['rest'] === 1000.0
            && (float) $totals['EUR']['rest'] === 500.0;
    });
});
