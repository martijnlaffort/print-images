<?php

use App\Livewire\BatchExporter;
use App\Livewire\Dashboard;
use App\Livewire\MockupGenerator;
use App\Livewire\Settings;
use App\Livewire\TemplateEditor;
use App\Livewire\TemplateList;
use App\Livewire\UpscaleQueue;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class);
Route::get('/upscale', UpscaleQueue::class);
Route::get('/mockups', MockupGenerator::class);
Route::get('/templates', TemplateList::class);
Route::get('/templates/create', TemplateEditor::class);
Route::get('/templates/{id}/edit', TemplateEditor::class);
Route::get('/export', BatchExporter::class);
Route::get('/settings', Settings::class);
