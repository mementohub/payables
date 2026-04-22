<?php

use App\Http\Controllers\CompanyController;
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
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('companies', CompanyController::class)->except('show');
    Route::resource('users', UserController::class)->except('show');
    Route::post('companies/{company}/sync', [SyncController::class, 'store'])->name('companies.sync');

    Route::get('facturi-emise', [InvoiceController::class, 'emise'])->name('invoices.emise');
    Route::get('facturi-primite', [InvoiceController::class, 'primite'])->name('invoices.primite');
    Route::get('facturi/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

    Route::get('furnizori', [PartnerController::class, 'furnizori'])->name('partners.furnizori');
    Route::get('furnizori/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::post('partners/{partner}/responsibles', [PartnerController::class, 'attachResponsible'])->name('partners.responsibles.attach');
    Route::delete('partners/{partner}/responsibles/{user}', [PartnerController::class, 'detachResponsible'])->name('partners.responsibles.detach');
    Route::get('clienti', [PartnerController::class, 'clienti'])->name('partners.clienti');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
