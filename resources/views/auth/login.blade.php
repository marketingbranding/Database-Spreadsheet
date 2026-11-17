<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — Marison Database</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Marison Database</h1>
            <p class="subtitle">Database multi-branch — masuk untuk melanjutkan.</p>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="auth-field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                    @error('email')<p class="error-text">{{ $message }}</p>@enderror
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    @error('password')<p class="error-text">{{ $message }}</p>@enderror
                </div>

                <label class="small" style="display:flex;align-items:center;gap:.4rem;margin-bottom:1rem;">
                    <input type="checkbox" name="remember" value="1"> Ingat saya
                </label>

                <button type="submit" class="btn">Masuk</button>
            </form>

            <p class="auth-hint">Akses disesuaikan: cabang hanya melihat proyek yang ditugaskan.</p>
        </section>
    </main>
</body>
</html>
