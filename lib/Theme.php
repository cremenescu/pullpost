<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// The two looks of the app, chosen in Settings. A theme draws the frame of the pages, the Fetch
// page and the card of a post; History, Settings and the sign-in form are shared markup that each
// theme's stylesheet dresses. Both follow the system's light or dark setting.
abstract class Theme
{
    public const NAMES = ['a' => 'theme_a', 'b' => 'theme_b'];

    private static ?Theme $current = null;

    public static function id(): string
    {
        $id = (string) (Demo::on() ? (Demo::theme() ?? cfg('theme')) : (Db::meta('theme') ?: cfg('theme')));
        return isset(self::NAMES[$id]) ? $id : 'a';
    }

    public static function current(): Theme
    {
        if (self::$current === null) {
            self::$current = self::id() === 'b' ? new ThemeB() : new ThemeA();
        }
        return self::$current;
    }

    /** Used by the smoke test to draw both. */
    public static function force(?string $id): void
    {
        self::$current = $id === null ? null : ($id === 'b' ? new ThemeB() : new ThemeA());
    }

    /** The frame before the content of a signed-in page; $page is fetch, history or settings. */
    abstract public function shellStart(string $page): void;

    abstract public function shellEnd(): void;

    /** The Fetch page, with the post asked for open, or the latest posts when none is. */
    abstract public function fetchPage(?string $current): void;

    /** The card of a post, from Card::model(). */
    abstract public function card(array $m): string;

    /** A short line about the post for the theme's status display; empty when it has none. */
    public function status(array $m): string
    {
        return '';
    }

    /** The frame of the sign-in page. */
    abstract public function gateStart(): void;

    abstract public function gateEnd(): void;

    /** The engine's state in one line, for the frames. */
    public static function engineLine(): string
    {
        if (Demo::on()) {
            return t('demo_engine');
        }
        $h = Engine::health();
        if ($h === null) {
            return t('engine_down');
        }
        if (($h['busy'] ?? null) !== null) {
            return t('engine_busy', ['code' => (string) $h['busy']]);
        }
        return t('engine_ready', ['v' => self::chromiumVersion($h)]);
    }

    /** "131" out of "Chromium 131.0.6778.85" */
    public static function chromiumVersion(array $health): string
    {
        return preg_match('/(\d+)\.\d+/', (string) ($health['chromium'] ?? ''), $m) ? $m[1] : '';
    }
}

require __DIR__ . '/themes/A.php';
require __DIR__ . '/themes/B.php';
