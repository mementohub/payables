<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Services\SyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A throwaway "ERP" behind the `omc` connection: the tables the sync reads,
 * in SQLite, so the whole pull can run in a test.
 */
function fakeErp(): void
{
    config()->set('database.connections.omc', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::purge('omc');

    $schema = Schema::connection('omc');

    $schema->create('partener', function (Blueprint $table) {
        $table->string('partener')->primary();
        foreach (['cod_cci', 'reg_comert_nr', 'tara', 'localit', 'adresa', 'telefon', 'email_adr'] as $column) {
            $table->string($column)->nullable();
        }
        $table->boolean('da_nu_platitor_tva')->nullable();
    });

    $schema->create('doc', function (Blueprint $table) {
        $table->date('data_doc');
        $table->string('tip_doc');
        $table->string('nr_doc');
        foreach (['partener', 'moneda', 'emitent', 'com_int', 'eu_punct_lucru', 'tip_doc_baza', 'nr_doc_baza', 'banca_eu', 'cont_banca_eu', 'cine_preda', 'cine_primeste', 'obs_txt'] as $column) {
            $table->string($column)->nullable();
        }
        foreach (['curs', 'val_mon', 'val_mon_tva', 'val_mon_inc', 'val_mon_pl', 'val_mon_dimin_negru', 'val_mon_dimin_rosu'] as $column) {
            $table->float($column)->nullable();
        }
        foreach (['data_scadenta', 'data_inchidere', 'data_doc_baza', 'data_contab', 'data_anulare'] as $column) {
            $table->date($column)->nullable();
        }
        $table->dateTime('ultima_modif_data')->nullable();
    });

    $schema->create('doc_poz', function (Blueprint $table) {
        $table->date('data_doc');
        $table->string('tip_doc');
        $table->string('nr_doc');
        $table->integer('scv');
        $table->string('articol')->nullable();
        $table->string('detaliu_articol')->nullable();
        $table->float('cant')->nullable();
        $table->string('um')->nullable();
        $table->float('pret')->nullable();
        $table->float('proc_tva')->nullable();
        foreach (['conts', 'conta', 'loc', 'com_int', 'nr_obiect', 'furnizor'] as $column) {
            $table->string($column)->nullable();
        }
    });

    $schema->create('doc_fin', function (Blueprint $table) {
        $table->date('data_doc_fin');
        $table->string('tip_doc_fin');
        $table->string('nr_doc_fin');
        $table->date('data_doc_com');
        $table->string('tip_doc_com');
        $table->string('nr_doc_com');
        $table->date('data_repartizare');
        $table->float('val_fin')->nullable();
        $table->float('val_com')->nullable();
    });

    $schema->create('banca', function (Blueprint $table) {
        $table->string('banca')->primary();
        $table->string('cod_bic')->nullable();
        $table->string('swift')->nullable();
    });

    $schema->create('partener_banca', function (Blueprint $table) {
        $table->string('partener');
        $table->string('banca')->nullable();
        $table->string('cont_banca');
        $table->string('moneda')->nullable();
        $table->boolean('da_nu_implicit')->nullable();
        $table->boolean('discontinued')->nullable();
    });

    $schema->create('eu_banca', function (Blueprint $table) {
        $table->string('banca')->nullable();
        $table->string('cont_banca');
        $table->string('moneda')->nullable();
        $table->boolean('da_nu_implicit')->nullable();
        $table->boolean('discontinued')->nullable();
    });

    $schema->create('extrasb', function (Blueprint $table) {
        $table->date('data_extras');
        $table->string('banca_eu')->nullable();
        $table->string('cont_banca_eu');
        $table->string('operator')->nullable();
    });

    $schema->create('com_int', function (Blueprint $table) {
        $table->string('com_int')->primary();
        $table->date('data_incep')->nullable();
    });

    $schema->create('view_anaf_e_fact_furn_msg', function (Blueprint $table) {
        foreach (['msg_tip', 'msg_id', 'msg_cif', 'msg_index_incarcare', 'msg_detalii', 'msg_xml', 'err_ins_omc', 'tip_doc_xml', 'nr_doc_xml', 'partener_xml', 'cod_cci_xml'] as $column) {
            $table->string($column)->nullable();
        }
        $table->dateTime('msg_data_creare_d')->nullable();
        $table->dateTime('data_ins_omc')->nullable();
        $table->date('data_doc_xml')->nullable();
    });

    DB::connection('omc')->table('partener')->insert(['partener' => 'VODAFONE ROMANIA SA', 'cod_cci' => 'RO8971726', 'tara' => 'RO']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function erpDoc(array $overrides = []): void
{
    DB::connection('omc')->table('doc')->insert([
        'data_doc' => '2026-09-14', 'tip_doc' => 'FactFI', 'nr_doc' => 'VDF1', 'partener' => 'VODAFONE ROMANIA SA',
        'moneda' => 'Lei', 'curs' => 1, 'val_mon' => 1000, 'val_mon_tva' => 190, 'val_mon_inc' => 0, 'val_mon_pl' => 0,
        'val_mon_dimin_negru' => 0, 'data_scadenta' => '2026-10-14', 'emitent' => 'contab', ...$overrides,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    fakeErp();

    $this->company = Company::factory()->create(['name' => 'Christian Tour', 'erp_connection' => 'omc']);
});

test('the same window can be synced again: existing invoices are updated, not duplicated', function () {
    erpDoc();
    erpDoc(['data_doc' => '2026-09-16', 'nr_doc' => 'VDF2', 'val_mon' => 500]);
    DB::connection('omc')->table('doc_poz')->insert(['data_doc' => '2026-09-14', 'tip_doc' => 'FactFI', 'nr_doc' => 'VDF1', 'scv' => 1, 'articol' => 'SERV', 'detaliu_articol' => 'VPN', 'cant' => 1, 'pret' => 1000, 'proc_tva' => 19]);

    $sync = app(SyncService::class);
    $first = $sync->sync($this->company, Carbon::parse('2026-09-13'), Carbon::parse('2026-09-16'));

    expect($first['invoices'])->toBe(2)
        ->and($first['partners'])->toBe(1)
        ->and(Invoice::where('company_id', $this->company->id)->count())->toBe(2)
        ->and(Invoice::where('nr_doc', 'VDF1')->first()->details()->count())->toBe(1);

    // the supplier gets paid and the invoice on the last day of the window too
    DB::connection('omc')->table('doc')->where('nr_doc', 'VDF1')->update(['val_mon_pl' => 1000]);
    DB::connection('omc')->table('doc_fin')->insert([
        ['data_doc_fin' => '2026-09-15', 'tip_doc_fin' => 'OP_PL', 'nr_doc_fin' => 'BT1', 'data_doc_com' => '2026-09-14', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'VDF1', 'data_repartizare' => '2026-09-15', 'val_fin' => 1000, 'val_com' => 1000],
        ['data_doc_fin' => '2026-09-16', 'tip_doc_fin' => 'OP_PL', 'nr_doc_fin' => 'BT2', 'data_doc_com' => '2026-09-16', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'VDF2', 'data_repartizare' => '2026-09-16', 'val_fin' => 500, 'val_com' => 500],
    ]);

    $second = $sync->sync($this->company, Carbon::parse('2026-09-13'), Carbon::parse('2026-09-16'));

    expect($second['invoices'])->toBe(2)
        ->and($second['payments'])->toBe(2)
        ->and(Invoice::where('company_id', $this->company->id)->count())->toBe(2)
        ->and((float) Invoice::where('nr_doc', 'VDF1')->first()->val_mon_paid)->toBe(1000.0)
        ->and(Invoice::where('nr_doc', 'VDF1')->first()->payments()->count())->toBe(1)
        ->and(Invoice::where('nr_doc', 'VDF2')->first()->payments()->count())->toBe(1)
        ->and($this->company->fresh()->last_synced_at?->toDateTimeString())->toBe('2026-09-16 10:00:00');
});

test('the recent sync also refreshes older invoices that are still open', function () {
    erpDoc(['data_doc' => '2026-07-01', 'nr_doc' => 'OLD', 'data_scadenta' => '2026-07-31']);
    app(SyncService::class)->sync($this->company, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-01'));

    DB::connection('omc')->table('doc')->where('nr_doc', 'OLD')->update(['val_mon_pl' => 400, 'val_mon_dimin_negru' => 600]);
    DB::connection('omc')->table('doc_fin')->insert(['data_doc_fin' => '2026-09-16', 'tip_doc_fin' => 'OP_PL', 'nr_doc_fin' => 'BT9', 'data_doc_com' => '2026-07-01', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'OLD', 'data_repartizare' => '2026-09-16', 'val_fin' => 400, 'val_com' => 400]);
    erpDoc(['data_doc' => '2026-09-16', 'nr_doc' => 'NEW']);

    $result = app(SyncService::class)->syncRecent($this->company, 3);

    $old = Invoice::where('nr_doc', 'OLD')->first();

    // Re-read: OLD, settled in OMC since, and NEW, open in OMC.
    expect($result['invoices'])->toBe(1)
        ->and($result['refreshed'])->toBe(2)
        ->and((float) $old->val_mon_paid)->toBe(400.0)
        ->and((float) $old->val_mon_storno)->toBe(600.0)
        ->and($old->outstandingAmount())->toBe(0.0)
        ->and($old->payments()->count())->toBe(1)
        ->and(Invoice::where('nr_doc', 'NEW')->exists())->toBeTrue();
});

test('a window is pulled in slices, with a progress line per slice', function () {
    config()->set('sync.slice_days', 3);

    foreach (['2026-09-10', '2026-09-12', '2026-09-14', '2026-09-16'] as $i => $date) {
        erpDoc(['data_doc' => $date, 'nr_doc' => "S{$i}"]);
    }

    $lines = [];
    $result = app(SyncService::class)->syncWindow($this->company, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-16'), function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    expect($result['invoices'])->toBe(4)
        ->and($result['refreshed'])->toBe(4)
        ->and($lines)->toHaveCount(3)
        ->and($lines[0])->toStartWith('2026-09-10 → 2026-09-12: 2 facturi')
        ->and($lines[2])->toStartWith('2026-09-16 → 2026-09-16: 1 facturi')
        ->and(Invoice::where('company_id', $this->company->id)->count())->toBe(4);
});

test('the history pull starts at the configured date, resumes where it stopped and can be restarted', function () {
    config()->set('sync.history_from', '2026-09-01');
    config()->set('sync.slice_days', 7);
    erpDoc(['data_doc' => '2026-09-02', 'nr_doc' => 'H1']);
    erpDoc(['data_doc' => '2026-09-10', 'nr_doc' => 'H2']);

    $lines = [];
    $result = app(SyncService::class)->syncHistory($this->company, function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    expect($result['from'])->toBe('2026-09-01')
        ->and($result['to'])->toBe('2026-09-16')
        ->and($result['invoices'])->toBe(2)
        ->and($lines)->toHaveCount(3)
        ->and(SyncService::historyCursor($this->company))->toBe('2026-09-16');

    // a second call has nothing left to do (the cursor is at today)
    $again = app(SyncService::class)->syncHistory($this->company);
    expect($again['invoices'] ?? 0)->toBe(0);

    // a restart date wipes the cursor and pulls again from there
    erpDoc(['data_doc' => '2026-09-12', 'nr_doc' => 'H3']);
    $restarted = app(SyncService::class)->syncHistory($this->company, null, Carbon::parse('2026-09-08'));
    expect($restarted['from'])->toBe('2026-09-08')
        ->and($restarted['invoices'])->toBe(2)
        ->and(Invoice::where('company_id', $this->company->id)->count())->toBe(3);
});

test('two documents whose number differs only in case are mirrored as the two invoices the ERP holds', function () {
    erpDoc(['nr_doc' => 'GR/16/Inv1', 'val_mon' => 1000]);
    erpDoc(['nr_doc' => 'GR/16/INV1', 'val_mon' => 2000]);

    $result = app(SyncService::class)->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    expect($result['invoices'])->toBe(2)
        ->and(Invoice::where('company_id', $this->company->id)->orderBy('nr_doc')->pluck('nr_doc')->all())
        ->toBe(['GR/16/INV1', 'GR/16/Inv1']);
});

test('numbers that differ only by a trailing space, one key to the local collation, become one invoice', function () {
    erpDoc(['nr_doc' => '16', 'val_mon' => 1000]);
    erpDoc(['nr_doc' => '16 ', 'val_mon' => 2000]);
    erpDoc(['nr_doc' => 'VDF9', 'val_mon' => 300]);

    app(SyncService::class)->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    expect(Invoice::where('company_id', $this->company->id)->count())->toBe(2)
        ->and((float) Invoice::where('nr_doc', 'like', '16%')->sole()->val_mon)->toBe(2000.0)
        ->and(Invoice::where('nr_doc', 'VDF9')->exists())->toBeTrue();
});

test('every document of a window is read however many pages it takes, with the lines routing needs', function () {
    config()->set('sync.page_size', 2);

    foreach (range(1, 5) as $i) {
        erpDoc(['nr_doc' => "P{$i}", 'eu_punct_lucru' => 'SEDIUL CENTRAL', 'ultima_modif_data' => '2026-09-15 08:00:00']);
        DB::connection('omc')->table('doc_poz')->insert([
            'data_doc' => '2026-09-14', 'tip_doc' => 'FactFI', 'nr_doc' => "P{$i}", 'scv' => 1, 'articol' => 'HOT_EU', 'cant' => 1, 'pret' => 100,
            'conts' => '471', 'conta' => '.', 'loc' => $i === 1 ? 'MARK' : '-', 'com_int' => '1234567.', 'nr_obiect' => null, 'furnizor' => 'Hotel Parad',
        ]);
    }

    $result = app(SyncService::class)->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));
    $line = Invoice::where('nr_doc', 'P1')->first()->details()->sole();

    expect($result['invoices'])->toBe(5)
        ->and($result['details'])->toBe(5)
        ->and(Invoice::where('company_id', $this->company->id)->count())->toBe(5)
        ->and(Invoice::where('nr_doc', 'P1')->first()->office)->toBe('SEDIUL CENTRAL')
        ->and($line->only(['account', 'analytic', 'loc', 'com_int', 'furnizor']))->toBe(['account' => '471', 'analytic' => '.', 'loc' => 'MARK', 'com_int' => '1234567.', 'furnizor' => 'Hotel Parad'])
        // OMC's "-" placeholder is no cost centre.
        ->and(Invoice::where('nr_doc', 'P2')->first()->details()->sole()->loc)->toBeNull();
});

test('a document OMC deletes or cancels is marked as removed, and cleared if it comes back', function () {
    erpDoc(['nr_doc' => 'GONE']);
    erpDoc(['nr_doc' => 'CANCEL']);
    erpDoc(['nr_doc' => 'STAYS']);
    $sync = app(SyncService::class);
    $sync->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    DB::connection('omc')->table('doc')->where('nr_doc', 'GONE')->delete();
    DB::connection('omc')->table('doc')->where('nr_doc', 'CANCEL')->update(['data_anulare' => '2026-09-15']);
    $result = $sync->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    expect($result['removed'])->toBe(2)
        ->and(Invoice::where('nr_doc', 'GONE')->first()->omc_removed_at)->not->toBeNull()
        ->and(Invoice::where('nr_doc', 'CANCEL')->first()->omc_removed_at)->not->toBeNull()
        ->and(Invoice::where('nr_doc', 'STAYS')->first()->omc_removed_at)->toBeNull();

    erpDoc(['nr_doc' => 'GONE']);
    $sync->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    expect(Invoice::where('nr_doc', 'GONE')->first()->omc_removed_at)->toBeNull();
});

test('a credit note used up against an invoice is settled, not open', function () {
    erpDoc(['nr_doc' => 'C 1', 'val_mon' => -122.97, 'val_mon_dimin_rosu' => 122.97]);
    erpDoc(['nr_doc' => 'C 2', 'val_mon' => -50]);

    $sync = app(SyncService::class);
    $sync->sync($this->company, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-14'));

    expect(Invoice::where('nr_doc', 'C 1')->first()->outstandingAmount())->toBe(0.0)
        ->and(Invoice::where('nr_doc', 'C 1')->first()->paymentStatus())->toBe('paid')
        ->and(Invoice::where('nr_doc', 'C 2')->first()->paymentStatus())->toBe('unpaid')
        ->and(Invoice::where('nr_doc', 'C 2')->first()->outstandingAmount())->toBe(-50.0)
        // Only the credit note still to be used is in OMC's open list.
        ->and($sync->refreshOpenInvoices($this->company))->toBe(1);
});

test('the open invoices are taken from OMC, whatever their date, and the ones settled since are brought up to date', function () {
    // Open in OMC, dated months ago and never pulled here.
    erpDoc(['data_doc' => '2026-02-03', 'nr_doc' => 'LATE', 'val_mon' => 700]);
    // Pulled while open, paid since.
    erpDoc(['data_doc' => '2026-08-05', 'nr_doc' => 'A', 'val_mon' => 1000]);
    // Pulled while open, still open.
    erpDoc(['data_doc' => '2026-08-06', 'nr_doc' => 'B', 'val_mon' => 500]);
    $sync = app(SyncService::class);
    $sync->sync($this->company, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

    DB::connection('omc')->table('doc')->where('nr_doc', 'A')->update(['val_mon_pl' => 1000, 'data_scadenta' => '2026-09-04']);
    DB::connection('omc')->table('doc_fin')->insert(['data_doc_fin' => '2026-09-15', 'tip_doc_fin' => 'OP_PL', 'nr_doc_fin' => 'BTRL1', 'data_doc_com' => '2026-08-05', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'A', 'data_repartizare' => '2026-09-15', 'val_fin' => 1000, 'val_com' => 1000]);

    $refreshed = $sync->refreshOpenInvoices($this->company);
    $paid = Invoice::where('nr_doc', 'A')->first();

    expect($refreshed)->toBe(3)
        ->and(Invoice::where('nr_doc', 'LATE')->exists())->toBeTrue()
        ->and((float) $paid->val_mon_paid)->toBe(1000.0)
        ->and($paid->data_scadenta?->toDateString())->toBe('2026-09-04')
        ->and($paid->payments()->sole()->nr_doc)->toBe('BTRL1')
        ->and((float) Invoice::where('nr_doc', 'B')->first()->val_mon_paid)->toBe(0.0);
});

test('an allocation the ERP hands over twice is stored once, not thrown', function () {
    erpDoc();
    DB::connection('omc')->table('doc')->where('nr_doc', 'VDF1')->update(['val_mon_pl' => 1000]);

    // The same allocation, spelled the two ways the ERP spells dates and with
    // the trailing space a document number picks up along the way. The date
    // column and the collation see one row; only PHP sees two.
    DB::connection('omc')->table('doc_fin')->insert([
        ['data_doc_fin' => '2026-09-15', 'tip_doc_fin' => 'Ch_INC', 'nr_doc_fin' => 'CTA 40014439', 'data_doc_com' => '2026-09-14', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'VDF1', 'data_repartizare' => '2026-09-15', 'val_fin' => 600, 'val_com' => 600],
        ['data_doc_fin' => '2026-09-15 00:00:00', 'tip_doc_fin' => 'Ch_INC', 'nr_doc_fin' => 'CTA 40014439', 'data_doc_com' => '2026-09-14', 'tip_doc_com' => 'FactFI', 'nr_doc_com' => 'VDF1', 'data_repartizare' => '2026-09-15 00:00:00', 'val_fin' => 400, 'val_com' => 400],
    ]);

    $result = app(SyncService::class)->sync($this->company, Carbon::parse('2026-09-13'), Carbon::parse('2026-09-16'));

    $payment = Invoice::where('nr_doc', 'VDF1')->first()->payments()->sole();

    expect($result['payments'])->toBe(1)
        ->and($payment->data_doc->toDateString())->toBe('2026-09-15')
        ->and($payment->data_repartizare->toDateString())->toBe('2026-09-15');
});
