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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseStatusController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EInvoiceController;
use App\Http\Controllers\EtripSupplierController;
use App\Http\Controllers\InvoiceCheckController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OpExController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentCheckController;
use App\Http\Controllers\PaymentExportController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

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
    Route::redirect('invoices/issued', 'invoices/received');
    Route::get('invoices/received', [InvoiceController::class, 'primite'])->name('invoices.primite');
    Route::post('invoices/received/export', [InvoiceController::class, 'exportPrimite'])->name('invoices.primite.export');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/comments', [InvoiceController::class, 'comment'])->name('invoices.comments.store');
    Route::post('invoices/{invoice}/payment-status', [InvoiceController::class, 'updatePaymentStatus'])->name('invoices.payment-status.update');

    Route::post('payments/bt/prepare', [PaymentExportController::class, 'btPrepare'])->name('payments.bt.prepare');
    Route::post('payments/bt/download', [PaymentExportController::class, 'btDownload'])->name('payments.bt.download');

    Route::get('e-invoices', [EInvoiceController::class, 'index'])->name('e-invoices.index');
    Route::post('e-invoices/export', [EInvoiceController::class, 'export'])->name('e-invoices.export');
    Route::get('e-invoices/{eInvoice}/detail', [EInvoiceController::class, 'detail'])->name('e-invoices.detail');
    Route::get('e-invoices/{eInvoice}/parsed', [EInvoiceController::class, 'parsed'])->name('e-invoices.parsed');
    Route::get('e-invoices/{eInvoice}/candidates', [EInvoiceController::class, 'candidates'])->name('e-invoices.candidates');
    Route::post('e-invoices/{eInvoice}/match', [EInvoiceController::class, 'match'])->name('e-invoices.match');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
    Route::post('approvals/final', [ApprovalController::class, 'decideFinal'])->name('approvals.final');
    Route::post('approvals/invoices/{invoice}/reopen', [ApprovalController::class, 'reopen'])->name('approvals.reopen');
    Route::post('approvals/redirect', [ApprovalController::class, 'redirect'])->name('approvals.redirect');

    Route::get('payment-runs', [PaymentRunController::class, 'index'])->name('payment-runs.index');
    Route::post('payment-runs', [PaymentRunController::class, 'store'])->name('payment-runs.store');
    Route::get('payment-runs/{run}', [PaymentRunController::class, 'show'])->name('payment-runs.show');
    Route::post('payment-runs/{run}/approve', [PaymentRunController::class, 'approve'])->name('payment-runs.approve');
    Route::post('payment-runs/{run}/items/{item}', [PaymentRunController::class, 'toggle'])->name('payment-runs.items.toggle');
    Route::post('payment-runs/{run}/exported', [PaymentRunController::class, 'exported'])->name('payment-runs.exported');
    Route::post('payment-runs/{run}/close', [PaymentRunController::class, 'close'])->name('payment-runs.close');

    Route::get('routing', [RoutingController::class, 'index'])->name('routing.index');
    Route::post('routing/invoices/{invoice}/assign', [RoutingController::class, 'assign'])->name('routing.assign');
    Route::post('routing/assign', [RoutingController::class, 'assignMany'])->name('routing.assign-many');
    Route::post('routing/invoices/{invoice}/release', [RoutingController::class, 'release'])->name('routing.release');
    Route::post('routing/rules', [RoutingController::class, 'storeRule'])->name('routing.rules.store');
    Route::put('routing/rules/{rule}', [RoutingController::class, 'updateRule'])->name('routing.rules.update');
    Route::delete('routing/rules/{rule}', [RoutingController::class, 'destroyRule'])->name('routing.rules.destroy');
    Route::post('routing/rerun', [RoutingController::class, 'rerun'])->name('routing.rerun');

    Route::get('bank-statements', [BankStatementController::class, 'index'])->name('bank-statements.index');
    Route::get('bank-statements/{bankStatement}', [BankStatementController::class, 'show'])->name('bank-statements.show');

    Route::get('suppliers', [PartnerController::class, 'furnizori'])->name('partners.furnizori');
    Route::get('suppliers/search', [PartnerController::class, 'search'])->name('partners.search');
    Route::get('suppliers/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::get('suppliers/{partner}/payment-check', [PartnerController::class, 'paymentCheck'])->name('partners.payment-check');
    Route::post('partners/{partner}/etrip-supplier', [EtripSupplierController::class, 'link'])->name('partners.etrip-supplier.link');
    Route::delete('partners/{partner}/etrip-supplier', [EtripSupplierController::class, 'unlink'])->name('partners.etrip-supplier.unlink');

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
    Route::redirect('clients', 'suppliers');

    Route::get('reports/opex', [OpExController::class, 'index'])->name('reports.opex.index');
    Route::post('reports/opex/{company}/refresh', [OpExController::class, 'refresh'])->name('reports.opex.refresh');
    Route::get('reports/opex/{company}/invoices', [OpExController::class, 'invoices'])->name('reports.opex.invoices');

    Route::get('reports/cash-flow', [CashFlowReportController::class, 'index'])->name('reports.cash-flow.index');
    Route::post('reports/cash-flow/build', [CashFlowReportController::class, 'build'])->name('reports.cash-flow.build');
    Route::get('reports/cash-flow/drilldown', [CashFlowReportController::class, 'drilldown'])->name('reports.cash-flow.drilldown');
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

    Route::get('ai-assistant', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('ai-assistant/{conversation}', [AiChatController::class, 'index'])->name('ai-chat.show');
    Route::post('ai-assistant/stream', [AiChatController::class, 'stream'])->name('ai-chat.stream');
    Route::delete('ai-assistant/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');

    Route::get('database-status', [DatabaseStatusController::class, 'index'])->name('database-status.index')->middleware('role:admin');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index')->middleware('role:admin');
    Route::post('maintenance/upgrade', [MaintenanceController::class, 'upgrade'])->name('maintenance.upgrade')->middleware('role:admin');
    Route::post('maintenance/migrate', [MaintenanceController::class, 'migrate'])->name('maintenance.migrate')->middleware('role:admin');
    Route::post('maintenance/stop/{run}', [MaintenanceController::class, 'stop'])->name('maintenance.stop')->middleware('role:admin');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index')->middleware('role:admin');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store')->middleware('role:admin');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update')->middleware('role:admin');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy')->middleware('role:admin');
    Route::post('departments/{department}/members', [DepartmentController::class, 'attachMember'])->name('departments.members.attach')->middleware('role:admin');
    Route::delete('departments/{department}/members/{user}', [DepartmentController::class, 'detachMember'])->name('departments.members.detach')->middleware('role:admin');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
