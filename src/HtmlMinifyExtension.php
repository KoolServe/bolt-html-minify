<?php

namespace Bolt\Extension\Koolserve\HtmlMinify;

use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

/**
 * HtmlMinify extension class.
 *
 * @author Chris Hilsdon <chris@koolserve.uk>
 */
class HtmlMinifyExtension extends SimpleExtension
{
    protected $response;

    protected $inlineTag = '<replaceTag>';

    protected function subscribe(EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addListener(KernelEvents::RESPONSE, function (FilterResponseEvent $event) {
            $app = $this->getContainer();
            $response = $event->getResponse();
            $request = $event->getRequest();

            if ($response instanceof StreamedResponse) {
                return $response;
            }

            // Only run if on the frontend and debug is disabled
            if (!Zone::isFrontend($request) || $app['config']->get('general/debug')) {
                return $response;
            }

            $contentType = $response->headers->get('Content-Type');

            // Don't minify images
            if (strpos($contentType, 'image') !== false) {
                return $response;
            }

            // Don't minify xml
            if (strpos($contentType, 'application/xml') !== false) {
                return $response;
            }

            // Don't minify JSON
            if ($contentType === 'application/json') {
                return $response;
            }

            // Minify and return the HTML
            $this->minify($response);

            return $response;
        }, -1025);
    }

    private function encodeCodeTag()
    {
        $content = $this->response->getContent();
        preg_match_all("/<code([^>]+)?>([^<]*)<\/code>/", $content, $out);
        if ($out[0] === []) {
            return true;
        }

        foreach ($out[0] as $code) {
            $newContent = $this->inlineTag . base64_encode($code) . $this->inlineTag;
            $content = str_replace($code, $newContent, $content);
        }
        $this->response->setContent($content);
    }

    private function decodeCodeTag()
    {
        $content = $this->response->getContent();
        preg_match_all("/" . $this->inlineTag . "([^<]*)" . $this->inlineTag . "/", $content, $out);
        if ($out[0] === []) {
            return true;
        }

        foreach ($out[1] as $key => $code) {
            $base64 = base64_decode($code);
            $content = str_replace($code, $base64, $content);
        }

        $this->response->setContent($content);
    }

    private function removeTempTags()
    {
        $replace = [
            '/\<replaceTag\>/' => ''
        ];

        $content = preg_replace(array_keys($replace), array_values($replace), $this->response->getContent());
        $this->response->setContent($content);
    }

    private function beforeMinify()
    {
        $this->encodeCodeTag();
    }

    private function afterMinify()
    {
        $this->decodeCodeTag();
        $this->removeTempTags();
    }

    private function minify(Response $response)
    {
        $this->response = $response;
        $this->beforeMinify();

        $replace = [
            // Remove HTML comments
            '/<!--(.*?)-->/s' => '',
            // Remove tabs before and after HTML tags
            '/\>[^\S ]+/s' => '>',
            '/[^\S ]+\</s' => '<',
            // Shorten multiple whitespace sequences; keep new-line characters because they matter in JS!!!
            '/([\t ])+/s' => ' ',
            // Remove leading and trailing spaces
            '/^([\t ])+/m' => '',
            '/([\t ])+$/m' => '',
            // Remove empty lines (sequence of line-end and white-space characters)
            '/[\r\n]+([\t ]?[\r\n]+)+/s' => "\n",
            // Remove empty lines (between HTML tags); cannot remove just any line-end characters because in inline JS they can matter!
            '/\>[\r\n\t ]+\</s' => '><',
            // Remove "empty" lines containing only JS's block end character; join with next line (e.g. "}\n}\n</script>" --> "}}</script>"
            '/}[\r\n\t ]+/s' => '}',
            '/}[\r\n\t ]+,[\r\n\t ]+/s' => '},',
            // Remove new-line after JS's function or condition start; join with next line
            '/\)[\r\n\t ]?{[\r\n\t ]+/s' => '){',
            '/,[\r\n\t ]?{[\r\n\t ]+/s' => ',{',
            // Remove new-line after JS's line end (only most obvious and safe cases)
            '/\),[\r\n\t ]+/s' => '),',
            // Remove quotes from HTML attributes that does not contain spaces; keep quotes around URLs!
            // $1 and $4 insert first white-space character found before/after attribute
            '~([\r\n\t ])?([a-zA-Z0-9]+)="([a-zA-Z0-9_/\\-]+)"([\r\n\t ])?~s' => '$1$2=$3$4',
            // Remove spaces at the end of HTML elements
            '/" \/\>/' => '"/>',
            '/\' \/\>/' => '\'/>',
            // Remove any remaning new lines
            '/\r?\n|\r/' => ' '
        ];

        $content = preg_replace(array_keys($replace), array_values($replace), $this->response->getContent());
        $this->response->setContent($content);
        $this->afterMinify();

        return $this->response;
    }
}