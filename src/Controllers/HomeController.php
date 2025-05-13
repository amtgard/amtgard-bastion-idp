<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HomeController
{
    /**
     * Display the home page.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function index(Request $request, Response $response): Response
    {
        $response->getBody()->write('
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Amtgard Identity Provider</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        line-height: 1.6;
                        margin: 0;
                        padding: 20px;
                        color: #333;
                    }
                    .container {
                        max-width: 800px;
                        margin: 0 auto;
                    }
                    h1 {
                        color: #2c3e50;
                    }
                    .btn {
                        display: inline-block;
                        background: #3498db;
                        color: #fff;
                        padding: 10px 20px;
                        margin: 5px 0;
                        border-radius: 5px;
                        text-decoration: none;
                    }
                    .btn:hover {
                        background: #2980b9;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>Amtgard Identity Provider</h1>
                    <p>Welcome to the Amtgard Identity Provider service. This service allows users to authenticate and authorize applications to access their Amtgard data.</p>
                    
                    <h2>User Actions</h2>
                    <p>
                        <a href="/auth/login" class="btn">Login</a>
                        <a href="/auth/register" class="btn">Register</a>
                    </p>
                    
                    <h2>About</h2>
                    <p>This service provides OAuth 2.0 authentication for Amtgard domains. It allows users to create accounts using their Google or Facebook credentials and then authorize applications to access their data.</p>
                </div>
            </body>
            </html>
        ');
        
        return $response;
    }
}