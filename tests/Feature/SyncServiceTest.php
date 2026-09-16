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
        foreach (['partener', 'moneda', 'emitent', 'com_int', 'tip_doc_baza', 'nr_doc_baza', 'banca_eu', 'cont_banca_eu', 'cine_preda', 'cine_primeste', 'obs_txt'] as $column) {
            $table->string($column)->nullable();
        }
        foreach (['curs', 'val_mon', 'val_mon_tva', 'val_mon_inc', 'val_mon_pl', 'val_mon_dimin_negru'] as $column) {
            $table->float($column)->nullable();
        }
        foreach (['data_scadenta', 'data_inchidere', 'data_doc_baza', 'data_contab', 'data_anulare'] as $column) {
            $table->date($column)->nullable();
        }
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

    expect($result['invoices'])->toBe(1)
        ->and($result['refreshed'])->toBe(1)
        ->and((float) $old->val_mon_paid)->toBe(400.0)
        ->and((float) $old->val_mon_storno)->toBe(600.0)
        ->and($old->outstandingAmount())->toBe(0.0)
        ->and($old->payments()->count())->toBe(1)
        ->and(Invoice::where('nr_doc', 'NEW')->exists())->toBeTrue();
});
