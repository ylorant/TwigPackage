<?php
/**
 * This file is part of Berlioz framework.
 *
 * @license   https://opensource.org/licenses/MIT MIT License
 * @copyright 2018 Ronan GIRON
 * @author    Ronan GIRON <https://github.com/ElGigi>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code, to the root.
 */

declare(strict_types=1);

namespace Berlioz\Package\Twig;

use Berlioz\Core\Asset\Assets;
use Berlioz\Core\Asset\EntryPoints;
use Berlioz\Core\Asset\Manifest;
use Berlioz\Core\Core;
use Berlioz\Core\CoreAwareInterface;
use Berlioz\Core\CoreAwareTrait;
use Berlioz\Package\Twig\Exception\AssetException;
use Berlioz\Package\Twig\Exception\PathException;
use Twig\Extension\AbstractExtension;

/**
 * Class TwigExtension.
 *
 * @package Berlioz\Package\Twig
 */
class TwigExtension extends AbstractExtension implements CoreAwareInterface
{
    use CoreAwareTrait;
    const H2PUSH_CACHE_COOKIE = 'h2pushes';
    /** @var array Cache for HTTP2 push */
    private $h2pushCache = [];
    /** @var array Manifest content */
    private $manifest;

    /**
     * TwigExtension constructor.
     *
     * @param \Berlioz\Core\Core $core
     */
    public function __construct(Core $core)
    {
        $this->setCore($core);

        // Get cache from cookies
        if (isset($_COOKIE[self::H2PUSH_CACHE_COOKIE]) && is_array($_COOKIE[self::H2PUSH_CACHE_COOKIE])) {
            $this->h2pushCache = array_keys($_COOKIE[self::H2PUSH_CACHE_COOKIE]);
        }
    }

    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return \Twig_Filter[]
     */
    public function getFilters()
    {
        $filters = [];
        $filters[] = new \Twig_Filter('date_format', [$this, 'filterDateFormat']);
        $filters[] = new \Twig_Filter('truncate', 'b_str_truncate');
        $filters[] = new \Twig_Filter('nl2p', 'b_nl2p', ['is_safe' => ['html']]);
        $filters[] = new \Twig_Filter('human_file_size', 'b_human_file_size');
        $filters[] = new \Twig_Filter('json_decode', 'json_decode');
        $filters[] = new \Twig_Filter('spaceless', [$this, 'filterSpaceless']);
        $filters[] = new \Twig_Filter('basename', 'basename');

        return $filters;
    }

    /**
     * Filter to format date.
     *
     * @param \DateTime|int $datetime DateTime object or timestamp
     * @param string        $pattern  Pattern of date result waiting
     * @param string        $locale   Locale for pattern translation
     *
     * @return string
     * @throws \RuntimeException if application not accessible
     */
    public function filterDateFormat($datetime, string $pattern = 'dd/MM/yyyy', string $locale = null): string
    {
        $fmt = new \IntlDateFormatter($locale ?? $this->getCore()->getLocale(), \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $fmt->setPattern((string) $pattern);

        if ($datetime instanceof \DateTimeInterface) {
            return $fmt->format($datetime);
        }

        if (is_numeric($datetime)) {
            return $fmt->format((int) $datetime);
        }

        if(is_string($datetime)) {
            $result = $fmt->format(strtotime($datetime));

            if($result) {
                return $result;
            }
        }

        return "";
    }

    /**
     * Spaceless filter.
     *
     * @param string $str
     *
     * @return string
     */
    public function filterSpaceless(string $str)
    {
        return trim(preg_replace('/>\s+</', '><', $str));
    }

    /**
     * Returns a list of functions to add to the existing list.
     *
     * @return \Twig_Function[]
     */
    public function getFunctions()
    {
        $functions = [];
        $functions[] = new \Twig_Function('path', [$this, 'functionPath']);
        $functions[] = new \Twig_Function('asset', [$this, 'functionAsset']);
        $functions[] = new \Twig_Function('entrypoints', [$this, 'functionEntryPoints'], ['is_safe' => ['html']]);
        $functions[] = new \Twig_Function('entrypoints_list', [$this, 'functionEntryPointsList']);
        $functions[] = new \Twig_Function('preload', [$this, 'functionPreload']);

        return $functions;
    }

    /**
     * Function path to generate path.
     *
     * @param string $name
     * @param array  $parameters
     *
     * @return string
     * @throws \Berlioz\Package\Twig\Exception\PathException
     */
    public function functionPath(string $name, array $parameters = []): string
    {
        try {
            $path = $this->getCore()->getServiceContainer()->get('router')->generate($name, $parameters);

            if ($path === false) {
                throw new PathException(sprintf('Route named "%s" does not found', $name));
            }

            return $path;
        } catch (\Throwable $e) {
            throw new PathException('Routing treatment error', -1, null, $e);
        }
    }

    /**
     * Function asset to get generate asset path.
     *
     * @param string        $key
     * @param Manifest|null $manifest
     *
     * @return string
     * @throws \Berlioz\Package\Twig\Exception\AssetException
     */
    public function functionAsset(string $key, ?Manifest $manifest = null): string
    {
        try {
            if (is_null($manifest)) {
                /** @var \Berlioz\Core\Asset\Assets $assets */
                $assets = $this->getCore()->getServiceContainer()->get(Assets::class);
                $manifest = $assets->getManifest();
            }

            if (!$manifest->has($key)) {
                throw new AssetException(sprintf('Asset "%s" not found in manifest file', $key));
            }

            return $manifest->get($key);
        } catch (AssetException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new AssetException('Manifest treatment error', -1, null, $e);
        }
    }

    /**
     * Function to get entry points in html.
     *
     * @param string           $entry
     * @param string|null      $type
     * @param array            $options
     * @param EntryPoints|null $entryPointsObj
     *
     * @return string
     * @throws \Berlioz\Package\Twig\Exception\AssetException
     */
    public function functionEntryPoints(string $entry, ?string $type = null, array $options = [], ?EntryPoints $entryPointsObj = null): string
    {
        try {
            $output = '';

            if (is_null($entryPointsObj)) {
                /** @var \Berlioz\Core\Asset\Assets $assets */
                $assets = $this->getCore()->getServiceContainer()->get(Assets::class);
                $entryPointsObj = $assets->getEntryPoints();
            }

            $entryPoints = $entryPointsObj->get($entry, $type);

            if (!is_null($type)) {
                $entryPoints = [$type => $entryPoints];
            }

            foreach ($entryPoints as $type => $entryPointsByType) {
                foreach ($entryPointsByType as $entryPoint) {
                    $entryPoint = strip_tags($entryPoint);

                    // Preload option
                    $preloadOptions = [];
                    if (isset($options['preload'])) {
                        if (is_array($options['preload'])) {
                            $preloadOptions = $options['preload'];
                        }
                    }

                    switch ($type) {
                        case 'js':
                            if (isset($options['preload'])) {
                                $entryPoint = $this->functionPreload($entryPoint, array_merge(['as' => 'script'], $preloadOptions));
                            }

                            // Defer/Async?
                            $deferOrAsync = '';
                            $deferOrAsync .= ($options['defer'] ?? false) === true ? ' defer' : '';
                            $deferOrAsync .= ($options['async'] ?? false) === true ? ' async' : '';

                            $output .= sprintf('<script src="%s"%s></script>', strip_tags($entryPoint), $deferOrAsync) . PHP_EOL;
                            break;
                        case 'css':
                            if (isset($options['preload'])) {
                                $entryPoint = $this->functionPreload($entryPoint, array_merge(['as' => 'style'], $preloadOptions));
                            }

                            $output .= sprintf('<link rel="stylesheet" href="%s">', strip_tags($entryPoint)) . PHP_EOL;
                            break;
                    }
                }
            }

            return $output;
        } catch (\Exception $e) {
            throw new AssetException('Entry points treatment error', -1, null, $e);
        }
    }

    /**
     * Function to get entry points list.
     *
     * @param string      $entry
     * @param string|null $type
     *
     * @return array
     * @throws \Berlioz\Package\Twig\Exception\AssetException
     */
    public function functionEntryPointsList(string $entry, ?string $type = null): array
    {
        try {
            /** @var \Berlioz\Core\Asset\Assets $assets */
            $assets = $this->getCore()->getServiceContainer()->get(Assets::class);

            return $assets->getEntryPoints()->get($entry, $type);
        } catch (\Exception $e) {
            throw new AssetException('Entry points treatment error', -1, null, $e);
        }
    }

    /**
     * Function preload to pre loading of request for HTTP 2 protocol.
     *
     * @param string $link
     * @param array  $parameters
     *
     * @return string Link
     */
    public function functionPreload(string $link, array $parameters = []): string
    {
        $push = !(!empty($parameters['nopush']) && $parameters['nopush'] == true);

        if (!$push || !in_array(md5($link), $this->h2pushCache)) {
            $header = sprintf('Link: <%s>; rel=preload', $link);
            // as
            if (!empty($parameters['as'])) {
                $header = sprintf('%s; as=%s', $header, $parameters['as']);
            }
            // type
            if (!empty($parameters['type'])) {
                $header = sprintf('%s; type=%s', $header, $parameters['as']);
            }
            // crossorigin
            if (!empty($parameters['crossorigin']) && $parameters['crossorigin'] == true) {
                $header .= '; crossorigin';
            }
            // nopush
            if (!$push) {
                $header .= '; nopush';
            }

            header($header, false);

            // Cache
            if ($push) {
                $this->h2pushCache[] = md5($link);
                setcookie(sprintf('%s[%s]', self::H2PUSH_CACHE_COOKIE, md5($link)), '1', 0, '/', '', false, true);
            }
        }

        return $link;
    }

    /**
     * Returns a list of tests to add to the existing list.
     *
     * @return \Twig_Test[]
     */
    public function getTests()
    {
        $tests = [];
        $tests[] = new \Twig_Test('instance of', [$this, 'testInstanceOf']);

        return $tests;
    }

    /**
     * Test instance of.
     *
     * @param mixed  $object     The tested object
     * @param string $class_name The class name
     *
     * @return bool
     */
    public function testInstanceOf($object, string $class_name): bool
    {
        return is_a($object, $class_name, true);
    }
}
