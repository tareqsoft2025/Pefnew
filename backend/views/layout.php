<?php /** @var array $user */ $nav = $nav ?? []; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'إدارة الحركة') ?> — إدارة الحركة والمهام الميدانية</title>
<link rel="stylesheet" href="/assets/css/app.css">
<?php if (!empty($map)): ?><link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css"><?php endif; ?>
</head>
<body>
<a class="skip" href="#main">تخطي إلى المحتوى</a>
<div class="app">
<aside class="side" aria-label="القائمة الرئيسية">
  <div class="brand"><i>🚐</i><span>إدارة الحركة</span></div>
  <nav>
  <?php $lastGroup = null; foreach ($nav as $n): if ($n['group'] !== $lastGroup) { echo '<div class="grp">' . e($n['group']) . '</div>'; $lastGroup = $n['group']; } ?>
    <a href="<?= e($n['href']) ?>" <?= ($active ?? '') === $n['key'] ? 'aria-current="page"' : '' ?>><span aria-hidden="true"><?= $n['icon'] ?></span><?= e($n['label']) ?></a>
  <?php endforeach; ?>
  </nav>
</aside>
<div>
  <header class="top">
    <div class="row"><button class="btn sec sm menu-btn" aria-label="القائمة" aria-expanded="false">☰</button><h1><?= e($title ?? '') ?></h1></div>
    <div class="row"><span class="mut"><?= e($user['full_name']) ?></span>
      <a class="btn sec sm" href="/account">حسابي</a>
      <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn sec sm">خروج</button></form></div>
  </header>
  <main id="main" class="main">
    <?php if (!empty($flash)): ?><div class="flash" role="status"><?= e($flash) ?></div><?php endif; ?>
    <?php if (!empty($flashErr)): ?><div class="flash e" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
</div>
<?php if (!empty($map)): ?><script src="/assets/vendor/leaflet/leaflet.js"></script><script src="/assets/js/map.js"></script><?php endif; ?>
<script src="/assets/js/app.js"></script>
<?= $scripts ?? '' ?>
</body></html>
