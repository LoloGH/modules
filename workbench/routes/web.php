<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\DemoUser;

/*
| Application hôte de démonstration : une page pour choisir un profil (sans
| mot de passe) et la « page de connexion » vers laquelle le module renvoie
| les visiteurs. Sert uniquement au développement.
*/

Route::get('/', fn () => redirect('/dev'));

Route::get('/connexion', fn () => redirect('/dev'))->name('login');

Route::get('/dev', function () {
    $items = DemoUser::query()->orderBy('id')->get()->map(fn (DemoUser $user): string => sprintf(
        '<li style="margin:.4rem 0"><a href="%s">%s</a> <small>(%s)</small></li>',
        e(url('/dev/login/'.$user->id)),
        e($user->name),
        e($user->getRoleNames()->implode(', ')),
    ))->implode('');

    $current = Auth::user();
    $status = $current === null
        ? '<p>Vous n\'êtes connecté avec aucun profil.</p>'
        : sprintf('<p>Connecté : <strong>%s</strong> — <a href="/finance">ouvrir le module</a> · <a href="/dev/logout">se déconnecter</a></p>', e($current->name));

    return response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>Démonstration Keneya Finance</title>'
        .'<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem">'
        .'<h1>Démonstration Keneya Finance</h1>'
        .'<p>Application hôte de test. Choisissez un profil pour vous connecter, sans mot de passe :</p>'
        .'<ul>'.($items === '' ? '<li>Aucun profil : lancez <code>finance:demo-setup</code>.</li>' : $items).'</ul>'
        .$status
        .'</body>'
    );
});

Route::get('/dev/login/{user}', function (DemoUser $user) {
    Auth::login($user);

    return redirect('/finance');
});

Route::get('/dev/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/dev');
});
