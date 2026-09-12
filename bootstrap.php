<?php
declare(strict_types=1);

use Sigil\Util\Env;

define('SIGIL_ROOT', __DIR__);
define('SIGIL_SRC', SIGIL_ROOT . '/src');
define('SIGIL_STORAGE', SIGIL_ROOT . '/storage');
define('SIGIL_CONFIG', SIGIL_ROOT . '/config');

// Minimal PSR-4-style autoloader for the Sigil\ namespace.
// No Composer, no external packages — easy to read and debug.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Sigil\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = SIGIL_SRC . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(SIGIL_ROOT . '/.env');

// Shared portfolio theme (Metronic look) — lives once at the workspace root.
// Optional on purpose: pages fall back to the app's own CSS if it is missing.
$raymourTheme = dirname(SIGIL_ROOT) . '/shared/inc/theme.php';
if (is_file($raymourTheme)) {
    require_once $raymourTheme;
}
unset($raymourTheme);

date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Santo_Domingo'));

$config = require SIGIL_CONFIG . '/app.php';
$prompts = require SIGIL_CONFIG . '/prompts.php';

// Returns a fully wired application container.
return new Sigil\App($config, $prompts);
