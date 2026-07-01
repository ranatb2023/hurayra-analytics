<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KlaviyoController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// AJAX endpoints powering the live filter bar.
Route::get('/api/metrics/summary', [MetricsController::class, 'summary'])->name('metrics.summary');
Route::get('/api/metrics/trend', [MetricsController::class, 'trend'])->name('metrics.trend');
Route::get('/api/metrics/sparklines', [MetricsController::class, 'sparklines'])->name('metrics.sparklines');
Route::get('/api/metrics/top-customers', [MetricsController::class, 'topCustomers'])->name('metrics.top_customers');
Route::get('/api/metrics/cohorts', [MetricsController::class, 'cohorts'])->name('metrics.cohorts');
Route::get('/api/metrics/export', [MetricsController::class, 'export'])->name('metrics.export');
Route::get('/report/client', [MetricsController::class, 'clientReport'])->name('report.client');

// Klaviyo email-performance tiles (reads stored snapshots; sync runs on a schedule).
Route::get('/api/klaviyo/tiles', [KlaviyoController::class, 'tiles'])->name('klaviyo.tiles');
Route::post('/api/klaviyo/refresh', [KlaviyoController::class, 'refresh'])->name('klaviyo.refresh');

// Upload + import history.
Route::get('/uploads', [UploadController::class, 'index'])->name('uploads.index');
Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
Route::delete('/uploads/{import}', [UploadController::class, 'destroy'])->name('uploads.destroy');
