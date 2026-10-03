<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API documentation for ZOAD Laravel application - invoicing and client management system',
    title: 'ZOAD API Documentation',
    contact: new OA\Contact(name: 'ZOAD Support', email: 'support@zoad.sk'),
    license: new OA\License(name: 'MIT', url: 'https://opensource.org/licenses/MIT'),
)]
#[OA\Server(
    url: 'http://localhost:8000',
    description: 'ZOAD API Server',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    description: 'Enter token in format: Bearer {token}',
    bearerFormat: 'JWT',
    scheme: 'bearer',
)]
#[OA\PathItem(path: '/api')]
class SwaggerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'ZOAD API Documentation']);
    }
}
