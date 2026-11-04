<?php

namespace App\Contracts\Architecture;

use Illuminate\Http\Request;

interface IdentityGateway
{
    public function currentUser(): array;
    public function login(Request $request): array;
    public function logout(Request $request): array;
    public function updateProfile(Request $request): array;
    public function changePassword(Request $request): array;
}
