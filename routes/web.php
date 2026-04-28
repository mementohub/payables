<?php

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::inertia('/', 'welcome')->name('home');

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('companies', CompanyController::class)->except('show');
    Route::resource('users', UserController::class)->except('show');
    Route::post('companies/{company}/sync', [SyncController::class, 'store'])->name('companies.sync');

    Route::get('facturi-emise', [InvoiceController::class, 'emise'])->name('invoices.emise');
    Route::get('facturi-primite', [InvoiceController::class, 'primite'])->name('invoices.primite');
    Route::get('facturi/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('facturi/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoices.approve');

    Route::get('efacturi', [EInvoiceController::class, 'index'])->name('e-invoices.index');
    Route::get('efacturi/{eInvoice}/detail', [EInvoiceController::class, 'detail'])->name('e-invoices.detail');
    Route::get('efacturi/{eInvoice}/parsed', [EInvoiceController::class, 'parsed'])->name('e-invoices.parsed');

    Route::get('extrase-bancare', [BankStatementController::class, 'index'])->name('bank-statements.index');
    Route::get('extrase-bancare/{bankStatement}', [BankStatementController::class, 'show'])->name('bank-statements.show');

    Route::get('furnizori', [PartnerController::class, 'furnizori'])->name('partners.furnizori');
    Route::get('furnizori/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::post('partners/{partner}/supervisor-departments', [PartnerController::class, 'attachSupervisorDepartment'])->name('partners.supervisor-departments.attach');
    Route::delete('partners/{partner}/supervisor-departments/{department}', [PartnerController::class, 'detachSupervisorDepartment'])->name('partners.supervisor-departments.detach');
    Route::get('clienti', [PartnerController::class, 'clienti'])->name('partners.clienti');

    Route::get('asistent-ai', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('asistent-ai/{conversation}', [AiChatController::class, 'index'])->name('ai-chat.show');
    Route::post('asistent-ai/stream', [AiChatController::class, 'stream'])->name('ai-chat.stream');
    Route::delete('asistent-ai/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');

    Route::get('departamente', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departamente', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departamente/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('departamente/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::post('departamente/{department}/members', [DepartmentController::class, 'attachMember'])->name('departments.members.attach');
    Route::delete('departamente/{department}/members/{user}', [DepartmentController::class, 'detachMember'])->name('departments.members.detach');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
