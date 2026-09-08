<?php
declare(strict_types=1);

namespace Sigil\Web\Pages;

use Sigil\App;
use Sigil\Web\Layout;
use Sigil\Util\BasePath;

final class LibraryPage
{
    public function __construct(private App $app)
    {
    }

    public function render(): void
    {
        $jsUrl = BasePath::url('/assets/js/library.js');

        $body = <<<HTML
        <section class="hero">
          <h1>Library</h1>
          <p>All renders, newest first. Click one to open it in the Studio.</p>
        </section>
        <div id="library" class="library"></div>
        <script type="module" src="{$jsUrl}"></script>
        HTML;

        Layout::render($this->app, ['title' => 'Library', 'nav' => 'library', 'css' => 'library.css', 'body' => $body]);
    }
}
