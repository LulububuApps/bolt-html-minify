<?php

namespace Bolt\Extension\Koolserve\HtmlMinify;

use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HtmlMinify extension class.
 *
 * @author Chris Hilsdon <chris@koolserve.uk>
 */
class HtmlMinifyExtension extends SimpleExtension
{
    /**
     * @param EventDispatcherInterface $dispatcher
     */
    protected function subscribe(EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addListener(
            KernelEvents::RESPONSE,
            function (FilterResponseEvent $event) {
                $response = $event->getResponse();

                if ($response instanceof StreamedResponse) {
                    return $response;
                }

                $contentType = $response->headers->get('Content-Type');

                if ($contentType !== 'text/html') {
                    return $response;
                }

                $content  = $response->getContent();
                $minified = $this->minify($content);

                $response->setContent($minified);

                return $response;
            },
            -1025
        );
    }

    /**
     * @param $content
     * @return string|string[]|null
     */
    private function minify($content)
    {
        $config       = $this->getConfig();
        $keepOneSpace = array_key_exists('keep_one_space', $config) ? $config['keep_one_space'] : false;

        $replace = [
            // Remove HTML comments
            '/<!--(.*?)-->/s'                                                 => '',
            // Remove tabs before and after HTML tags
            '/\>[^\S ]+/s'                                                    => '>',
            '/[^\S ]+\</s'                                                    => '<',
            // Shorten multiple whitespace sequences; keep new-line characters because they matter in JS!!!
            '/([\t ])+/s'                                                     => ' ',
            // Remove leading and trailing spaces
            '/^([\t ])+/m'                                                    => '',
            '/([\t ])+$/m'                                                    => '',
            // Remove empty lines (sequence of line-end and white-space characters)
            '/[\r\n]+([\t ]?[\r\n]+)+/s'                                      => "\n",
            // Remove empty lines (between HTML tags); cannot remove just any line-end characters because in inline JS they can matter!
            '/\>[\r\n\t ]+\</s'                                               => '>' . ($keepOneSpace ? ' ' : '') . '<',
            // Remove "empty" lines containing only JS's block end character; join with next line (e.g. "}\n}\n</script>" --> "}}</script>"
            '/}[\r\n\t ]+/s'                                                  => '}',
            '/}[\r\n\t ]+,[\r\n\t ]+/s'                                       => '},',
            // Remove new-line after JS's function or condition start; join with next line
            '/\)[\r\n\t ]?{[\r\n\t ]+/s'                                      => '){',
            '/,[\r\n\t ]?{[\r\n\t ]+/s'                                       => ',{',
            // Remove new-line after JS's line end (only most obvious and safe cases)
            '/\),[\r\n\t ]+/s'                                                => '),',
            // Remove quotes from HTML attributes that does not contain spaces; keep quotes around URLs!
            // $1 and $4 insert first white-space character found before/after attribute
            '~([\r\n\t ])?([a-zA-Z0-9]+)="([a-zA-Z0-9_/\\-]+)"([\r\n\t ])?~s' => '$1$2=$3$4',
            // Remove spaces at the end of HTML elements
            '/" \/\>/'                                                        => '"/>',
            '/\' \/\>/'                                                       => '\'/>',
            // Remove any remaning new lines
            '/\r?\n|\r/'                                                      => ' ',
        ];

        $minifiedContent = preg_replace(array_keys($replace), array_values($replace), $content);

        return $minifiedContent;
    }
}