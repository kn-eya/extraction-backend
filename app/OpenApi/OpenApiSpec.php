<?php

namespace App\OpenApi;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Belgium Business Data Extractor API",
 *     version="1.0.0",
 *     description="API REST pour la recherche d entreprises belges",
 *     @OA\Contact(
 *         url="https://github.com/kn-eya/extraction-backend",
 *         name="Aya"
 *     )
 * )
 *
 * @OA\Server(
 *     url="http://localhost/api",
 *     description="Serveur de developpement local"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 *
 * @OA\Tag(name="Auth", description="Authentification")
 * @OA\Tag(name="Search", description="Recherche")
 * @OA\Tag(name="Companies", description="Entreprises")
 */
class OpenApiSpec
{
}