<?php

namespace Xwpd\ThinkApiDoc\Commands;

use ReflectionClass;
use think\Container;
use think\facade\App;
use think\facade\Config;
use think\Route;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\View;
use Mpociot\Reflection\DocBlock;
use Xwpd\ThinkApiDoc\Collection;
use Xwpd\ThinkApiDoc\Documentarian;
use Xwpd\ThinkApiDoc\Postman\CollectionWriter;
use Xwpd\ThinkApiDoc\Generators\ThinkphpGenerator;
use Xwpd\ThinkApiDoc\Generators\AbstractGenerator;
use think\facade\Route as RouteFacade;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'api:generate
                            {--output=public/docs : The output path for the generated documentation}
                            {--routeDomain= : The route domain (or domains) to use for generation}
                            {--routePrefix= : The route prefix (or prefixes) to use for generation}
                            {--routes=* : The route names to use for generation}
                            {--middleware= : The middleware to use for generation}
                            {--noResponseCalls : Disable API response calls}
                            {--noPostmanCollection : Disable Postman collection creation}
                            {--useMiddlewares : Use all configured route middlewares}
                            {--authProvider=users : The authentication provider to use for API response calls}
                            {--authGuard=web : The authentication guard to use for API response calls}
                            {--actAsUserId= : The user ID to use for API response calls}
                            {--router=thinkphp : The router to be used (thinkphp)}
                            {--force : Force rewriting of existing routes}
                            {--bindings= : Route Model Bindings}
                            {--header=* : Custom HTTP headers to add to the example requests. Separate the header name and value with ":"}
    ';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Generate your API documentation from existing thinkphp routes.';

    protected function configure()
    {
        $this->setName('api-doc:generate')
            ->addOption('routePrefix', null, Option::VALUE_REQUIRED, '路由前缀')
            ->addOption('routes', null, Option::VALUE_REQUIRED, '路由名', '*')
            ->addOption('force', null, Option::VALUE_REQUIRED, '强制更新', true)
            ->addOption('actAsUserId', null, Option::VALUE_REQUIRED, '模拟登陆的id')
            ->addOption('noResponseCalls', null, Option::VALUE_REQUIRED, '无返回', true)
            ->addOption('bindings', null, Option::VALUE_REQUIRED, '无返回')
            ->addOption('router', null, Option::VALUE_REQUIRED, '路由类型 (thinkphp)', 'thinkphp')
            ->addOption('noPostmanCollection', null, Option::VALUE_REQUIRED, '生成Postman文件', false)
            ->addOption('output', null, Option::VALUE_REQUIRED, '文档的保存路径', 'public/docs')
            ->addOption('header', null, Option::VALUE_REQUIRED, 'HTTP headers to add to the example requests, Separate the header name and value with ":"', '')
            ->setDescription('生成api文档');
    }

    private function option($name)
    {
        if ($name == 'header') {
            return [];
        }
        return $this->input->getOption($name);
    }


    protected function info($text)
    {
        $this->output->info($text);
    }

    protected function warn($text)
    {
        $this->output->warning($text);
    }

    protected function error($text)
    {
        $this->output->error($text);
    }

    /**
     * 设置模板引擎
     */
    protected function setViewEn()
    {
        $resources_dir = __DIR__ . '/../../resources' . DIRECTORY_SEPARATOR;
        $view_path = $resources_dir . 'views/';
        Config::set('template.type', 'Blade');
        Config::set('template.auto_rule', '1');
        Config::set('template.view_path', $view_path);
        Config::set('template.view_suffix', 'blade.php');
        Config::set('template.view_depr', DIRECTORY_SEPARATOR);
        Config::set('template.tpl_begin', '{{');
        Config::set('template.tpl_end', '}}');
        Config::set('template.tpl_raw_begin', '{!!');
        Config::set('template.tpl_raw_end', '!!}');
        Config::set('template.taglib_begin', '{');
        Config::set('template.taglib_end', '}');


    }

    static public function view($template = '', $vars = [])
    {
        $view = App::make('view', [], true);
        /**
         * @var View $view
         */
        return $view->assign($vars)->fetch($template, $vars);
    }

    public static function arr_wrap($value)
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    static public function str_is($pattern, $value)
    {
        $patterns = static::arr_wrap($pattern);

        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            // If the given value is an exact match we can of course return true right
            // from the beginning. Otherwise, we will translate asterisks and do an
            // actual pattern match against the two strings to see if they match.
            if ($pattern == $value) {
                return true;
            }

            $pattern = preg_quote($pattern, '#');

            // Asterisks are translated into zero-or-more regular expression wildcards
            // to make it convenient to check if the strings starts with the given
            // pattern such as "library/*", making any string check convenient.
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '\z#u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function execute(Input $input, Output $output)
    {
        $this->setViewEn();
        $this->input = $input;

        $output->writeln("生成中...");

        if ($this->option('router') === 'thinkphp') {
            $generator = new ThinkphpGenerator();
        } else {
            $generator = new ThinkphpGenerator();
        }

        $allowedRoutes = explode(',', $this->option('routes') ?: '*');
        $routeDomain = null;
        $routePrefix = $this->option('routePrefix');
        $middleware = null;

        $this->setUserToBeImpersonated($this->option('actAsUserId'));

        if ($routePrefix === null && $routeDomain === null && !count($allowedRoutes) && $middleware === null) {
            $this->error('You must provide either a route prefix, a route domain, a route or a middleware to generate the documentation.');

            return false;
        }

        //$generator->prepareMiddleware($this->option('useMiddlewares'));

        $routePrefixes = explode(',', $routePrefix ?: '*');
        $routeDomains = explode(',', $routeDomain ?: '*');

        $parsedRoutes = [];

        foreach ($routeDomains as $routeDomain) {
            foreach ($routePrefixes as $routePrefix) {
                $parsedRoutes += $this->processRoutes($generator, $allowedRoutes, $routeDomain, $routePrefix, $middleware);
            }
        }

        $parsedRoutes =Collection::make($parsedRoutes)->groupBy('resource')->sort(function ($a, $b) {
            return strcmp($a->first()['resource'], $b->first()['resource']);
        });

        $this->writeMarkdown($parsedRoutes);
    }

    /**
     * @param Collection $parsedRoutes
     * @return void
     */
    private function writeMarkdown($parsedRoutes)
    {
        $outputPath = Config::get('root_path') . $this->option('output');
        $targetFile = $outputPath . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'index.md';
        $compareFile = $outputPath . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . '.compare.md';
        $prependFile = $outputPath . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'prepend.md';
        $appendFile = $outputPath . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'append.md';
        $viewPath = '';//dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views/';
        $infoText = static::view('partials' . DIRECTORY_SEPARATOR . 'info', [
            'outputPath' => ltrim($outputPath, 'public/'),
            'showPostmanCollectionButton' => !$this->option('noPostmanCollection')
        ]);

        $parsedRouteOutput = $parsedRoutes->map(function ($routeGroup) use ($viewPath) {
            return $routeGroup->map(function ($route) use ($viewPath) {
                $route['output'] = static::view('partials' . DIRECTORY_SEPARATOR . 'route', ['parsedRoute' => $route]);;
                return $route;
            });
        });

        $frontmatter = static::view('partials' . DIRECTORY_SEPARATOR . 'frontmatter');
        /*
         * In case the target file already exists, we should check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            if (preg_match('/---(.*)---\\s<!-- START_INFO -->/is', $generatedDocumentation, $generatedFrontmatter)) {
                $frontmatter = trim($generatedFrontmatter[1], "\n");
            }

            $parsedRouteOutput->transform(function ($routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function ($route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $generatedDocumentation, $routeMatch)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $compareDocumentation, $compareMatch) && $compareMatch[1] !== $routeMatch[1]);
                        if ($routeDocumentationChanged === false || $this->option('force')) {
                            if ($routeDocumentationChanged) {
                                $this->warn('Discarded manual changes for route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            }
                        } else {
                            $this->warn('Skipping modified route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            $route['modified_output'] = $routeMatch[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $prependFileContents = file_exists($prependFile)
            ? file_get_contents($prependFile) . "\n" : '';
        $appendFileContents = file_exists($appendFile)
            ? "\n" . file_get_contents($appendFile) : '';

        $documentarian = new Documentarian();

        $markdown = static::view(DIRECTORY_SEPARATOR . 'documentarian',
            [
                'writeCompareFile' => false,
                'frontmatter' => $frontmatter,
                'infoText' => $infoText,
                'prependMd' => $prependFileContents,
                'appendMd' => $appendFileContents,
                'outputPath' => $this->option('output'),
                'showPostmanCollectionButton' => !$this->option('noPostmanCollection'),
                'parsedRoutes' => $parsedRouteOutput
            ]);


        if (!is_dir($outputPath)) {
            $documentarian->create($outputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);

        // Write comparable markdown file
        $compareMarkdown = static::view(DIRECTORY_SEPARATOR . 'documentarian',
            [
                'writeCompareFile' => true,
                'frontmatter' => $frontmatter,
                'infoText' => $infoText,
                'prependMd' => $prependFileContents,
                'appendMd' => $appendFileContents,
                'outputPath' => $this->option('output'),
                'showPostmanCollectionButton' => !$this->option('noPostmanCollection'),
                'parsedRoutes' => $parsedRouteOutput
            ]);

        file_put_contents($compareFile, $compareMarkdown);

        $this->info('Wrote index.md to: ' . $outputPath);

        $this->info('Generating API HTML code');

        $documentarian->generate($outputPath);

        $this->info('Wrote HTML documentation to: ' . $outputPath . '/index.html');

        if ($this->option('noPostmanCollection') !== true) {
            $this->info('Generating Postman collection');

            file_put_contents($outputPath . DIRECTORY_SEPARATOR . 'collection.json', $this->generatePostmanCollection($parsedRoutes));
        }
    }

    /**
     * @return array
     */
    private function getBindings()
    {
        $bindings = $this->option('bindings');
        if (empty($bindings)) {
            return [];
        }

        $bindings = explode('|', $bindings);
        $resultBindings = [];
        foreach ($bindings as $binding) {
            list($name, $id) = explode(',', $binding);
            $resultBindings[$name] = $id;
        }

        return $resultBindings;
    }

    /**
     * @param $actAs
     */
    private function setUserToBeImpersonated($actAs)
    {
        if (!empty($actAs)) {
            if (version_compare($this->thinkphp->version(), '5.2.0', '<')) {
                $userModel = config('auth.model');
                $user = $userModel::find($actAs);
                $this->thinkphp['auth']->setUser($user);
            } else {
                $provider = $this->option('authProvider');
                $userModel = config("auth.providers.$provider.model");
                $user = $userModel::find($actAs);
                $this->thinkphp['auth']->guard($this->option('authGuard'))->setUser($user);
            }
        }
    }

    /**
     * @return mixed
     */
    private function getRoutes()
    {
        if ($this->option('router') === 'thinkphp') {
            return $this->getRouteList();
        } else {
            return $this->getRouteList();
        }
    }

    protected function getRouteList()
    {
        Container::get('route')->setTestMode(true);
        // 路由检测
        $path = Container::get('app')->getRoutePath();

        $files = is_dir($path) ? scandir($path) : [];

        foreach ($files as $file) {
            if (strpos($file, '.php')) {
                $filename = $path . DIRECTORY_SEPARATOR . $file;
                // 导入路由配置
                $rules = include $filename;

                if (is_array($rules)) {
                    Container::get('route')->import($rules);
                }
            }
        }

        if (Container::get('config')->get('route_annotation')) {
            $suffix = Container::get('config')->get('controller_suffix') || Container::get('config')->get('class_suffix');

            include Container::get('build')->buildRoute($suffix);
        }

        $routeList = Container::get('route')->getRuleList();
        $rows = [];

        foreach ($routeList as $domain => $items) {
            foreach ($items as $item) {
                //$item['route'] = $item['route'] instanceof \Closure? '<Closure>' : $item['route'];
                $item['rule'] = str_replace('<', ":", $item['rule']);
                $item['rule'] = str_replace('>', "", $item['rule']);
                $rows[] = $item;
            }
        }
        return $rows;
    }

    /**
     * @param AbstractGenerator $generator
     * @param $allowedRoutes
     * @param $routeDomain
     * @param $routePrefix
     * @return array
     */
    private function processRoutes(AbstractGenerator $generator, array $allowedRoutes, $routeDomain, $routePrefix, $middleware)
    {
        $withResponse = $this->option('noResponseCalls') == false;
        $routes = $this->getRoutes();
        $bindings = $this->getBindings();
        $parsedRoutes = [];
        foreach ($routes as $route) {
            /** @var Route $route */
            if (in_array($generator->getName($route), $allowedRoutes)
                || (static::str_is($routeDomain, $generator->getDomain($route))
                    && static::str_is($routePrefix, $generator->getUri($route)))
            ) {
                if ($this->isValidRoute($generator, $route) && $this->isRouteVisibleForDocumentation($generator->getAction($route)['uses'])) {
                    $parsedRoutes[] = $generator->processRoute($route, $bindings, $this->option('header'), $withResponse && in_array('GET', $generator->getMethods($route)));
                    $this->info('Processed route: [' . implode(',', $generator->getMethods($route)) . '] ' . $generator->getUri($route));
                } else {
                    $this->warn('Skipping route: [' . implode(',', $generator->getMethods($route)) . '] ' . $generator->getUri($route));
                }
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param AbstractGenerator $generator
     * @param $route
     * @return bool
     */
    private function isValidRoute(AbstractGenerator $generator, $route)
    {
        return !is_callable($generator->getAction($route)['uses']) && !is_null($generator->getAction($route)['uses']);
    }

    /**
     * @param $route
     * @return bool
     * @throws \ReflectionException
     */
    private function isRouteVisibleForDocumentation($route)
    {
        list($class, $method) = explode('@', $route);
        if (!method_exists($class, $method)) {
            return false;
        }
        $reflection = new ReflectionClass($class);
        $comment = $reflection->getMethod($method)->getDocComment();
        if ($comment) {
            $phpdoc = new DocBlock($comment);

            return Collection::make($phpdoc->getTags())
                ->filter(function ($tag) use ($route) {
                    return $tag->getName() === 'hideFromAPIDocumentation';
                })
                ->isEmpty();
        }

        return true;
    }

    /**
     * Generate Postman collection JSON file.
     * @param Collection $routes
     * @return string
     */
    private function generatePostmanCollection(Collection $routes)
    {
        $writer = new CollectionWriter($routes);

        return $writer->getCollection();
    }
}
