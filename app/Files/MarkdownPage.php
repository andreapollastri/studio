<?php

namespace App\Files;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * A Markdown file of a repository as a page (the Doc tab): GitHub's flavour,
 * raw HTML stripped and unsafe links dropped, as the chat does. A relative
 * image or link points into the repository, so it goes through $resolve,
 * which turns a path of the repository into a URL (or null: left as text).
 */
class MarkdownPage
{
    /** @param  Closure(string): ?string  $resolve */
    public static function render(string $markdown, string $path, Closure $resolve): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use ($path, $resolve): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if (! $node instanceof Image && ! $node instanceof Link) {
                    continue;
                }

                $url = $node->getUrl();

                // absolute, an anchor of the page, mail: as written
                if ($url === '' || str_starts_with($url, '#') || preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $url)) {
                    continue;
                }

                $target = self::resolvePath(dirname($path), (string) strtok($url, '?#'));
                $node->setUrl(($target !== null ? $resolve($target) : null) ?? '#');
            }
        });

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }

    /** A path relative to a folder of the repository, or null when it climbs out of it. */
    private static function resolvePath(string $dir, string $relative): ?string
    {
        $segments = str_starts_with($relative, '/') || $dir === '.' ? [] : explode('/', $dir);

        foreach (explode('/', rawurldecode($relative)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
