<?php

use App\Models\User;

test('a user can register and receives an api token', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonPath('user.role', 'user')
        ->assertJsonStructure(['user', 'token']);

    $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
});

test('registration fails with a duplicate email', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('registration is rate limited', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/register', [
            'name' => 'Jane Doe',
            'email' => "jane{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'overflow@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(429);
});
