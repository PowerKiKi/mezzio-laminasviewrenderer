<?php

/**
 * @see       https://github.com/mezzio/mezzio-laminasviewrenderer for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-laminasviewrenderer/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-laminasviewrenderer/blob/master/LICENSE.md New BSD License
 */

namespace Mezzio\LaminasView;

use Laminas\View\Helper\AbstractHelper;
use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouteResultObserverInterface;
use Mezzio\Router\RouterInterface;
use Mezzio\Template\Exception\RenderingException;

class UrlHelper extends AbstractHelper implements RouteResultObserverInterface
{
    /**
     * @var RouteResult
     */
    private $result;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @param RouterInterface $router
     */
    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    /**
     * @param string $route
     * @param array $params
     * @return string
     * @throws RenderingException if no route provided, and no result match
     *     present.
     * @throws RenderingException if no route provided, and result match is a
     *     routing failure.
     * @throws RuntimeException if router cannot generate URI for given route.
     */
    public function __invoke($route = null, $params = [])
    {
        if ($route === null && $this->result === null) {
            throw new RenderingException(
                'Attempting to use matched result when none was injected; aborting'
            );
        }

        if ($route === null) {
            return $this->generateUriFromResult($params);
        }

        if ($this->result) {
            $params = $this->mergeParams($route, $params);
        }

        return $this->router->generateUri($route, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function update(RouteResult $result)
    {
        $this->result = $result;
    }

    /**
     * @param RouteResult $result
     */
    public function setRouteResult(RouteResult $result)
    {
        $this->result = $result;
    }

    /**
     * @param array $params
     * @return string
     * @throws RenderingException if current result is a routing failure.
     */
    private function generateUriFromResult(array $params)
    {
        if ($this->result->isFailure()) {
            throw new RenderingException(
                'Attempting to use matched result when routing failed; aborting'
            );
        }

        $name   = $this->result->getMatchedRouteName();
        $params = array_merge($this->result->getMatchedParams(), $params);
        return $this->router->generateUri($name, $params);
    }

    /**
     * Merge route result params and provided parameters.
     *
     * If $params is not an array, returns them verbatim.
     *
     * If the route result represents a routing failure, returns the params
     * verbatim.
     *
     * If the route result does not represent the same route name requested,
     * returns the params verbatim.
     *
     * Otherwise, merges the route result params with those provided at
     * invocation, with the latter having precedence.
     *
     * @param string $route Route name.
     * @param array $params Parameters provided at invocation.
     * @return array
     */
    private function mergeParams($route, $params)
    {
        if (! is_array($params)) {
            return $params;
        }

        if ($this->result->isFailure()) {
            return $params;
        }

        if ($this->result->getMatchedRouteName() !== $route) {
            return $params;
        }

        return array_merge($this->result->getMatchedParams(), $params);
    }
}
