<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Architecture\IdentityGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private IdentityGateway $identity) {}

    public function login(Request $request)
    {
        return response()->json($this->identity->login($request));
    }

    public function me()
    {
        return response()->json($this->identity->currentUser());
    }

    public function logout(Request $request)
    {
        return response()->json($this->identity->logout($request));
    }
}
