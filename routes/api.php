<?php

use App\Http\Controllers\Api\Ea\EaApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user()->load('organization');
    });

    Route::prefix('v1')->group(function () {
        Route::prefix('ea')->name('api.ea.')->group(function () {
            // Catalogues
            Route::get('/capabilities', [EaApiController::class, 'capabilities'])->name('capabilities.index');
            Route::get('/capabilities/{capability}', [EaApiController::class, 'capabilityShow'])->name('capabilities.show');
            Route::post('/capabilities', [EaApiController::class, 'capabilityStore'])->name('capabilities.store');
            Route::get('/applications', [EaApiController::class, 'applications'])->name('applications.index');
            Route::get('/tech-components', [EaApiController::class, 'techComponents'])->name('tech-components.index');
            Route::get('/interfaces', [EaApiController::class, 'interfaces'])->name('interfaces.index');
            Route::get('/apis', [EaApiController::class, 'apis'])->name('apis.index');
            Route::get('/value-streams', [EaApiController::class, 'valueStreams'])->name('value-streams.index');
            Route::get('/processes', [EaApiController::class, 'processes'])->name('processes.index');
            Route::get('/info-domains', [EaApiController::class, 'infoDomains'])->name('info-domains.index');
            Route::get('/logical-entities', [EaApiController::class, 'logicalEntities'])->name('logical-entities.index');
            Route::get('/data-flows', [EaApiController::class, 'dataFlows'])->name('data-flows.index');
            Route::get('/zones', [EaApiController::class, 'zones'])->name('zones.index');
            Route::get('/principles', [EaApiController::class, 'principles'])->name('principles.index');
            Route::get('/standards', [EaApiController::class, 'standards'])->name('standards.index');
            Route::get('/initiatives', [EaApiController::class, 'initiatives'])->name('initiatives.index');
            Route::get('/plateaux', [EaApiController::class, 'plateaux'])->name('plateaux.index');

            // Analyses
            Route::get('/blast-radius', [EaApiController::class, 'blastRadius'])->name('blast-radius');
            Route::get('/viewpoints/{viewpoint}', [EaApiController::class, 'viewpoint'])->name('viewpoint');
            Route::get('/search', [EaApiController::class, 'search'])->name('search');
            Route::get('/scenarios', [EaApiController::class, 'scenarios'])->name('scenarios');
            Route::get('/applications/{application}/control-inheritance', [EaApiController::class, 'controlInheritance'])->name('control-inheritance');

            // Operations
            Route::post('/anomalies/run', [EaApiController::class, 'runAnomalies'])->name('anomalies.run');
            Route::post('/kris/recompute', [EaApiController::class, 'recomputeKris'])->name('kris.recompute');

            // Interop
            Route::get('/exchange/archimate', [EaApiController::class, 'exportArchiMate'])->name('exchange.archimate');
            Route::post('/import/csv', [EaApiController::class, 'importCsv'])->name('import.csv');

            // MCP
            Route::post('/mcp', [EaApiController::class, 'mcp'])->name('mcp');
            Route::get('/mcp/tools', [EaApiController::class, 'mcpTools'])->name('mcp.tools');

            // Spec
            Route::get('/openapi.json', [EaApiController::class, 'openapi'])->name('openapi');
        });
    });
});
