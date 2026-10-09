<?php

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Approvals\PaymentRunController;
use App\Http\Controllers\Approvals\RoutingController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\CashFlowOverrideController;
use App\Http\Controllers\CashFlowReportController;
use App\Http\Controllers\CharterContractController;
use App\Http\Controllers\CharterFlightController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\Contracts\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseStatusController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DueInvoicesController;
use App\Http\Controllers\EInvoiceController;
use App\Http\Controllers\EtripSupplierController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceCheckController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OpExController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentCheckController;
use App\Http\Controllers\PaymentExportController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\PnlController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Fiecare intră în casa lui: panoul principal e al celor care țin facturile
// companiei, deci cine n-are voie acolo ar fi fost întâmpinat de un refuz.
Route::get('/', HomeController::class)->name('home');

/*
 * Contractul trimis cuiva pe mail: se deschide din legătură, fără cont. Cel
 * căruia i s-a trimis poate fi și din afara companiei; legătura e lungă, se
 * poate stinge la o dată anume, iar deschiderile se numără.
 */
Route::get('contracte/{token}', [ContractController::class, 'shared'])->name('contracts.shared');
Route::get('contracte/{token}/fisier', [ContractController::class, 'sharedFile'])->name('contracts.shared.file');

Route::middleware('auth')->group(function () {
    // Panoul principal adună facturile companiei, deci e al celor care le țin.
    Route::middleware('area:dashboard')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });

    Route::resource('companies', CompanyController::class)->except('show')->middleware('role:admin');
    Route::get('users/import', [UserController::class, 'importForm'])->name('users.import')->middleware('role:admin');
    Route::post('users/import', [UserController::class, 'import'])->name('users.import.store')->middleware('role:admin');
    Route::resource('users', UserController::class)->except(['show', 'create', 'store'])->middleware('role:admin');
    Route::post('companies/sync', [SyncController::class, 'storeAll'])->name('companies.sync-all')->middleware('role:admin');
    Route::post('companies/{company}/sync', [SyncController::class, 'store'])->name('companies.sync')->middleware('role:admin');
    Route::get('etrip/{connection}/suppliers', [EtripSupplierController::class, 'search'])->name('etrip.suppliers.search');
    Route::post('etrip/{connection}/suppliers/sync', [EtripSupplierController::class, 'sync'])->name('etrip.suppliers.sync');
    Route::post('etrip-suppliers/sync', [EtripSupplierController::class, 'syncAll'])->name('etrip-suppliers.sync-all');

    // Only the supplier side of OMC is mirrored: the old addresses land on it.
    // Facturile primite și fișa unei facturi: evidența Financiarului.
    Route::middleware('area:invoices')->group(function () {
        Route::redirect('invoices/issued', 'invoices/received');
        Route::get('invoices/received', [InvoiceController::class, 'primite'])->name('invoices.primite');
        Route::post('invoices/received/export', [InvoiceController::class, 'exportPrimite'])->name('invoices.primite.export');

    });

    // Fișierul de bancă.
    Route::middleware('area:payments')->group(function () {
        Route::post('payments/bt/prepare', [PaymentExportController::class, 'btPrepare'])->name('payments.bt.prepare');
        Route::post('payments/bt/download', [PaymentExportController::class, 'btDownload'])->name('payments.bt.download');
    });

    // Fișa unei facturi: o deschide oricine are treabă cu ea — omul care o
    // aprobă, Financiarul care o ține, Trezoreria care o plătește. Ce vede
    // fiecare rămâne limitat la departamentele lui. Lista întreagă a
    // facturilor rămâne la Financiar. Statusul plății vine din OMC și nu se
    // marchează de aici, deci nu are rută.
    Route::middleware('area:invoice')->group(function () {
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/comments', [InvoiceController::class, 'comment'])->name('invoices.comments.store');
    });

    // e-Facturile ANAF: controlul că ce s-a trimis a ajuns în contabilitate.
    Route::middleware('area:payments')->group(function () {
        Route::get('e-invoices', [EInvoiceController::class, 'index'])->name('e-invoices.index');
        Route::post('e-invoices/export', [EInvoiceController::class, 'export'])->name('e-invoices.export');
    });
    Route::get('e-invoices/{eInvoice}/detail', [EInvoiceController::class, 'detail'])->name('e-invoices.detail');
    Route::get('e-invoices/{eInvoice}/parsed', [EInvoiceController::class, 'parsed'])->name('e-invoices.parsed');
    Route::get('e-invoices/{eInvoice}/candidates', [EInvoiceController::class, 'candidates'])->name('e-invoices.candidates');
    Route::post('e-invoices/{eInvoice}/match', [EInvoiceController::class, 'match'])->name('e-invoices.match');

    // Căsuța de aprobări: toată lumea în afară de trezorerie, care plătește
    // ce s-a aprobat deja.
    Route::middleware('area:approvals')->group(function () {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('approvals/export', [ApprovalController::class, 'export'])->name('approvals.export');
        Route::post('approvals/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
        Route::post('approvals/final', [ApprovalController::class, 'decideFinal'])->name('approvals.final');
        Route::post('approvals/invoices/{invoice}/reopen', [ApprovalController::class, 'reopen'])->name('approvals.reopen');
        Route::post('approvals/redirect', [ApprovalController::class, 'redirect'])->name('approvals.redirect');
    });

    // Rulajele de plată: Financiar le pregătește, Trezoreria le plătește.
    Route::middleware('area:payments')->group(function () {
        Route::get('payment-runs', [PaymentRunController::class, 'index'])->name('payment-runs.index');
        Route::post('payment-runs', [PaymentRunController::class, 'store'])->name('payment-runs.store');
        Route::get('payment-runs/{run}', [PaymentRunController::class, 'show'])->name('payment-runs.show');
        Route::post('payment-runs/{run}/items/{item}', [PaymentRunController::class, 'toggle'])->name('payment-runs.items.toggle');
        Route::post('payment-runs/{run}/exported', [PaymentRunController::class, 'exported'])->name('payment-runs.exported');
        Route::post('payment-runs/{run}/close', [PaymentRunController::class, 'close'])->name('payment-runs.close');
        // Ștergerea unui rulaj e pentru greșeli, nu pentru evidență: o face
        // doar administratorul.
        Route::delete('payment-runs/{run}', [PaymentRunController::class, 'destroy'])->name('payment-runs.destroy')->middleware('role:admin');
    });

    // Echipa proprie: un om operațional își aduce colegii pe departamentul lui.
    Route::middleware('area:team')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
    });

    // Rutarea pe departamente și regulile ei sunt ale Financiarului.
    Route::middleware('area:routing')->group(function () {
        Route::get('routing', [RoutingController::class, 'index'])->name('routing.index');
        Route::post('routing/invoices/{invoice}/assign', [RoutingController::class, 'assign'])->name('routing.assign');
        Route::post('routing/assign', [RoutingController::class, 'assignMany'])->name('routing.assign-many');
        Route::post('routing/invoices/{invoice}/release', [RoutingController::class, 'release'])->name('routing.release');
        Route::post('routing/rules', [RoutingController::class, 'storeRule'])->name('routing.rules.store');
        Route::put('routing/rules/{rule}', [RoutingController::class, 'updateRule'])->name('routing.rules.update');
        Route::delete('routing/rules/{rule}', [RoutingController::class, 'destroyRule'])->name('routing.rules.destroy');
        Route::post('routing/rerun', [RoutingController::class, 'rerun'])->name('routing.rerun');
    });

    // Extrasele bancare sunt tot ale plăților.
    Route::middleware('area:payments')->group(function () {
        Route::get('bank-statements', [BankStatementController::class, 'index'])->name('bank-statements.index');
        // Înaintea rutei cu parametru, altfel „transactions” ar fi luat drept
        // numărul unui extras.
        Route::get('bank-statements/transactions', [BankStatementController::class, 'transactions'])->name('bank-statements.transactions');
        Route::get('bank-statements/{bankStatement}', [BankStatementController::class, 'show'])->name('bank-statements.show');
    });

    // Furnizorii și legăturile lor țin de evidența facturilor.
    Route::middleware('area:invoices')->group(function () {
        Route::get('suppliers', [PartnerController::class, 'furnizori'])->name('partners.furnizori');
        Route::get('suppliers/search', [PartnerController::class, 'search'])->name('partners.search');
        Route::get('suppliers/{partner}', [PartnerController::class, 'show'])->name('partners.show');
        Route::get('suppliers/{partner}/payment-check', [PartnerController::class, 'paymentCheck'])->name('partners.payment-check');
        Route::post('partners/{partner}/etrip-supplier', [EtripSupplierController::class, 'link'])->name('partners.etrip-supplier.link');
        Route::delete('partners/{partner}/etrip-supplier', [EtripSupplierController::class, 'unlink'])->name('partners.etrip-supplier.unlink');
    });

    // Verificările de plăți și registrul de cereri: Financiar și Trezorerie.
    Route::middleware('area:payments')->group(function () {
        Route::get('payment-checks', [PaymentCheckController::class, 'index'])->name('payment-checks.index');
        Route::get('payment-checks/check', [PaymentCheckController::class, 'check'])->name('payment-checks.check');
        Route::get('payment-checks/expected', [PaymentCheckController::class, 'expected'])->name('payment-checks.expected');
        Route::get('payment-checks/reconcile', [PaymentCheckController::class, 'reconcile'])->name('payment-checks.reconcile');
        Route::get('payment-checks/invoices', [InvoiceCheckController::class, 'index'])->name('payment-checks.invoices.index');
        Route::get('payment-checks/invoices/suppliers', [InvoiceCheckController::class, 'suppliers'])->name('payment-checks.invoices.suppliers');
        Route::get('payment-checks/invoices/check', [InvoiceCheckController::class, 'check'])->name('payment-checks.invoices.check');
        Route::get('payment-checks/invoices/open', [InvoiceCheckController::class, 'open'])->name('payment-checks.invoices.open');

        Route::get('payment-requests', [PaymentRequestController::class, 'index'])->name('payment-requests.index');
        Route::post('payment-requests', [PaymentRequestController::class, 'store'])->name('payment-requests.store');
        Route::get('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show'])->name('payment-requests.show');
        Route::post('payment-requests/{paymentRequest}/status', [PaymentRequestController::class, 'updateStatus'])->name('payment-requests.status.update');
        Route::post('payment-requests/{paymentRequest}/comments', [PaymentRequestController::class, 'comment'])->name('payment-requests.comments.store');
        Route::post('payment-requests/{paymentRequest}/invoices', [PaymentRequestController::class, 'linkInvoice'])->name('payment-requests.invoices.link');
        Route::delete('payment-requests/{paymentRequest}/invoices/{invoice}', [PaymentRequestController::class, 'unlinkInvoice'])->name('payment-requests.invoices.unlink');
    });
    Route::redirect('clients', 'suppliers');

    // Rapoartele arată cifrele companiei întregi — venit, marjă, costuri,
    // profit — deci sunt ale Top Management-ului (și ale administratorilor,
    // fiindcă „admin” cuprinde toate rolurile).
    Route::middleware('role:'.User::ROLE_TOP_MANAGEMENT)->group(function () {
        Route::get('reports/pnl', [PnlController::class, 'index'])->name('reports.pnl.index');
        Route::post('reports/pnl/{company}/refresh', [PnlController::class, 'refresh'])->name('reports.pnl.refresh');
        Route::get('reports/pnl/{company}/details', [PnlController::class, 'details'])->name('reports.pnl.details');
        Route::get('reports/pnl/{company}/export', [PnlController::class, 'export'])->name('reports.pnl.export');
        Route::post('reports/pnl/{company}/move', [PnlController::class, 'move'])->name('reports.pnl.move');
        Route::delete('reports/pnl/{company}/overrides/{override}', [PnlController::class, 'forget'])->name('reports.pnl.overrides.forget');
        // Scadențarul: ce e de plătit de azi înainte, factură cu factură.
        Route::get('reports/due', [DueInvoicesController::class, 'index'])->name('reports.due.index');
        Route::get('reports/due/export', [DueInvoicesController::class, 'export'])->name('reports.due.export');
        Route::get('reports/opex', [OpExController::class, 'index'])->name('reports.opex.index');
        Route::post('reports/opex/{company}/refresh', [OpExController::class, 'refresh'])->name('reports.opex.refresh');
        Route::get('reports/opex/{company}/invoices', [OpExController::class, 'invoices'])->name('reports.opex.invoices');

        Route::get('reports/cash-flow', [CashFlowReportController::class, 'index'])->name('reports.cash-flow.index');
        Route::post('reports/cash-flow/build', [CashFlowReportController::class, 'build'])->name('reports.cash-flow.build');
        Route::get('reports/cash-flow/export', [CashFlowReportController::class, 'export'])->name('reports.cash-flow.export');
        Route::get('reports/cash-flow/drilldown', [CashFlowReportController::class, 'drilldown'])->name('reports.cash-flow.drilldown');
        Route::get('reports/cash-flow/documents', [CashFlowReportController::class, 'documents'])->name('reports.cash-flow.documents');
        Route::put('reports/cash-flow/parameters', [CashFlowReportController::class, 'parameters'])->name('reports.cash-flow.parameters');
        Route::put('reports/cash-flow/overrides', [CashFlowOverrideController::class, 'update'])->name('reports.cash-flow.overrides.update');
        Route::delete('reports/cash-flow/overrides', [CashFlowOverrideController::class, 'destroy'])->name('reports.cash-flow.overrides.destroy');
        Route::post('reports/cash-flow/contracts', [CharterContractController::class, 'store'])->name('reports.cash-flow.contracts.store');
        Route::put('reports/cash-flow/contracts/{contract}', [CharterContractController::class, 'update'])->name('reports.cash-flow.contracts.update');
        Route::delete('reports/cash-flow/contracts/{contract}', [CharterContractController::class, 'destroy'])->name('reports.cash-flow.contracts.destroy');
        Route::post('reports/cash-flow/flights', [CharterFlightController::class, 'store'])->name('reports.cash-flow.flights.store');
        Route::post('reports/cash-flow/flights/import', [CharterFlightController::class, 'import'])->name('reports.cash-flow.flights.import');
        Route::put('reports/cash-flow/flights/{flight}', [CharterFlightController::class, 'update'])->name('reports.cash-flow.flights.update');
        Route::delete('reports/cash-flow/flights/{flight}', [CharterFlightController::class, 'destroy'])->name('reports.cash-flow.flights.destroy');
    });

    // Asistentul interoghează baza și răspunde cu cifrele companiei — venit,
    // marjă, solduri. E un raport care vorbește, deci ține de aceeași regulă
    // ca tabul Rapoarte: Top Management și administratorii.
    Route::middleware('role:'.User::ROLE_TOP_MANAGEMENT)->group(function () {
        Route::get('ai-assistant', [AiChatController::class, 'index'])->name('ai-chat.index');
        Route::get('ai-assistant/{conversation}', [AiChatController::class, 'index'])->name('ai-chat.show');
        Route::post('ai-assistant/stream', [AiChatController::class, 'stream'])->name('ai-chat.stream');
        Route::delete('ai-assistant/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');
    });

    Route::get('database-status', [DatabaseStatusController::class, 'index'])->name('database-status.index')->middleware('role:admin');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index')->middleware('role:admin');
    Route::post('maintenance/upgrade', [MaintenanceController::class, 'upgrade'])->name('maintenance.upgrade')->middleware('role:admin');
    Route::post('maintenance/migrate', [MaintenanceController::class, 'migrate'])->name('maintenance.migrate')->middleware('role:admin');
    Route::post('maintenance/stop/{run}', [MaintenanceController::class, 'stop'])->name('maintenance.stop')->middleware('role:admin');

    /*
     * Repertoriul de contracte: rolul lui, tabul lui. Cine n-are rolul nu intră,
     * oricât ar ghici din adresă.
     */
    Route::middleware('role:contract_management,top_management')->group(function () {
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
        Route::post('contracts/{contract}/files', [ContractController::class, 'addFile'])->name('contracts.files.store');
        Route::get('contracts/{contract}/files/{file}', [ContractController::class, 'download'])->name('contracts.files.download');
        Route::get('contracts/{contract}/files/{file}/preview', [ContractController::class, 'preview'])->name('contracts.files.preview');
        Route::post('contracts/{contract}/ask', [ContractController::class, 'ask'])->name('contracts.ask');
        Route::post('contracts/{contract}/share', [ContractController::class, 'share'])->name('contracts.share');
        Route::post('contracts/{contract}/archive', [ContractController::class, 'archive'])->name('contracts.archive');
        // Ștergerea cu totul e a administratorului: ruta cere rolul, nu doar ecranul.
        Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy')->middleware('role:admin');
    });

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index')->middleware('role:admin');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store')->middleware('role:admin');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update')->middleware('role:admin');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy')->middleware('role:admin');
    Route::post('departments/{department}/members', [DepartmentController::class, 'attachMember'])->name('departments.members.attach')->middleware('role:admin');
    Route::put('departments/{department}/head', [DepartmentController::class, 'setHead'])->name('departments.head')->middleware('role:admin');
    Route::delete('departments/{department}/members/{user}', [DepartmentController::class, 'detachMember'])->name('departments.members.detach')->middleware('role:admin');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
