#!/usr/bin/env bash
# تثبيت/تحديث النظام على tarsof.top/Tasks دون GitHub Actions (يُشغَّل من Cron في لوحة الاستضافة).
# آمن للتكرار. لا يلمس إلا:
#   ~/tasks_src (نسخة المستودع)  ~/tasks_app (الكود + .env)  ~/tasks_data (المرفقات)  ~/public_html/Tasks
# لا يحذف أي ملف خارج هذه المجلدات، ولا يتصل إلا بقاعدة البيانات المكتوبة في ~/tasks_app/.env.
#
# أمر Cron (كل دقيقة أثناء التثبيت؛ يُنسخ السكربت قبل تشغيله لأن الخطوة 1 تحدّث المستودع):
#   cd ~ && { [ -d tasks_src/.git ] || git clone -q --depth 1 https://github.com/tareqsoft2025/pefnew tasks_src; } && cp tasks_src/deploy/server-install.sh .tasks_install.sh && bash .tasks_install.sh >> tasks_install.log 2>&1
#
# المراحل:
#   1) جلب آخر نسخة من main ونسخ الكود والملفات العامة.
#   2) إنشاء ~/tasks_app/.env من قالب (مرة واحدة) — تكتب أنت DB_PASS فيه من مدير الملفات.
#   3) عند وجود DB_PASS: تشغيل migrations (آمنة للتكرار).
#   4) عند وجود الملف ~/tasks_admin_password.txt: إنشاء حساب admin بكلمة المرور التي فيه ثم حذف الملف.
set -euo pipefail

H="${TASKS_HOME:-${HOME:?}}"
SRC="$H/tasks_src"
BRANCH="${TASKS_BRANCH:-main}"
APP="$H/tasks_app"
DATA="$H/tasks_data"
WEB="$H/public_html/Tasks"
PHP="${PHP_BIN:-php}"
log() { echo "[$(date '+%F %T')] $*"; }

[ -d "$H/public_html" ] || { log "لم يُعثر على $H/public_html"; exit 1; }
case "$WEB" in */public_html/Tasks) ;; *) log "مسار ويب غير متوقع: $WEB"; exit 1;; esac

# 1) الكود
git -C "$SRC" fetch -q --depth 1 origin "$BRANCH"
NEW="$(git -C "$SRC" rev-parse FETCH_HEAD)"
OLD="$(cat "$APP/.installed_rev" 2>/dev/null || true)"
if [ "$NEW" != "$OLD" ]; then
  git -C "$SRC" reset -q --hard "$NEW"
  mkdir -p "$APP" "$WEB"
  mkdir -p -m 750 "$DATA"
  # نسخ الكود (لا يوجد .env في المستودع، فلا يُستبدل .env الموجود)
  (cd "$SRC/backend" && tar --exclude='./tests' --exclude='./storage' --exclude='./vendor' --exclude='./.env' -cf - .) | (cd "$APP" && tar -xf -)
  mkdir -p "$APP/storage"

  # autoloader: composer إن وُجد، وإلا autoloader بسيط (لا اعتماديات وقت تشغيل)
  if command -v composer >/dev/null 2>&1; then
    (cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction -q)
  else
    mkdir -p "$APP/vendor"
    cat > "$APP/vendor/autoload.php" <<'PHPEOF'
<?php
// autoloader بديل عن composer (PSR-4: App\ => src/)
spl_autoload_register(static function (string $c): void {
    if (!str_starts_with($c, 'App\\')) { return; }
    $f = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
    if (is_file($f)) { require $f; }
});
require_once dirname(__DIR__) . '/src/helpers.php';
PHPEOF
  fi

  # الملفات العامة
  cp -a "$SRC/backend/public/assets" "$WEB/"
  cp -a "$SRC/backend/public/.htaccess" "$WEB/.htaccess"
  cat > "$WEB/index.php" <<'PHPEOF'
<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/tasks_app/public/index.php';
PHPEOF
  echo "$NEW" > "$APP/.installed_rev"
  log "تم تثبيت النسخة $NEW"
fi

# 2) .env (مرة واحدة)
if [ ! -f "$APP/.env" ]; then
  sed -e "s#^APP_BASE_PATH=.*#APP_BASE_PATH=/Tasks#" -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$DATA#" \
      "$APP/.env.example" > "$APP/.env"
  chmod 600 "$APP/.env"
  log "أُنشئ $APP/.env — اكتب فيه DB_NAME وDB_USER وDB_PASS للقاعدة الجديدة"
fi

dbpass="$(grep -E '^DB_PASS=' "$APP/.env" | head -1 | cut -d= -f2- || true)"
if [ -z "$dbpass" ]; then
  [ -f "$APP/.waiting_db" ] || { log "بانتظار DB_PASS في $APP/.env"; touch "$APP/.waiting_db"; }
  exit 0
fi
rm -f "$APP/.waiting_db"

# 3) migrations (عند كل نسخة جديدة أو أول مرة)
if [ "$(cat "$APP/.migrated_rev" 2>/dev/null || true)" != "$NEW" ]; then
  (cd "$APP" && "$PHP" bin/migrate.php)
  echo "$NEW" > "$APP/.migrated_rev"
  log "تم تشغيل migrations"
fi

# 4) حساب المدير (مرة واحدة، من ملف تكتبه أنت ثم يُحذف)
PWF="$H/tasks_admin_password.txt"
if [ -s "$PWF" ]; then
  pw="$(head -n1 "$PWF" | tr -d '\r\n')"
  rm -f "$PWF"
  if (cd "$APP" && ADMIN_PASSWORD="$pw" "$PHP" bin/create-admin.php admin "مدير النظام"); then
    log "تم إنشاء حساب admin وحُذف ملف كلمة المرور"
  else
    log "تعذر إنشاء حساب admin (ربما موجود مسبقًا). حُذف ملف كلمة المرور"
  fi
  unset pw
fi
