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
            User::select('id', 'name', 'email', 'role', 'created_at')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'       => 'sometimes|integer|min:1|unique:users,id',
            'name'     => 'required|string|max:100',
            'email'    => 'nullable|email|unique:users',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin,viewer',
        ]);

        $user = new User();
        if (isset($validated['id'])) {
            $user->id = $validated['id'];
        }
        $user->fill(array_diff_key($validated, ['id' => null]));
        $user->save();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'sometimes|string|max:100',
            'email'    => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role'     => 'sometimes|in:admin,viewer',
        ]);

        $user->update($validated);

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
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
