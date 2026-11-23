<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تسجيل الدخول — إدارة الحركة</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="login"><form class="card" method="post" action="/login" autocomplete="on">
<h1 style="margin-top:0">تسجيل الدخول</h1><p class="mut">منصة إدارة حركة المركبات والمهام الميدانية</p>
<?php if (!empty($error)): ?><div class="flash e" role="alert"><?= e($error) ?></div><?php endif; ?>
<input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<p><label for="u">اسم المستخدم</label><input id="u" name="username" required autofocus autocomplete="username" value="<?= e($username ?? '') ?>"></p>
<p><label for="p">كلمة المرور</label><input id="p" type="password" name="password" required autocomplete="current-password"></p>
<?php if (!empty($mfa)): ?><p><label for="m">رمز التحقق الثنائي</label><input id="m" name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" required autocomplete="one-time-code"></p><?php endif; ?>
<button class="btn" style="width:100%;justify-content:center">دخول</button>
</form></main></body></html>
