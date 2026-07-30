<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\OpenApi;

use OpenApi\Attributes as OA;

// Shared component schemas for the Statistics endpoints, kept separate so
// StatisticsController's own docblocks stay readable — L5-Swagger scans
// attributes across app/ regardless of which class they're attached to;
// this class is never instantiated.
#[OA\Schema(
    schema: 'StatisticsKpiBlock',
    properties: [
        new OA\Property(
            property: 'this_month',
            properties: [
                new OA\Property(property: 'value', type: 'number', format: 'float'),
                new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                new OA\Property(property: 'date_to', type: 'string', format: 'date'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'trend_vs_last_month_percent', type: 'number', format: 'float', nullable: true),
        new OA\Property(
            property: 'rolling_12m',
            properties: [
                new OA\Property(property: 'value', type: 'number', format: 'float'),
                new OA\Property(property: 'yoy_percent', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                new OA\Property(property: 'date_to', type: 'string', format: 'date'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'ytd',
            properties: [
                new OA\Property(property: 'value', type: 'number', format: 'float'),
                new OA\Property(property: 'yoy_percent', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'date_from', type: 'string', format: 'date'),
                new OA\Property(property: 'date_to', type: 'string', format: 'date'),
            ],
            type: 'object'
        ),
    ]
)]
#[OA\Schema(
    schema: 'StatisticsConcentration',
    properties: [
        new OA\Property(property: 'top1_share_percent', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'risk_level', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
        new OA\Property(property: 'pareto_count', type: 'integer', nullable: true),
    ]
)]
final class StatisticsSchemas {}
