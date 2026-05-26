<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Graduation Manager API',
    description: 'API hệ thống quản lý thực tập và đồ án'
)]

#[OA\Server(
    url: 'http://127.0.0.1:8000',
    description: 'Local Server'
)]

#[OA\SecurityScheme(
    securityScheme: 'BearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Nhập Access Token'
)]

class OpenApiSpec
{
}