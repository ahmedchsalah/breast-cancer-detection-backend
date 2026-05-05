<?php

namespace App\Http\Controllers;

#[OA\Info(
    version: "1.0.0",
    description: "API documentation for the Medical AI platform",
    title: "Federated Learning API"
)]
#[OA\Server(
    url: "/api",
    description: "Primary API Server"
)]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
abstract class Controller
{
    //
}
