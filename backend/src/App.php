<?php
declare(strict_types=1);
namespace App;

use App\Core\{Audit, Authz, Db, Env, Settings};
use App\Providers\{FcmPushProvider, HttpSmsProvider, NullPushProvider, NullSmsProvider, OsrmRouting, PushProvider, RoutingProvider, SmsProvider, StraightLineRouting};

/** حاوية بسيطة للخدمات المشتركة. تُستبدل المزودات في الاختبارات عبر الخصائص العامة. */
final class App
{
    public Db $db;
    public Settings $settings;
    public Audit $audit;
    public Authz $authz;
    public PushProvider $push;
    public SmsProvider $sms;
    public RoutingProvider $routing;
    public array $config;
    private static ?App $inst = null;

    public function __construct(Db $db, ?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__) . '/config/permissions.php';
        $this->db = $db;
        $this->settings = new Settings($db, $this->config['settings']);
        $this->audit = new Audit($db);
        $this->authz = new Authz($db);
        $this->push = ($sa = Env::get('FCM_SERVICE_ACCOUNT_JSON')) ? new FcmPushProvider($sa) : new NullPushProvider();
        $this->sms = Env::get('SMS_API_URL') ? new HttpSmsProvider(Env::get('SMS_API_URL'), Env::get('SMS_API_TOKEN', '') ?? '', Env::get('SMS_SENDER', 'Fleet') ?? '') : new NullSmsProvider();
        $this->routing = Env::get('ROUTING_OSRM_URL') ? new OsrmRouting(Env::get('ROUTING_OSRM_URL')) : new StraightLineRouting();
    }

    public static function boot(): self
    {
        return self::$inst ??= new self(Db::fromEnv());
    }

    public static function storageDir(): string
    {
        $d = Env::get('STORAGE_DIR') ?: dirname(__DIR__) . '/storage';
        if (!is_dir($d . '/uploads')) { @mkdir($d . '/uploads', 0750, true); }
        return $d;
    }
}
