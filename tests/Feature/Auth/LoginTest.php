<?php

use App\Models\User;

test('a user can login with correct credentials', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);
});

test('login fails with incorrect credentials', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);
});

test('an authenticated user can fetch their own profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk()->assertJsonPath('data.email', $user->email);
});

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/logout');

    $response->assertNoContent();
    expect($user->tokens()->count())->toBe(0);
});
