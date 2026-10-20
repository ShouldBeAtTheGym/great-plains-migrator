<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GreatPlainsTabController;
use App\Http\Controllers\GpSetupController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\OdooSetupController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalespersonController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StepController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserFieldController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/guide', GuideController::class)->name('guide');
Route::get('/product', [ProductController::class, 'show'])->name('product');
Route::post('/install', [ProductController::class, 'install'])->name('install.store');

Route::middleware('intro.open')->group(function () {
    Route::get('/', [WelcomeController::class, 'show'])->name('welcome');
    Route::post('/start', [WelcomeController::class, 'begin'])->name('start');
    Route::get('/start/ready', [WelcomeController::class, 'ready'])->name('start.ready');
    Route::post('/start/ready', [WelcomeController::class, 'readyStore'])->name('start.ready.store');
    Route::get('/start/account', [WelcomeController::class, 'account'])->name('start.account');
    Route::post('/start/account', [WelcomeController::class, 'accountStore'])->name('start.account.store');
});

Route::middleware(['intro.open', 'setup.introduced'])->group(function () {
    Route::get('/start/overview', [WelcomeController::class, 'overview'])->name('start.overview');
    Route::post('/setup/odoo/next', [WelcomeController::class, 'finish'])->name('setup.odoo.next');
    Route::get('/setup/gp', [GpSetupController::class, 'show'])->name('setup.gp');
    Route::post('/setup/gp', [GpSetupController::class, 'test'])->name('setup.gp.test');
    Route::get('/setup/odoo', [OdooSetupController::class, 'show'])->name('setup.odoo');
    Route::post('/setup/odoo', [OdooSetupController::class, 'test'])->name('setup.odoo.test');
});

Route::middleware(['office.login', 'setup.ready'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/copies', [DashboardController::class, 'copies'])->name('copies');
    Route::post('/copies', [DashboardController::class, 'startCopies'])->name('copies.start');
    Route::get('/setup/great-plains', [GreatPlainsTabController::class, 'show'])->name('setup.great-plains');
    Route::post('/setup/great-plains', [GreatPlainsTabController::class, 'store'])->name('setup.great-plains.store');
    Route::get('/steps/{key}', [StepController::class, 'show'])->name('steps.show');
    Route::get('/steps/{key}/copy', [StepController::class, 'copyStatus'])->name('steps.copy');
    Route::post('/steps/{key}/copy', [StepController::class, 'copyStart'])->name('steps.copy.start');
    Route::get('/steps/customer_price_lists/missing', [StepController::class, 'downloadMissingPriceLists'])->name('steps.price-lists.missing');
    Route::get('/steps/{key}/download/{part?}', [StepController::class, 'download'])->where('part', '[0-9]+')->name('steps.download');
    Route::get('/steps/{key}/problems', [StepController::class, 'problems'])->name('steps.problems');
    Route::post('/steps/{key}/company', [StepController::class, 'importCompany'])->name('steps.import-company');
    Route::post('/steps/{key}/confirm', [StepController::class, 'confirm'])->name('steps.confirm');
    Route::post('/steps/{key}/skip', [StepController::class, 'skip'])->name('steps.skip');
    Route::post('/steps/{key}/restart', [StepController::class, 'restart'])->name('steps.restart');
    Route::get('/sync', [SyncController::class, 'show'])->name('sync.show');
    Route::post('/sync/{key}/baseline', [SyncController::class, 'baseline'])->name('sync.baseline');
    Route::post('/sync/{key}/refresh', [SyncController::class, 'refresh'])->name('sync.refresh');
    Route::post('/sync/{key}/verify', [SyncController::class, 'verify'])->name('sync.verify');
    Route::get('/sync/{key}/download/{part?}', [SyncController::class, 'download'])->where('part', '[0-9]+')->name('sync.download');
    Route::get('/salespeople', [SalespersonController::class, 'show'])->name('salespeople.show');
    Route::get('/salespeople/download', [SalespersonController::class, 'download'])->name('salespeople.download');
    Route::post('/salespeople', [SalespersonController::class, 'update'])->name('salespeople.update');
    Route::get('/user-fields/{model}', [UserFieldController::class, 'show'])->name('user-fields.show');
    Route::post('/user-fields/{model}', [UserFieldController::class, 'update'])->name('user-fields.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::post('/settings/intro-reset', [SettingsController::class, 'resetIntro'])->name('settings.intro.reset');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
