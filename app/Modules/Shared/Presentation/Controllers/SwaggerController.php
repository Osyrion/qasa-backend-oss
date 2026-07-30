<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API documentation for QASA Laravel application - invoicing and client management system',
    title: 'QASA API Documentation',
    contact: new OA\Contact(name: 'QASA Support', email: 'support@qasa.sk'),
    license: new OA\License(name: 'MIT', url: 'https://opensource.org/licenses/MIT'),
)]
#[OA\Server(
    url: 'http://localhost:8000',
    description: 'QASA API Server',
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
        return response()->json(['message' => 'QASA API Documentation']);
    }
}
