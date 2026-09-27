<?php
declare(strict_types=1);

namespace Sigil\Web;

use Sigil\App;
use Sigil\Util\BasePath;

/**
 * Page shell — mirrors metronic/dist/html/demo8 (sidebar + main card
 * surface). Page bodies keep Sigil's own classes, restyled by
 * public/assets/css/base.css to the same palette.
 */
final class Layout
{
    /**
     * @param array{title: string, nav: string, css: string, body: string} $page
     */
    public static function render(App $app, array $page): void
    {
        $webKey = (string) ($app->config()['app']['web_api_key'] ?? '');
        $title = htmlspecialchars($page['title'], ENT_QUOTES);
        $css = htmlspecialchars($page['css'], ENT_QUOTES);
        $active = static fn(string $nav): string => $page['nav'] === $nav ? ' active' : '';

        $base = BasePath::get();
        $baseMeta = '<meta name="base-path" content="' . htmlspecialchars($base, ENT_QUOTES) . '">';

        $keyMeta = $webKey !== ''
            ? '<meta name="api-key" content="' . htmlspecialchars($webKey, ENT_QUOTES) . '">'
            : '';

        $banner = '';
        if ($webKey === '') {
            $banner = '<div class="banner banner-warn">Web UI needs an API key: run <code>php bin/sigil key:create web</code>, then set <code>WEB_API_KEY</code> in <code>.env</code>.</div>';
        }

        // Shared chrome, unconditionally. bootstrap.php already guarantees
        // shared/inc/theme.php was loaded or the app refused to boot, so there is nothing to
        // test for here — a function_exists() guard would be exactly the silent-fallback shape
        // root CLAUDE.md rule 6 forbids, and it would hide a broken install as a blank page.
        $head = raymour_theme('light');
        $themeHead = $head['head']();
        $modeJs    = $head['mode_js']();
        $themeJs   = $head['scripts']();

        $home = BasePath::url('/');
        $navItem = static function (string $href, string $icon, string $label, string $act): string {
            $cls = 'kt-menu-link rounded-[9px] border border-transparent '
                 . 'kt-menu-item-active:border-border kt-menu-item-active:bg-background '
                 . 'kt-menu-link-hover:bg-background kt-menu-link-hover:border-border '
                 . 'w-[62px] h-[60px] flex flex-col justify-center items-center gap-1 p-2';
            return <<<HTML
        <div class="kt-menu-item{$act}">
         <a class="{$cls}" href="{$href}">
          <span class="kt-menu-icon kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground">
           <i class="ki-filled {$icon} text-xl"></i>
          </span>
          <span class="kt-menu-title text-xs kt-menu-item-here:text-primary kt-menu-item-active:text-primary kt-menu-link-hover:text-primary text-secondary-foreground font-medium">{$label}</span>
         </a>
        </div>
HTML;
        };

        echo '<!DOCTYPE html>
<html lang="en">
<head>
' . $themeHead . '<title>' . $title . ' — Sigil</title>
' . $baseMeta . '
' . $keyMeta . '
<link rel="stylesheet" href="' . BasePath::url('/assets/css/base.css') . '">
<link rel="stylesheet" href="' . BasePath::url('/assets/css/' . $css) . '">
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background [--header-height:60px] [--sidebar-width:90px] bg-muted">
' . $modeJs . '
<div class="flex grow">
  <header class="flex lg:hidden items-center fixed z-10 top-0 start-0 end-0 shrink-0 bg-muted h-(--header-height)" id="header">
    <div class="kt-container-fixed flex items-center justify-between flex-wrap gap-3">
      <a href="' . $home . '"><span class="text-base font-semibold text-mono">Sigil</span></a>
      <button class="kt-btn kt-btn-icon kt-btn-ghost -me-1" data-kt-drawer-toggle="#sidebar">
        <i class="ki-filled ki-menu"></i>
      </button>
    </div>
  </header>
  <div class="flex flex-col lg:flex-row grow pt-(--header-height) lg:pt-0">
    <div class="fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 bg-muted [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start flex" id="sidebar">
      <div class="hidden lg:flex items-center justify-center shrink-0 pt-8 pb-3.5" id="sidebar_header">
        <a href="' . $home . '"><span class="text-lg font-semibold text-mono">Sigil</span></a>
      </div>
      <div class="kt-scrollable-y-hover grow gap-2.5 shrink-0 flex items-center pt-5 lg:pt-0 ps-3 pe-3 lg:pe-0 flex-col" data-kt-scrollable="true" id="sidebar_menu_wrapper">
        <div class="kt-menu flex flex-col gap-2.5 grow" data-kt-menu="true" id="sidebar_menu">
' . $navItem(BasePath::url('/'), 'ki-abstract-39', 'Studio', $active('studio')) . '
' . $navItem(BasePath::url('/library'), 'ki-folder', 'Library', $active('library')) . '
' . $navItem(BasePath::url('/settings'), 'ki-setting-2', 'Settings', $active('settings')) . '
        </div>
      </div>
      <div class="flex flex-col gap-2.5 items-center shrink-0 pb-4" id="sidebar_footer">
      </div>
    </div>
    <div class="flex flex-col grow rounded-xl bg-background border border-input lg:ms-(--sidebar-width) mt-0 lg:mt-5 m-5">
      <div class="flex flex-col grow kt-scrollable-y-auto lg:[--scrollbar-width:auto] pt-5" id="scrollable_content">
        <main class="grow" role="content">
          <div class="pb-5">
            <div class="kt-container-fixed flex items-center justify-between flex-wrap gap-3">
              <div class="flex items-center flex-wrap gap-1 lg:gap-5">
                <h1 class="font-medium text-base text-mono">' . $title . '</h1>
              </div>
            </div>
          </div>
          <div class="kt-container-fixed">
            <div class="grid gap-5 lg:gap-7.5">
' . $banner . '
' . $page['body'] . '
            </div>
          </div>
        </main>
        <footer class="text-center text-xs text-muted-foreground pb-5 pt-5">
          Sigil · logo studio
        </footer>
      </div>
    </div>
  </div>
</div>
<script src="https://bifrost.raymour.lat/shared/bar.js" data-current="sigil" defer></script>
' . $themeJs . '</body>
</html>';
    }
}
