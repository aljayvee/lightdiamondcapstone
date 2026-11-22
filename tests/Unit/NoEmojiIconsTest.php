<?php

declare(strict_types=1);

namespace Wbpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wbpms\Http\View\Icon;

/**
 * Emoji and Unicode symbols used as icons (🖨 ⬇ 💵 ⚙ ♟ ☰ ✓ …) render
 * differently on every device and made the UI look unfinished. Icons come
 * from Icon::svg(); this test keeps emoji out of the pages and page scripts.
 *
 * Allowed: text arrows inside content (date ranges "11/14 → 11/20",
 * "Before → After"), and the ✓ marks printed onto the BDO deposit slip.
 */
final class NoEmojiIconsTest extends TestCase
{
    /** Views no controller renders any more (kept for reference only). */
    private const UNUSED_VIEWS = [
        'attendance/index.php', 'employees/index.php', 'payroll/index.php', 'requests/index.php',
        'salary/index.php', 'schedule/index.php', 'reports/index.php', 'benefits/index.php', 'stub.php',
        'dashboard/businessowner.php', 'dashboard/employee.php', 'dashboard/hrhead.php', 'employee/requests.php',
    ];

    /** Pictographs, dingbats, misc symbols/technical, geometric shapes, icon arrows, fullwidth plus. */
    private const PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{25A0}-\x{25FF}\x{2600}-\x{27BF}'
        . '\x{27C0}-\x{27FF}\x{2295}-\x{2299}\x{2B00}-\x{2BFF}\x{FF0B}\x{21A9}\x{21AA}\x{21BB}\x{2191}\x{2193}\x{2139}]'
        . '|&#(?:x1F[0-9A-F]{3}|9[0-9]{3}|1[0-9]{4,5});/iu';

    public function testPagesAndScriptsUseSvgIconsNotEmoji(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = array_merge(
            $this->files($root . '/resources/views', 'php'),
            $this->files($root . '/app/Http', 'php'),
            $this->files($root . '/public/assets', 'js'),
        );
        self::assertNotEmpty($files);

        $hits = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            if (in_array(substr($relative, strlen('resources/views/')), self::UNUSED_VIEWS, true)
                && str_starts_with($relative, 'resources/views/')) {
                continue;
            }
            foreach (file($file) ?: [] as $number => $line) {
                $trim = ltrim($line);
                if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
                    continue; // comments may describe what was replaced
                }
                if (str_contains($line, 'class="d chk"')) {
                    continue; // printed tick on the BDO deposit slip
                }
                if (preg_match(self::PATTERN, $line, $m) === 1) {
                    $hits[] = $relative . ':' . ($number + 1) . ' ' . $m[0];
                }
            }
        }

        self::assertSame([], $hits, "Use Icon::svg('name') instead of emoji/symbol icons.");
    }

    public function testIconMarkupIsDecorativeAndFollowsTextColour(): void
    {
        $svg = Icon::svg('printer');

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('stroke="currentColor"', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
        self::assertStringContainsString('class="icon icon-printer"', $svg);

        $labelled = Icon::svg('search', 20, '', 'Search');
        self::assertStringContainsString('role="img" aria-label="Search"', $labelled);
        self::assertStringContainsString('width="20"', $labelled);
    }

    public function testEveryIconIsWellFormedSvg(): void
    {
        foreach (array_keys(Icon::PATHS) as $name) {
            $doc = new \DOMDocument();
            self::assertTrue(@$doc->loadXML(Icon::svg($name)), "icon {$name} is not well-formed");
        }
        self::assertSame(Icon::svg('help'), str_replace('icon-no-such-icon', 'icon-help', Icon::svg('no-such-icon')));
    }

    /** @return list<string> */
    private function files(string $dir, string $ext): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === $ext) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
