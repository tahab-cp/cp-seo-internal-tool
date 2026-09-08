<?php

use Illuminate\Support\Facades\Route;

// The whole V1 application lives inside the Filament admin panel.
Route::redirect('/', '/admin');
