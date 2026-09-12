<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Http\InvalidCredentialsHttpException;
use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->name, $request->email, $request->password);

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('api')->plainTextToken,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->auth->attempt($request->email, $request->password);
        } catch (InvalidCredentialsException) {
            throw new InvalidCredentialsHttpException;
        }

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($request->user()));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
