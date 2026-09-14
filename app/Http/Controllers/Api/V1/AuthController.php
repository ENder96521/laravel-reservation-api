<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    /**
     * Register
     *
     * Create a new user account and return a Sanctum API token.
     *
     * @unauthenticated
     *
     * @response 201 {
     *   "user": {"id": 1, "name": "Jane Doe", "email": "jane@example.com", "role": "user"},
     *   "token": "1|abcdef1234567890"
     * }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role' => 'user',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login
     *
     * Exchange email/password credentials for a new Sanctum API token.
     *
     * @unauthenticated
     *
     * @response 200 {
     *   "user": {"id": 1, "name": "Jane Doe", "email": "jane@example.com", "role": "user"},
     *   "token": "2|abcdef1234567890"
     * }
     * @response 401 {"message": "The provided credentials are incorrect."}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Logout
     *
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    /**
     * Current user
     *
     * Return the authenticated user's profile.
     *
     * @response 200 {
     *   "data": {"id": 1, "name": "Jane Doe", "email": "jane@example.com", "role": "user"}
     * }
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
