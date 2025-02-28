<?php

declare(strict_types=1);

namespace MezzioTest\LaminasView;

use Interop\Container\ContainerInterface;
use Laminas\View\HelperPluginManager;
use Laminas\View\Model\ModelInterface;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\AggregateResolver;
use Laminas\View\Resolver\TemplateMapResolver;
use Mezzio\Helper;
use Mezzio\LaminasView\Exception\InvalidContainerException;
use Mezzio\LaminasView\LaminasViewRenderer;
use Mezzio\LaminasView\LaminasViewRendererFactory;
use Mezzio\LaminasView\NamespacedPathStackResolver;
use Mezzio\LaminasView\ServerUrlHelper;
use Mezzio\LaminasView\UrlHelper;
use Mezzio\Template\TemplatePath;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\ProphecyInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionProperty;

use function assert;
use function sprintf;
use function var_export;

use const DIRECTORY_SEPARATOR;

class LaminasViewRendererFactoryTest extends TestCase
{
    use ProphecyTrait;

    /** @var ContainerInterface&ProphecyInterface */
    private $container;

    protected function setUp(): void
    {
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    /**
     * @psalm-return array<array-key, string|string[]>
     */
    public function getConfigurationPaths(): array
    {
        return [
            'foo' => __DIR__ . '/TestAsset/bar',
            1     => __DIR__ . '/TestAsset/one',
            'bar' => [
                __DIR__ . '/TestAsset/baz',
                __DIR__ . '/TestAsset/bat',
            ],
            0     => [
                __DIR__ . '/TestAsset/two',
                __DIR__ . '/TestAsset/three',
            ],
        ];
    }

    public function assertPathsHasNamespace(
        ?string $namespace,
        array $paths,
        ?string $message = null
    ): void {
        $message = $message ?: sprintf('Paths do not contain namespace %s', $namespace ?: 'null');

        $found = false;
        foreach ($paths as $path) {
            $this->assertInstanceOf(TemplatePath::class, $path, 'Non-TemplatePath found in paths list');
            if ($path->getNamespace() === $namespace) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, $message);
    }

    public function assertPathNamespaceCount(
        int $expected,
        ?string $namespace,
        array $paths,
        ?string $message = null
    ): void {
        $message = $message ?: sprintf('Did not find %d paths with namespace %s', $expected, $namespace ?: 'null');

        $count = 0;
        foreach ($paths as $path) {
            $this->assertInstanceOf(TemplatePath::class, $path, 'Non-TemplatePath found in paths list');
            if ($path->getNamespace() === $namespace) {
                $count += 1;
            }
        }
        $this->assertSame($expected, $count, $message);
    }

    /**
     * @param mixed $expected
     */
    public function assertPathNamespaceContains(
        $expected,
        ?string $namespace,
        array $paths,
        ?string $message = null
    ): void {
        $message = $message ?: sprintf('Did not find path %s in namespace %s', $expected, $namespace ?: null);

        $found = [];
        foreach ($paths as $path) {
            $this->assertInstanceOf(TemplatePath::class, $path, 'Non-TemplatePath found in paths list');
            if ($path->getNamespace() === $namespace) {
                $found[] = $path->getPath();
            }
        }
        $this->assertContains($expected, $found, $message);
    }

    public function fetchPhpRenderer(LaminasViewRenderer $view): PhpRenderer
    {
        $r = new ReflectionProperty($view, 'renderer');
        $r->setAccessible(true);
        $renderer = $r->getValue($view);
        assert($renderer instanceof PhpRenderer);

        return $renderer;
    }

    /**
     * @param mixed $service Service to return from container
     */
    public function injectContainerService(string $name, $service): void
    {
        $this->container->has($name)->willReturn(true);
        $this->container->get($name)->willReturn(
            $service instanceof ObjectProphecy ? $service->reveal() : $service
        );
    }

    public function injectBaseHelpers(): void
    {
        $this->injectContainerService(
            Helper\UrlHelper::class,
            $this->prophesize(Helper\UrlHelper::class)
        );
        $this->injectContainerService(
            Helper\ServerUrlHelper::class,
            $this->prophesize(Helper\ServerUrlHelper::class)
        );
    }

    public function testCallingFactoryWithNoConfigReturnsLaminasViewInstance(): LaminasViewRenderer
    {
        $this->container->has('config')->willReturn(false);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());
        $this->assertInstanceOf(LaminasViewRenderer::class, $view);
        return $view;
    }

    /**
     * @depends testCallingFactoryWithNoConfigReturnsLaminasViewInstance
     */
    public function testUnconfiguredLaminasViewInstanceContainsNoPaths(LaminasViewRenderer $view): void
    {
        $paths = $view->getPaths();
        $this->assertIsArray($paths);
        $this->assertEmpty($paths);
    }

    public function testConfiguresLayout(): void
    {
        $config = [
            'templates' => [
                'layout' => 'layout/layout',
            ],
        ];
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $r = new ReflectionProperty($view, 'layout');
        $r->setAccessible(true);
        $layout = $r->getValue($view);
        $this->assertInstanceOf(ModelInterface::class, $layout);
        $this->assertSame($config['templates']['layout'], $layout->getTemplate());
    }

    public function testConfiguresPaths(): void
    {
        $config = [
            'templates' => [
                'paths' => $this->getConfigurationPaths(),
            ],
        ];
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $paths = $view->getPaths();
        $this->assertPathsHasNamespace('foo', $paths);
        $this->assertPathsHasNamespace('bar', $paths);
        $this->assertPathsHasNamespace(null, $paths);

        $this->assertPathNamespaceCount(1, 'foo', $paths);
        $this->assertPathNamespaceCount(2, 'bar', $paths);
        $this->assertPathNamespaceCount(3, null, $paths);

        $dirSlash = DIRECTORY_SEPARATOR;

        $this->assertPathNamespaceContains(
            __DIR__ . '/TestAsset/bar' . $dirSlash,
            'foo',
            $paths,
            var_export($paths, true)
        );
        $this->assertPathNamespaceContains(__DIR__ . '/TestAsset/baz' . $dirSlash, 'bar', $paths);
        $this->assertPathNamespaceContains(__DIR__ . '/TestAsset/bat' . $dirSlash, 'bar', $paths);
        $this->assertPathNamespaceContains(__DIR__ . '/TestAsset/one' . $dirSlash, null, $paths);
        $this->assertPathNamespaceContains(__DIR__ . '/TestAsset/two' . $dirSlash, null, $paths);
        $this->assertPathNamespaceContains(__DIR__ . '/TestAsset/three' . $dirSlash, null, $paths);
    }

    public function testConfiguresTemplateMap(): void
    {
        $config = [
            'templates' => [
                'map' => [
                    'foo' => 'bar',
                    'bar' => 'baz',
                ],
            ],
        ];
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $r = new ReflectionProperty($view, 'renderer');
        $r->setAccessible(true);
        $renderer  = $r->getValue($view);
        $aggregate = $renderer->resolver();
        $this->assertInstanceOf(AggregateResolver::class, $aggregate);
        $resolver = false;
        foreach ($aggregate as $resolver) {
            if ($resolver instanceof TemplateMapResolver) {
                break;
            }
        }
        $this->assertInstanceOf(TemplateMapResolver::class, $resolver, 'Expected TemplateMapResolver not found!');
        $this->assertTrue($resolver->has('foo'));
        $this->assertEquals('bar', $resolver->get('foo'));
        $this->assertTrue($resolver->has('bar'));
        $this->assertEquals('baz', $resolver->get('bar'));
    }

    public function testConfiguresCustomDefaultSuffix(): void
    {
        $config = [
            'templates' => [
                'extension' => 'php',
            ],
        ];

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);

        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $r = new ReflectionProperty($view, 'resolver');
        $r->setAccessible(true);
        $resolver = $r->getValue($view);

        $this->assertInstanceOf(
            NamespacedPathStackResolver::class,
            $resolver,
            'Expected NamespacedPathStackResolver not found!'
        );
        $this->assertEquals('php', $resolver->getDefaultSuffix());
    }

    public function testConfiguresDeprecatedDefaultSuffix(): void
    {
        $config = [
            'templates' => [
                'default_suffix' => 'php',
            ],
        ];

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);

        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $r = new ReflectionProperty($view, 'resolver');
        $r->setAccessible(true);
        $resolver = $r->getValue($view);

        $this->assertInstanceOf(
            NamespacedPathStackResolver::class,
            $resolver,
            'Expected NamespacedPathStackResolver not found!'
        );
        $this->assertEquals('php', $resolver->getDefaultSuffix());
    }

    public function testInjectsCustomHelpersIntoHelperManager(): void
    {
        $this->container->has('config')->willReturn(false);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());
        $this->assertInstanceOf(LaminasViewRenderer::class, $view);

        $renderer = $this->fetchPhpRenderer($view);
        $helpers  = $renderer->getHelperPluginManager();
        $this->assertInstanceOf(HelperPluginManager::class, $helpers);
        $this->assertTrue($helpers->has('url'));
        $this->assertTrue($helpers->has('serverurl'));
        $this->assertInstanceOf(UrlHelper::class, $helpers->get('url'));
        $this->assertInstanceOf(ServerUrlHelper::class, $helpers->get('serverurl'));
    }

    public function testWillUseHelperManagerFromContainer(): HelperPluginManager
    {
        $this->container->has('config')->willReturn(false);
        $this->container->has(PhpRenderer::class)->willReturn(false);
        $this->container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $this->injectBaseHelpers();

        $helpers = new HelperPluginManager($this->container->reveal());
        $this->container->has(HelperPluginManager::class)->willReturn(true);
        $this->container->get(HelperPluginManager::class)->willReturn($helpers);
        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());
        $this->assertInstanceOf(LaminasViewRenderer::class, $view);

        $renderer = $this->fetchPhpRenderer($view);
        $this->assertSame($helpers, $renderer->getHelperPluginManager());
        return $helpers;
    }

    /**
     * @depends testWillUseHelperManagerFromContainer
     */
    public function testInjectsCustomHelpersIntoHelperManagerFromContainer(HelperPluginManager $helpers): void
    {
        $this->assertTrue($helpers->has('url'));
        $this->assertTrue($helpers->has('serverurl'));
        $this->assertInstanceOf(UrlHelper::class, $helpers->get('url'));
        $this->assertInstanceOf(ServerUrlHelper::class, $helpers->get('serverurl'));
    }

    public function testWillUseRendererFromContainer(): void
    {
        $engine = new PhpRenderer();
        $this->container->has('config')->willReturn(false);
        $this->container->has(HelperPluginManager::class)->willReturn(false);
        $this->container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);
        $this->injectContainerService(PhpRenderer::class, $engine);

        $factory = new LaminasViewRendererFactory();
        $view    = $factory($this->container->reveal());

        $composed = $this->fetchPhpRenderer($view);
        $this->assertSame($engine, $composed);
    }

    public function testWillRaiseExceptionIfContainerDoesNotImplementInteropContainerInterface(): void
    {
        $container = $this->prophesize(PsrContainerInterface::class);
        $container->has('config')->willReturn(false);
        $container->get('config')->shouldNotBeCalled();
        $container->has(PhpRenderer::class)->willReturn(false);
        $container->has(\Zend\View\Renderer\PhpRenderer::class)->willReturn(false);
        $container->get(PhpRenderer::class)->shouldNotBeCalled();
        $container->get(\Zend\View\Renderer\PhpRenderer::class)->shouldNotBeCalled();
        $container->has(HelperPluginManager::class)->willReturn(false);
        $container->has(\Zend\View\HelperPluginManager::class)->willReturn(false);

        $factory = new LaminasViewRendererFactory();

        $this->expectException(InvalidContainerException::class);
        $factory($container->reveal());
    }
}
