<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            User::select('id', 'user_id', 'name', 'email', 'role', 'created_at')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => 'required|string|max:50|unique:users,user_id',
            'name'     => 'required|string|max:100',
            'email'    => 'nullable|email|unique:users',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin,viewer',
        ]);

        $user = User::create($validated);

        return response()->json([
            'id'      => $user->id,
            'user_id' => $user->user_id,
            'name'    => $user->name,
            'email'   => $user->email,
            'role'    => $user->role,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => 'sometimes|string|max:50|unique:users,user_id,' . $user->id,
            'name'     => 'sometimes|string|max:100',
            'email'    => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role'     => 'sometimes|in:admin,viewer',
        ]);

        $user->update($validated);

        return response()->json([
            'id'      => $user->id,
            'user_id' => $user->user_id,
            'name'    => $user->name,
            'email'   => $user->email,
            'role'    => $user->role,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => '不能刪除自己'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }
}
