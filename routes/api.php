<?php

use App\Http\Controllers\ImportByPathController;
use Illuminate\Support\Facades\Route;

Route::post('/import-by-path', ImportByPathController::class);
