<?php

arch('no debug statements are left in application code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('controllers extend the base controller')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toExtend('App\Http\Controllers\Controller')
    ->ignoring('App\Http\Controllers\Controller');

arch('models live in App\Models and do not depend on controllers')
    ->expect('App\Models')
    ->not->toUse('App\Http\Controllers');

arch('services do not depend on HTTP controllers')
    ->expect('App\Services')
    ->not->toUse('App\Http\Controllers');

arch('the codebase does not use PHP\'s error suppression operator')
    ->expect('App')
    ->not->toUse('@');
