<?php

declare(strict_types=1);

namespace SesamePortal;

trait LoginPages
{
    private static function login(): void
    {
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Auth::login((string)Util::post('login'), (string)Util::post('password'))) {
                Util::redirect('/');
            }
            $error = self::t('login.invalid', 'Неверный логин или пароль');
        }

        self::layout(self::t('login.title', 'Вход'), function () use ($error) {
            echo '<section class="login-visual"><div><img src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"><p>' . self::t('login.subtitle', 'Портал видеонаблюдения SesameWare') . '</p></div>';
            echo '<div class="login-features"><span>' . Util::h(self::t('login.feature.secure', 'Безопасно')) . '</span><span>' . Util::h(self::t('login.feature.reliable', 'Надежно')) . '</span><span>' . Util::h(self::t('login.feature.efficient', 'Производительно')) . '</span></div></section>';
            echo '<section class="login-panel login-card">';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" autocomplete="username" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" autocomplete="current-password" required></label>';
            echo '<button class="primary">' . self::t('action.login', 'Войти') . '</button>';
            echo '</form>' . I18n::languageLinks() . '</section>';
        }, null);
    }

    private static function logout(): void
    {
        Auth::logout();
        Util::redirect('/login');
    }
}
