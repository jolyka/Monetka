<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryLimitController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecurrenceController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::middleware('workspace')->group(function () {
        Route::get('overview', [OverviewController::class, 'index'])->name('overview');
        Route::get('stats', [StatsController::class, 'index'])->name('stats.index');
        Route::get('export/csv', [ExportController::class, 'csv'])->name('export.csv');
        Route::get('export/json', [ExportController::class, 'json'])->name('export.json');
        Route::get('workspaces/settings', [WorkspaceController::class, 'settings'])->name('workspaces.settings');
        Route::patch('workspaces/settings', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::post('workspaces/import', [WorkspaceController::class, 'import'])->name('workspaces.import');
        Route::post('workspaces/leave', [WorkspaceController::class, 'leave'])->name('workspaces.leave');
        Route::post('workspaces/transfer', [WorkspaceController::class, 'transfer'])->name('workspaces.transfer');
        Route::delete('workspaces/members/{member}', [WorkspaceController::class, 'removeMember'])->name('workspaces.members.destroy');
        Route::delete('workspaces', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
        Route::get('dashboard', fn () => redirect()->route('overview'))->name('dashboard');
        Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
        Route::post('workspaces/invitations', [InvitationController::class, 'store'])->name('invitations.store');

        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::post('accounts/{account}/balance', [AccountController::class, 'setBalance'])->name('accounts.balance');
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        Route::post('banks', [BankController::class, 'store'])->name('banks.store');
        Route::patch('banks/{bank}', [BankController::class, 'update'])->name('banks.update');
        Route::delete('banks/{bank}', [BankController::class, 'destroy'])->name('banks.destroy');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('goals', [GoalController::class, 'index'])->name('goals.index');
        Route::post('goals', [GoalController::class, 'store'])->name('goals.store');
        Route::patch('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
        Route::delete('goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');
        Route::post('goals/{goal}/contribute', [GoalController::class, 'contribute'])->name('goals.contribute');
        Route::post('goals/{goal}/withdraw', [GoalController::class, 'withdraw'])->name('goals.withdraw');

        Route::get('debts', [DebtController::class, 'index'])->name('debts.index');
        Route::post('debts', [DebtController::class, 'store'])->name('debts.store');
        Route::patch('debts/{debt}', [DebtController::class, 'update'])->name('debts.update');
        Route::delete('debts/{debt}', [DebtController::class, 'destroy'])->name('debts.destroy');
        Route::post('debts/{debt}/repay', [DebtController::class, 'repay'])->name('debts.repay');

        Route::get('recurrences', [RecurrenceController::class, 'index'])->name('recurrences.index');
        Route::post('recurrences', [RecurrenceController::class, 'store'])->name('recurrences.store');
        Route::patch('recurrences/{recurrence}', [RecurrenceController::class, 'update'])->name('recurrences.update');
        Route::post('recurrences/{recurrence}/post', [RecurrenceController::class, 'post'])->name('recurrences.post');
        Route::post('recurrences/{recurrence}/deactivate', [RecurrenceController::class, 'deactivate'])->name('recurrences.deactivate');

        Route::get('limits', [CategoryLimitController::class, 'index'])->name('limits.index');
        Route::post('limits', [CategoryLimitController::class, 'store'])->name('limits.store');
        Route::patch('limits/{categoryLimit}', [CategoryLimitController::class, 'update'])->name('limits.update');
        Route::delete('limits/{categoryLimit}', [CategoryLimitController::class, 'destroy'])->name('limits.destroy');

        Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
        Route::post('assistant', [AssistantController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('assistant.store');
        Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'conversation'])
            ->name('assistant.conversations.show');

        Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::post('receipts/connect', [ReceiptController::class, 'connect'])
            ->middleware('throttle:5,1')
            ->name('receipts.connect');
        Route::get('receipts/status', [ReceiptController::class, 'status'])
            ->middleware('throttle:30,1')
            ->name('receipts.status');
        Route::post('receipts', [ReceiptController::class, 'lookup'])->name('receipts.lookup');
        Route::post('receipts/import', [ReceiptController::class, 'import'])->name('receipts.import');
        Route::redirect('receipts/test', '/receipts');

        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::post('transactions/income', [TransactionController::class, 'income'])->name('transactions.income');
        Route::post('transactions/expense', [TransactionController::class, 'expense'])->name('transactions.expense');
        Route::post('transactions/transfer', [TransactionController::class, 'transfer'])->name('transactions.transfer');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
        Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('invitations/{invitation:token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{invitation:token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::post('invitations/{invitation:token}/decline', [InvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
    Route::put('ai', [AiSettingsController::class, 'update'])->name('ai.update');
});
