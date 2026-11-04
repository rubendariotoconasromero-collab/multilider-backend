<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Architecture\IdentityGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private IdentityGateway $identity) {}

    public function show()
    {
        return response()->json($this->identity->currentUser());
    }

    public function update(Request $request)
    {
        return response()->json($this->identity->updateProfile($request));
    }

    public function changePassword(Request $request)
    {
        return response()->json($this->identity->changePassword($request));
    }
}
