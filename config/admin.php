<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usuário administrador inicial
    |--------------------------------------------------------------------------
    |
    | Credenciais usadas pelo AdminUserSeeder para criar (ou promover) o usuário
    | administrador que pode gerenciar as integrações. Defina ADMIN_EMAIL e
    | ADMIN_PASSWORD no .env; sem ambos, o seeder é ignorado.
    |
    */

    'name' => env('ADMIN_NAME', 'Admin'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
