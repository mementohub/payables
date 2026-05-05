<?php

use App\Http\Controllers\ActiveCompanyController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OpExController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentExportController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('active-company', [ActiveCompanyController::class, 'update'])->name('active-company.update');

    Route::resource('companies', CompanyController::class)->except('show');
    Route::get('users/import', [UserController::class, 'importForm'])->name('users.import');
    Route::post('users/import', [UserController::class, 'import'])->name('users.import.store');
    Route::resource('users', UserController::class)->except(['show', 'create', 'store']);
    Route::post('companies/{company}/sync', [SyncController::class, 'store'])->name('companies.sync');

    Route::get('invoices/issued', [InvoiceController::class, 'emise'])->name('invoices.emise');
    Route::post('invoices/issued/export', [InvoiceController::class, 'exportEmise'])->name('invoices.emise.export');
    Route::get('invoices/received', [InvoiceController::class, 'primite'])->name('invoices.primite');
    Route::post('invoices/received/export', [InvoiceController::class, 'exportPrimite'])->name('invoices.primite.export');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoices.approve');

    Route::post('payments/bt/prepare', [PaymentExportController::class, 'btPrepare'])->name('payments.bt.prepare');
    Route::post('payments/bt/download', [PaymentExportController::class, 'btDownload'])->name('payments.bt.download');

    Route::get('e-invoices', [EInvoiceController::class, 'index'])->name('e-invoices.index');
    Route::post('e-invoices/export', [EInvoiceController::class, 'export'])->name('e-invoices.export');
    Route::get('e-invoices/{eInvoice}/detail', [EInvoiceController::class, 'detail'])->name('e-invoices.detail');
    Route::get('e-invoices/{eInvoice}/parsed', [EInvoiceController::class, 'parsed'])->name('e-invoices.parsed');
    Route::get('e-invoices/{eInvoice}/candidates', [EInvoiceController::class, 'candidates'])->name('e-invoices.candidates');
    Route::post('e-invoices/{eInvoice}/match', [EInvoiceController::class, 'match'])->name('e-invoices.match');

    Route::get('bank-statements', [BankStatementController::class, 'index'])->name('bank-statements.index');
    Route::get('bank-statements/{bankStatement}', [BankStatementController::class, 'show'])->name('bank-statements.show');

    Route::get('suppliers', [PartnerController::class, 'furnizori'])->name('partners.furnizori');
    Route::get('suppliers/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::post('partners/{partner}/responsabil-departments', [PartnerController::class, 'attachResponsabilDepartment'])->name('partners.responsabil-departments.attach');
    Route::delete('partners/{partner}/responsabil-departments/{department}', [PartnerController::class, 'detachResponsabilDepartment'])->name('partners.responsabil-departments.detach');
    Route::get('clients', [PartnerController::class, 'clienti'])->name('partners.clienti');

    Route::get('reports/opex', [OpExController::class, 'index'])->name('reports.opex.index');
    Route::post('reports/opex/{company}/refresh', [OpExController::class, 'refresh'])->name('reports.opex.refresh');
    Route::get('reports/opex/{company}/invoices', [OpExController::class, 'invoices'])->name('reports.opex.invoices');

    Route::get('ai-assistant', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('ai-assistant/{conversation}', [AiChatController::class, 'index'])->name('ai-chat.show');
    Route::post('ai-assistant/stream', [AiChatController::class, 'stream'])->name('ai-chat.stream');
    Route::delete('ai-assistant/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::post('departments/{department}/members', [DepartmentController::class, 'attachMember'])->name('departments.members.attach');
    Route::delete('departments/{department}/members/{user}', [DepartmentController::class, 'detachMember'])->name('departments.members.detach');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
